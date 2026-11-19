<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Creates the business_customer_note table (App\Models\BusinessCustomerNoteModel): what the people of a business note
 * down about one of its customers, one row per note, with who wrote it (created_by, auth_master.id) and when. A note
 * belongs to one business: customer_master is shared by every business, so a note there would show to all of them.
 * Notes are added and deleted, never edited.
 *
 * Also indexes order_master on (business_id, customer_id), which App\Models\BusinessCustomerModel groups the
 * customers of a business by.
 * Depends on business_master, customer_master, auth_master and order_master.
 */
class CreateBusinessCustomerNoteTable extends Migration
{
    private const ORDER_INDEX = 'business_id_customer_id';

    public function up()
    {
        $mysql    = $this->db->DBDriver === 'MySQLi';
        $onUpdate = $mysql ? ' ON UPDATE CURRENT_TIMESTAMP' : '';
        // SQLite (the test database) names its foreign keys itself
        $fk = static fn (string $name): string => $mysql ? $name : '';
        $this->forge->addField([
            'id'          => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'public_id'   => ['type' => 'CHAR', 'constraint' => 12],
            'business_id' => ['type' => 'INT', 'unsigned' => true],
            'customer_id' => ['type' => 'INT', 'unsigned' => true],
            'note_text'   => ['type' => 'TEXT'],
            'created_by'  => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            // maintained by the database, not the model
            'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP' . $onUpdate,
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('public_id', 'uq_business_customer_note_public_id');
        $this->forge->addKey(['business_id', 'customer_id', 'created_at'], false, false, 'business_id_customer_id_created_at');
        $this->forge->addForeignKey('business_id', 'business_master', 'id', 'CASCADE', 'CASCADE', $fk('fk_business_customer_note_business'));
        $this->forge->addForeignKey('customer_id', 'customer_master', 'id', 'CASCADE', 'CASCADE', $fk('fk_business_customer_note_customer'));
        $this->forge->addForeignKey('created_by', 'auth_master', 'id', 'CASCADE', 'SET NULL', $fk('fk_business_customer_note_created_by'));
        $this->forge->createTable('business_customer_note', true); // no ENGINE option so the SQLite test database can run this too

        $this->forge->addKey(['business_id', 'customer_id'], false, false, self::ORDER_INDEX);
        $this->forge->processIndexes('order_master');
    }

    public function down()
    {
        $this->db->query($this->db->DBDriver === 'MySQLi'
            ? 'DROP INDEX ' . self::ORDER_INDEX . ' ON ' . $this->db->prefixTable('order_master')
            : 'DROP INDEX IF EXISTS ' . self::ORDER_INDEX);
        $this->forge->dropTable('business_customer_note', true);
    }
}
