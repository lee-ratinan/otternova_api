<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Creates the order_history_access table (App\Models\OrderHistoryAccessModel): a customer's way into the order history
 * of one business on the mart, by their email address. Asked for on the mart, it is a link emailed to the address
 * (link_hash, single use, short-lived); the link, once opened, becomes a session the mart keeps (session_hash). Only
 * hashes of the tokens are stored. A row is for one business: its link and its session show nothing of another.
 * Depends on business_master.
 */
class CreateOrderHistoryAccessTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'                 => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'business_id'        => ['type' => 'INT', 'unsigned' => true],
            'email_address'      => ['type' => 'VARCHAR', 'constraint' => 64], // lowercase
            'link_hash'          => ['type' => 'CHAR', 'constraint' => 64],    // SHA-256 of the emailed token
            'link_expires_at'    => ['type' => 'INT', 'unsigned' => true],     // Unix seconds
            'link_used_at'       => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'session_hash'       => ['type' => 'CHAR', 'constraint' => 64, 'null' => true],
            'session_expires_at' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            // maintained by the database, not the model
            'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('link_hash');
        $this->forge->addUniqueKey('session_hash');
        $this->forge->addKey(['business_id', 'email_address']);
        $this->forge->addForeignKey('business_id', 'business_master', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('order_history_access', true); // no ENGINE option so the SQLite test database can run this too
    }

    public function down()
    {
        $this->forge->dropTable('order_history_access', true);
    }
}
