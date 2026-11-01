<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Creates the auth_master table (App\Models\AuthMasterModel)
 */
class CreateAuthMasterTable extends Migration
{
    public function up()
    {
        // SQLite (the test database) has no ON UPDATE; there the model has to be relied on instead
        $onUpdate = $this->db->DBDriver === 'MySQLi' ? ' ON UPDATE CURRENT_TIMESTAMP' : '';

        $this->forge->addField([
            'id'               => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'uid'              => ['type' => 'VARCHAR', 'constraint' => 128],
            'email_address'    => ['type' => 'VARCHAR', 'constraint' => 128],
            'telephone_number' => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
            'password_hash'    => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'password_expiry'  => ['type' => 'DATE', 'null' => true],
            'full_name'        => ['type' => 'VARCHAR', 'constraint' => 255],
            'otp_mode'         => ['type' => 'ENUM', 'constraint' => ['E', 'T', 'X'], 'default' => 'E'],
            'account_status'   => ['type' => 'ENUM', 'constraint' => ['A', 'P', 'B', 'S'], 'default' => 'P'],
            'account_type'     => ['type' => 'ENUM', 'constraint' => ['S', 'C'], 'default' => 'C'],
            'admin_notes'      => ['type' => 'TEXT', 'null' => true],
            // maintained by the database, not the model
            'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP' . $onUpdate,
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('uid');
        $this->forge->addUniqueKey('email_address');
        $this->forge->addUniqueKey('telephone_number');
        $this->forge->addKey('account_status');
        $this->forge->addKey('account_type');
        $this->forge->createTable('auth_master', true); // InnoDB is MySQL's default engine; no ENGINE option so the SQLite test database can run this too
    }

    public function down()
    {
        $this->forge->dropTable('auth_master', true);
    }
}
