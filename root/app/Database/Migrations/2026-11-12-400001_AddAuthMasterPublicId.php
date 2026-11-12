<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Adds public_id CHAR(12) to auth_master, after id: a NanoID (generateNanoId() of id_helper), unique, so a person can
 * be referred to from outside (a staff member on the mart's booking pages) without the auto-increment id or the uid,
 * which is made from the email address. The accounts already there get one each; AuthMasterModel gives new ones
 * theirs (PublicIdTrait). On MySQL it is ascii_bin, as NanoIDs tell upper from lower case (see AddPublicIds).
 * Depends on auth_master.
 */
class AddAuthMasterPublicId extends Migration
{
    private const TABLE  = 'auth_master';
    private const LENGTH = 12;
    private const KEY    = 'uq_auth_master_public_id';

    public function up()
    {
        helper('id');
        $mysql = $this->db->DBDriver === 'MySQLi';
        $table = $this->db->escapeIdentifiers($this->db->prefixTable(self::TABLE));
        // NULL until the rows already there have one
        if ($mysql) {
            $this->db->query('ALTER TABLE ' . $table . ' ADD ' . $this->column(true) . ' AFTER `id`');
        } else {
            $this->forge->addColumn(self::TABLE, ['public_id' => ['type' => 'CHAR', 'constraint' => self::LENGTH, 'null' => true]]);
        }

        $this->db->transException(true)->transStart();
        $used = [];
        foreach ($this->db->table(self::TABLE)->select('id')->get()->getResultArray() as $row) {
            do {
                $publicId = generateNanoId(self::LENGTH);
            } while (isset($used[$publicId]));
            $used[$publicId] = true;
            $this->db->table(self::TABLE)->where('id', $row['id'])->update(['public_id' => $publicId]);
        }
        $this->db->transComplete();
        $this->db->transException(false);

        if ($mysql) {
            $this->db->query('ALTER TABLE ' . $table . ' MODIFY ' . $this->column(false) . ', ADD UNIQUE KEY `' . self::KEY . '` (`public_id`)');
        } else {
            $this->rebuildingTables(fn () => $this->forge->modifyColumn(self::TABLE, [
                'public_id' => ['name' => 'public_id', 'type' => 'CHAR', 'constraint' => self::LENGTH, 'null' => false],
            ]));
            $this->forge->addUniqueKey('public_id', self::KEY);
            $this->forge->processIndexes(self::TABLE);
        }
    }

    public function down()
    {
        if ($this->db->DBDriver === 'MySQLi') {
            $this->db->query('ALTER TABLE ' . $this->db->escapeIdentifiers($this->db->prefixTable(self::TABLE)) . ' DROP INDEX `' . self::KEY . '`, DROP COLUMN `public_id`');
        } else {
            $this->db->query('DROP INDEX IF EXISTS ' . $this->db->escapeIdentifiers(self::KEY));
            $this->rebuildingTables(fn () => $this->forge->dropColumn(self::TABLE, 'public_id'));
        }
    }

    /** MySQL's definition of the column */
    private function column(bool $null): string
    {
        return '`public_id` CHAR(' . self::LENGTH . ') CHARACTER SET ascii COLLATE ascii_bin ' . ($null ? 'NULL' : 'NOT NULL');
    }

    /**
     * SQLite (the test database) modifies and drops columns by rebuilding the table; the legacy rename keeps the
     * other tables' foreign keys pointing at it (see AddPublicIds)
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
