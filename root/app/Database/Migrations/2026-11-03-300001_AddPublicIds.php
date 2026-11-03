<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Adds public_id to the tables whose rows are referred to from outside: a NanoID (generateNanoId() of id_helper),
 * unique within its table, so the auto-increment id never has to go out. The rows already there get one each.
 * NanoIDs tell upper from lower case, so on MySQL the column is ascii_bin; SQLite (the test database) compares
 * text case-sensitively already.
 * Depends on business_type, customer_master and the otternova_ledger_* tables.
 */
class AddPublicIds extends Migration
{
    /** Table => length of its public_id */
    public const LENGTHS = [
        'business_type'                 => 4,
        'customer_master'               => 12,
        'otternova_ledger_account'      => 4,
        'otternova_ledger_category'     => 4,
        'otternova_ledger_counterparty' => 8,
        'otternova_ledger_master'       => 12,
    ];

    public function up()
    {
        helper('id');
        $mysql = $this->db->DBDriver === 'MySQLi';
        foreach (self::LENGTHS as $table => $length) {
            // NULL until the rows already there have one
            if ($mysql) {
                $this->db->query('ALTER TABLE ' . $this->db->escapeIdentifiers($this->db->prefixTable($table)) . ' ADD ' . $this->column($length, true) . ' AFTER `id`');
            } else {
                $this->forge->addColumn($table, ['public_id' => ['type' => 'CHAR', 'constraint' => $length, 'null' => true]]);
            }

            $this->db->transException(true)->transStart();
            $used = [];
            foreach ($this->db->table($table)->select('id')->get()->getResultArray() as $row) {
                do {
                    $publicId = generateNanoId($length);
                } while (isset($used[$publicId]));
                $used[$publicId] = true;
                $this->db->table($table)->where('id', $row['id'])->update(['public_id' => $publicId]);
            }
            $this->db->transComplete();
            $this->db->transException(false);

            if ($mysql) {
                $this->db->query('ALTER TABLE ' . $this->db->escapeIdentifiers($this->db->prefixTable($table))
                    . ' MODIFY ' . $this->column($length, false) . ', ADD UNIQUE KEY ' . $this->db->escapeIdentifiers($this->keyName($table)) . ' (`public_id`)');
            } else {
                $this->rebuildingTables(fn () => $this->forge->modifyColumn($table, [
                    'public_id' => ['name' => 'public_id', 'type' => 'CHAR', 'constraint' => $length, 'null' => false],
                ]));
                $this->forge->addUniqueKey('public_id', $this->keyName($table));
                $this->forge->processIndexes($table);
            }
        }
    }

    public function down()
    {
        foreach (array_keys(self::LENGTHS) as $table) {
            if ($this->db->DBDriver === 'MySQLi') {
                $this->db->query('ALTER TABLE ' . $this->db->escapeIdentifiers($this->db->prefixTable($table))
                    . ' DROP INDEX ' . $this->db->escapeIdentifiers($this->keyName($table)) . ', DROP COLUMN `public_id`');
            } else {
                $this->db->query('DROP INDEX IF EXISTS ' . $this->db->escapeIdentifiers($this->keyName($table)));
                $this->rebuildingTables(fn () => $this->forge->dropColumn($table, 'public_id'));
            }
        }
    }

    /**
     * MySQL's definition of the column
     * @param int  $length
     * @param bool $null
     * @return string
     */
    private function column(int $length, bool $null): string
    {
        return '`public_id` CHAR(' . $length . ') CHARACTER SET ascii COLLATE ascii_bin ' . ($null ? 'NULL' : 'NOT NULL');
    }

    /**
     * Named after the column, so a clash on it can be told from one on another unique key (see PublicIdTrait)
     * @param string $table
     * @return string
     */
    private function keyName(string $table): string
    {
        return 'uq_' . $table . '_public_id';
    }

    /**
     * SQLite (the test database) modifies and drops columns by rebuilding the table; the legacy rename keeps the
     * other tables' foreign keys pointing at it (see MoveAvatarAndTrimProfileLocale)
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
