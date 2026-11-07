<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * When each store is open (App\Models\BusinessStoreHoursModel).
 *
 * Adds business_store.store_timezone VARCHAR(64), after store_type: the IANA timezone the store's hours are in, one
 * of listSupportedTimezones(business_master.country_code), which BusinessStoreModel::localeErrors() checks. A country
 * may have several (Indonesia has three), so it is the store's, not the business's. The stores already there get
 * their country's first one.
 *
 * Creates business_store_hours: the weekly hours, one row per opening; a day may have several (10:00-14:00 and
 * 17:00-21:00), and a day with none is closed.
 * - day_of_week TINYINT: 1 Monday to 7 Sunday (ISO 8601)
 * - open_time, close_time TIME: in store_timezone. A close_time not after open_time is on the next day: 10:00-02:00
 *   closes at 2am the day after, and 00:00-00:00 is open the whole 24 hours. That day still counts as the opening's
 *   business day.
 *
 * Creates business_store_hours_exceptions: a date whose hours are not the weekly ones, replacing them for the whole
 * business day, one row per opening like business_store_hours. A row with open_time and close_time both NULL closes
 * the store all day, and must be the only row of its date. exception_reason ("X'Mas", "Employee training") is not
 * translated; each row of a date carries the same one.
 *
 * The model checks what the database cannot: openings of one day never overlap, including one running past
 * midnight into the next day's first; and a closed row is alone on its date (unique keys let NULLs through).
 * Both tables go with their store.
 * Depends on business_store and business_master.
 */
class CreateBusinessStoreHoursTables extends Migration
{
    public function up()
    {
        helper('list');
        $mysql = $this->db->DBDriver === 'MySQLi';
        // SQLite (the test database) has no ON UPDATE; there the model has to be relied on instead
        $onUpdate = $mysql ? ' ON UPDATE CURRENT_TIMESTAMP' : '';
        $fkName   = static fn (string $name): string => $mysql ? $name : ''; // nor named foreign keys

        // NULL until the stores already there have one
        $this->forge->addColumn('business_store', [
            'store_timezone' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true, 'after' => 'store_type'],
        ]);
        $stores = $this->db->table('business_store s')
            ->select('s.id, b.country_code')
            ->join('business_master b', 'b.id = s.business_id')
            ->get()
            ->getResultArray();
        $this->db->transException(true)->transStart();
        foreach ($stores as $store) {
            $timezone = array_key_first(listSupportedTimezones((string) $store['country_code'])) ?? 'UTC';
            $this->db->table('business_store')->where('id', $store['id'])->update(['store_timezone' => $timezone]);
        }
        $this->db->transComplete();
        $this->db->transException(false);
        $this->rebuildingTables(fn () => $this->forge->modifyColumn('business_store', [
            'store_timezone' => ['name' => 'store_timezone', 'type' => 'VARCHAR', 'constraint' => 64, 'null' => false],
        ]));

        $this->forge->addField([
            'id'          => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'store_id'    => ['type' => 'INT', 'unsigned' => true],
            'day_of_week' => ['type' => 'TINYINT', 'unsigned' => true], // 1 Monday to 7 Sunday
            'open_time'   => ['type' => 'TIME'],
            'close_time'  => ['type' => 'TIME'], // not after open_time: the next day
            // maintained by the database, not the model
            'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP' . $onUpdate,
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['store_id', 'day_of_week', 'open_time']); // also the lookup of a day
        $this->forge->addForeignKey('store_id', 'business_store', 'id', 'CASCADE', 'CASCADE', $fkName('fk_business_store_hours_store'));
        $this->forge->createTable('business_store_hours', true); // no ENGINE option so the SQLite test database can run this too

        $this->forge->addField([
            'id'               => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'store_id'         => ['type' => 'INT', 'unsigned' => true],
            'exception_date'   => ['type' => 'DATE'], // the business day it replaces
            'open_time'        => ['type' => 'TIME', 'null' => true], // both NULL: closed all day
            'close_time'       => ['type' => 'TIME', 'null' => true],
            'exception_reason' => ['type' => 'VARCHAR', 'constraint' => 255],
            'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP' . $onUpdate,
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['store_id', 'exception_date', 'open_time']); // also the lookup of a date
        $this->forge->addForeignKey('store_id', 'business_store', 'id', 'CASCADE', 'CASCADE', $fkName('fk_business_store_hours_exceptions_store'));
        $this->forge->createTable('business_store_hours_exceptions', true);
    }

    public function down()
    {
        $this->forge->dropTable('business_store_hours_exceptions', true);
        $this->forge->dropTable('business_store_hours', true);
        $this->rebuildingTables(fn () => $this->forge->dropColumn('business_store', 'store_timezone'));
    }

    /**
     * SQLite (the test database) changes and drops columns by rebuilding the table; the legacy rename keeps the other
     * tables' foreign keys pointing at it (see AddBusinessAudience)
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
