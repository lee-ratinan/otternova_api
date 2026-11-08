<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * When each person of a business works, and where: their availability for bookings, not an HR record.
 *
 * Adds business_store_client_link.link_status ENUM('A', 'I') DEFAULT 'A', after client_id: a person taken off a
 * store keeps their row as I (inactive), so where they worked is never lost; put back, it is A again. The links
 * already there are all active.
 *
 * Creates business_client_hours: a person's regular week, one row per shift; a day may have several (09:00-12:00
 * and 13:00-18:00), at different stores. Every week is taken to be the same.
 * - store_id: where the shift is. The foreign key on (business_id, store_id, client_id) is business_store_client_link's
 *   unique key, so the person works at that store, and the store and the person are in the same business.
 * - day_of_week TINYINT: 1 Monday to 7 Sunday (ISO 8601)
 * - start_time, end_time TIME: in the store's store_timezone. An end_time not after start_time is on the next day
 *   (22:00-06:00), and still belongs to the day the shift starts on, as in business_store_hours.
 * - active_status ENUM('A', 'I') DEFAULT 'A': a person taken off a store has all their shifts there made I; only
 *   active shifts count. Rows are never deleted, so there is no unique key on the shift: an inactive one may have the
 *   same day and start as an active one.
 * A shift may start before the store opens and end after it closes (opening up, cleaning); customers are only ever
 * offered the part within the store's opening hours.
 *
 * Creates business_client_hours_exceptions: a date whose shifts are not the person's regular ones, replacing them
 * for the whole day, one row per shift like business_client_hours.
 * - exception_type ENUM('V', 'S', 'O'): vacation or leave, sick, other (a swapped shift, an extra day); for whoever
 *   looks at the screen to see why
 * - store_id, start_time, end_time: all NULL for a day off, which must then be the only active row of its date
 * - exception_reason VARCHAR(255) NULL: an optional note, not translated
 * - active_status as in business_client_hours: the shifts at a store a person is taken off become I
 * The foreign key on (business_id, store_id, client_id) is not checked for a day off, whose store_id is NULL; the one
 * on (business_id, client_id) keeps it to a person of the business.
 *
 * The model checks what the database cannot: a person's active shifts never overlap, at any store, a late one
 * running into the next day's first (Sunday's into Monday's) included; and a day off is alone on its date.
 * Nothing here is deleted: the person's business_client_link and store links are never deleted either.
 * Depends on business_client_link, business_store and business_store_client_link.
 */
class CreateBusinessClientHoursTables extends Migration
{
    public function up()
    {
        $mysql = $this->db->DBDriver === 'MySQLi';
        // SQLite (the test database) has no ON UPDATE; there the model has to be relied on instead
        $onUpdate = $mysql ? ' ON UPDATE CURRENT_TIMESTAMP' : '';
        $fkName   = static fn (string $name): string => $mysql ? $name : ''; // nor named foreign keys

        $this->forge->addColumn('business_store_client_link', [
            'link_status' => ['type' => 'ENUM', 'constraint' => ['A', 'I'], 'default' => 'A', 'after' => 'client_id'], // active, inactive
        ]);

        $this->forge->addField([
            'id'            => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'business_id'   => ['type' => 'INT', 'unsigned' => true],
            'client_id'     => ['type' => 'INT', 'unsigned' => true],
            'store_id'      => ['type' => 'INT', 'unsigned' => true],
            'day_of_week'   => ['type' => 'TINYINT', 'unsigned' => true], // 1 Monday to 7 Sunday
            'start_time'    => ['type' => 'TIME'],
            'end_time'      => ['type' => 'TIME'], // not after start_time: the next day
            'active_status' => ['type' => 'ENUM', 'constraint' => ['A', 'I'], 'default' => 'A'], // active, inactive
            // maintained by the database, not the model
            'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP' . $onUpdate,
        ]);
        $this->forge->addPrimaryKey('id');
        // a person's week, and their day
        $this->forge->addKey(['business_id', 'client_id', 'day_of_week']);
        // serves the foreign key on the store link, and who works at a store
        $this->forge->addKey(['business_id', 'store_id', 'client_id']);
        $this->forge->addForeignKey(['business_id', 'store_id', 'client_id'], 'business_store_client_link', ['business_id', 'store_id', 'client_id'],
            'CASCADE', 'RESTRICT', $fkName('fk_business_client_hours_store_link'));
        $this->forge->createTable('business_client_hours', true); // no ENGINE option so the SQLite test database can run this too

        $this->forge->addField([
            'id'               => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'business_id'      => ['type' => 'INT', 'unsigned' => true],
            'client_id'        => ['type' => 'INT', 'unsigned' => true],
            'exception_date'   => ['type' => 'DATE'],
            'exception_type'   => ['type' => 'ENUM', 'constraint' => ['V', 'S', 'O']], // vacation or leave, sick, other
            'store_id'         => ['type' => 'INT', 'unsigned' => true, 'null' => true], // the three NULL: a day off
            'start_time'       => ['type' => 'TIME', 'null' => true],
            'end_time'         => ['type' => 'TIME', 'null' => true],
            'exception_reason' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'active_status'    => ['type' => 'ENUM', 'constraint' => ['A', 'I'], 'default' => 'A'],
            'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP' . $onUpdate,
        ]);
        $this->forge->addPrimaryKey('id');
        // a person's date; it also serves the foreign key on the person
        $this->forge->addKey(['business_id', 'client_id', 'exception_date']);
        // serves the foreign key on the store link, and a person's dates at a store
        $this->forge->addKey(['business_id', 'store_id', 'client_id']);
        $this->forge->addForeignKey(['business_id', 'client_id'], 'business_client_link', ['business_id', 'client_id'],
            'CASCADE', 'RESTRICT', $fkName('fk_business_client_hours_exceptions_client'));
        $this->forge->addForeignKey(['business_id', 'store_id', 'client_id'], 'business_store_client_link', ['business_id', 'store_id', 'client_id'],
            'CASCADE', 'RESTRICT', $fkName('fk_business_client_hours_exceptions_store_link'));
        $this->forge->createTable('business_client_hours_exceptions', true);
    }

    public function down()
    {
        $this->forge->dropTable('business_client_hours_exceptions', true);
        $this->forge->dropTable('business_client_hours', true);
        $this->rebuildingTables(fn () => $this->forge->dropColumn('business_store_client_link', 'link_status'));
    }

    /**
     * SQLite (the test database) drops columns by rebuilding the table; the legacy rename keeps the other tables'
     * foreign keys pointing at it (see AddBusinessAudience)
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
