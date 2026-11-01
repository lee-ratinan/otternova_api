<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Creates the auth_password_change table (App\Models\AuthPasswordChangeModel):
 * one row per pending change of an expired password, holding only a hash of the password_change_token
 */
class CreateAuthPasswordChangeTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'           => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'auth_id'      => ['type' => 'INT', 'unsigned' => true],
            'account_type' => ['type' => 'CHAR', 'constraint' => 1],
            'token_hash'   => ['type' => 'CHAR', 'constraint' => 64],
            'attempts'     => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 0],
            'expires_at'   => ['type' => 'INT', 'unsigned' => true], // Unix seconds
            // maintained by the database, not the model
            'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('token_hash');
        $this->forge->addKey('expires_at');
        $this->forge->addForeignKey('auth_id', 'auth_master', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('auth_password_change', true); // no ENGINE option so the SQLite test database can run this too
    }

    public function down()
    {
        $this->forge->dropTable('auth_password_change', true);
    }
}
