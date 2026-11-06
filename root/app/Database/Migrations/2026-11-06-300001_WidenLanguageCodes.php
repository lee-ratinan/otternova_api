<?php

namespace App\Database\Migrations;

use App\Libraries\ActivityLog;
use CodeIgniter\Database\Migration;

/**
 * Widens the BCP 47 language columns from VARCHAR(5) to VARCHAR(16), so regional tags longer than en-US fit,
 * such as nan-TW (Taiwanese Hokkien), yue-HK or sr-Latn-RS:
 * - language_code of every *_translation table
 * - preferred_language of staff_master and client_master (still nullable)
 * The rows already there keep their values.
 * down() shrinks them back to 5: a translation with a longer language_code is deleted and a longer
 * preferred_language becomes NULL, each logged to log_activity as a change by the system.
 * Depends on the tables below and log_activity.
 */
class WidenLanguageCodes extends Migration
{
    private const TRANSLATION_TABLES = [
        'business_type_translation',
        'business_master_translation',
        'business_store_translation',
        'artifact_category_translation',
        'artifact_master_translation',
        'artifact_attribute_translation',
        'artifact_attribute_value_translation',
    ];

    private const PROFILE_TABLES = ['staff_master', 'client_master'];

    /** Length of the language columns before and after */
    private const LENGTH_BEFORE = 5;
    private const LENGTH        = 16;

    public function up()
    {
        $this->rebuildingTables(fn () => $this->resize(self::LENGTH));
    }

    public function down()
    {
        $log = new ActivityLog();
        $this->db->transException(true)->transStart();
        foreach (self::TRANSLATION_TABLES as $table) {
            $rows = $this->db->table($table)->where('LENGTH(language_code) >', self::LENGTH_BEFORE)->get()->getResultArray();
            foreach ($rows as $row) {
                $this->db->table($table)->where('id', $row['id'])->delete();
                $log->deleted(null, 'Dropped translation whose language code is too long', $table, (int) $row['id'], $row);
            }
        }
        foreach (self::PROFILE_TABLES as $table) {
            $rows = $this->db->table($table)
                ->select('id, preferred_language')
                ->where('LENGTH(preferred_language) >', self::LENGTH_BEFORE)
                ->get()
                ->getResultArray();
            foreach ($rows as $row) {
                $this->db->table($table)->where('id', $row['id'])->update(['preferred_language' => null]);
                $log->updated(null, 'Cleared preferred language that is too long', $table, (int) $row['id'], $row, ['preferred_language' => null]);
            }
        }
        $this->db->transComplete();
        $this->db->transException(false);

        $this->rebuildingTables(fn () => $this->resize(self::LENGTH_BEFORE));
    }

    /**
     * Sets the length of every language column
     * @param int $length
     * @return void
     */
    private function resize(int $length): void
    {
        foreach (self::TRANSLATION_TABLES as $table) {
            $this->forge->modifyColumn($table, [
                'language_code' => ['name' => 'language_code', 'type' => 'VARCHAR', 'constraint' => $length],
            ]);
        }
        foreach (self::PROFILE_TABLES as $table) {
            $this->forge->modifyColumn($table, [
                'preferred_language' => ['name' => 'preferred_language', 'type' => 'VARCHAR', 'constraint' => $length, 'null' => true],
            ]);
        }
    }

    /**
     * SQLite (the test database) modifies columns by rebuilding the table; the legacy rename keeps the other tables'
     * foreign keys pointing at it (see MoveAvatarAndTrimProfileLocale)
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
