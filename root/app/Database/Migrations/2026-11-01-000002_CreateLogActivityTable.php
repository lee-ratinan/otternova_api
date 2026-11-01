<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Creates the log_activity table (App\Models\LogActivityModel): an append-only log of every change in the system.
 * The actor is changed_by_cust (a customer), changed_by_acc (an auth_master account), or neither (the system / a cron job).
 * Depends on customer_master and auth_master.
 */
class CreateLogActivityTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'               => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'activity_summary' => ['type' => 'TEXT'],
            'table_involved'   => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => true],
            'table_id_updated' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'activity_detail'  => ['type' => 'JSON', 'null' => true],
            'changed_by_cust'  => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'changed_by_acc'   => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            // maintained by the database, not the model; no updated_at, a log row is never changed
            'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        ]);
        $this->forge->addPrimaryKey('id');
        // the history of one row; table_involved alone is served by the same index
        $this->forge->addKey(['table_involved', 'table_id_updated']);
        $this->forge->addKey('created_at');
        // the log outlives the actor: deleting the customer or account only blanks the reference
        $this->forge->addForeignKey('changed_by_cust', 'customer_master', 'id', 'CASCADE', 'SET NULL');
        $this->forge->addForeignKey('changed_by_acc', 'auth_master', 'id', 'CASCADE', 'SET NULL');
        $this->forge->createTable('log_activity', true); // InnoDB is MySQL's default engine; no ENGINE option so the SQLite test database can run this too
    }

    public function down()
    {
        $this->forge->dropTable('log_activity', true);
    }
}
