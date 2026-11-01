<?php

namespace App\Database\Migrations;

use App\Libraries\ActivityLog;
use CodeIgniter\Database\Migration;

/**
 * Tidies the locale columns of staff_master and client_master:
 * - avatar_path moves to auth_master, as staff and clients share the same avatar mechanism
 * - preferred_language becomes CHAR(5), a BCP 47 tag such as en-US or th; a longer value becomes NULL
 * - timezone is dropped: times are stored in UTC and only shown in the viewer's local timezone
 * Each row whose values move or go is logged to log_activity as a change by the system.
 * Depends on auth_master, staff_master, client_master and log_activity.
 */
class MoveAvatarAndTrimProfileLocale extends Migration
{
    private const PROFILE_TABLES = ['staff_master', 'client_master'];

    /** Length of preferred_language before and after */
    private const LANGUAGE_LENGTH_BEFORE = 16;
    private const LANGUAGE_LENGTH        = 5;

    public function up()
    {
        $this->forge->addColumn('auth_master', [
            'avatar_path' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'after' => 'full_name'],
        ]);

        $log = new ActivityLog();
        $this->db->transException(true)->transStart();
        foreach (self::PROFILE_TABLES as $table) {
            $rows = $this->db->table($table)
                ->select('id, auth_id, preferred_language, timezone, avatar_path')
                ->get()
                ->getResultArray();
            foreach ($rows as $row) {
                if ($row['avatar_path'] !== null && $row['avatar_path'] !== '') {
                    $account = $this->db->table('auth_master')->select('id, avatar_path')->where('id', $row['auth_id'])->get()->getRowArray();
                    $this->db->table('auth_master')->where('id', $row['auth_id'])->update(['avatar_path' => $row['avatar_path']]);
                    $log->updated(null, 'Moved avatar from ' . $table, 'auth_master', (int) $row['auth_id'], $account, ['avatar_path' => $row['avatar_path']]);
                }

                $language = $row['preferred_language'];
                $changes  = ['avatar_path' => null, 'timezone' => null];
                if ($language !== null && strlen($language) > self::LANGUAGE_LENGTH) {
                    $changes['preferred_language'] = null;
                    $this->db->table($table)->where('id', $row['id'])->update(['preferred_language' => null]);
                }
                $log->updated(null, 'Moved avatar to auth_master and dropped timezone', $table, (int) $row['id'], $row, $changes);
            }
        }
        $this->db->transComplete();
        $this->db->transException(false);

        $this->rebuildingTables(function () {
            foreach (self::PROFILE_TABLES as $table) {
                $this->forge->modifyColumn($table, [
                    'preferred_language' => ['name' => 'preferred_language', 'type' => 'VARCHAR', 'constraint' => self::LANGUAGE_LENGTH, 'null' => true],
                ]);
                $this->forge->dropColumn($table, ['timezone', 'avatar_path']);
            }
        });
    }

    public function down()
    {
        // the timezones are gone; the columns come back empty
        $this->rebuildingTables(function () {
            foreach (self::PROFILE_TABLES as $table) {
                $this->forge->modifyColumn($table, [
                    'preferred_language' => ['name' => 'preferred_language', 'type' => 'VARCHAR', 'constraint' => self::LANGUAGE_LENGTH_BEFORE, 'null' => true],
                ]);
                $this->forge->addColumn($table, [
                    'timezone'    => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true, 'after' => 'preferred_language'],
                    'avatar_path' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'after' => 'timezone'],
                ]);
            }
        });

        $log = new ActivityLog();
        $this->db->transException(true)->transStart();
        $accounts = $this->db->table('auth_master')
            ->select('id, account_type, avatar_path')
            ->where('avatar_path IS NOT NULL')
            ->get()
            ->getResultArray();
        foreach ($accounts as $account) {
            $table   = $account['account_type'] === 'S' ? 'staff_master' : 'client_master';
            $profile = $this->db->table($table)->select('id, avatar_path')->where('auth_id', $account['id'])->get()->getRowArray();
            if ($profile === null) {
                continue; // nowhere to keep it; it goes with the auth_master column
            }
            $this->db->table($table)->where('id', $profile['id'])->update(['avatar_path' => $account['avatar_path']]);
            $log->updated(null, 'Moved avatar from auth_master', $table, (int) $profile['id'], $profile, ['avatar_path' => $account['avatar_path']]);
        }
        $this->db->transComplete();
        $this->db->transException(false);

        $this->rebuildingTables(fn () => $this->forge->dropColumn('auth_master', 'avatar_path'));
    }

    /**
     * SQLite (the test database) modifies and drops columns by renaming the table to temp_<table>, creating it
     * anew and dropping temp_<table>. Since SQLite 3.26 the rename also points the other tables' foreign keys
     * at temp_<table>, leaving them broken once it is dropped; the legacy rename leaves them alone.
     * @param callable $work
     * @return void
     */
    private function rebuildingTables(callable $work): void
    {
        $sqlite = $this->db->DBDriver === 'SQLite3';
        if ($sqlite) {
            $this->db->query('PRAGMA legacy_alter_table = ON');
        }
        try {
            $work();
        } finally {
            if ($sqlite) {
                $this->db->query('PRAGMA legacy_alter_table = OFF');
            }
        }
    }
}
