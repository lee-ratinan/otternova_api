<?php

namespace Tests\Support\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The tables the otternova_admin migrations create in the real database, cut down to the columns
 * the auth endpoints use, for the SQLite test database
 */
class CreateAuthTables extends Migration
{
    protected $DBGroup = 'tests';

    public function up(): void
    {
        $this->forge->addField([
            'id'               => ['type' => 'INTEGER', 'auto_increment' => true],
            'uid'              => ['type' => 'VARCHAR', 'constraint' => 128],
            'email_address'    => ['type' => 'VARCHAR', 'constraint' => 128],
            'telephone_number' => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
            'password_hash'    => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'password_expiry'  => ['type' => 'DATE', 'null' => true],
            'full_name'        => ['type' => 'VARCHAR', 'constraint' => 255],
            'otp_mode'         => ['type' => 'CHAR', 'constraint' => 1, 'default' => 'E'],
            'account_status'   => ['type' => 'CHAR', 'constraint' => 1, 'default' => 'P'],
            'account_type'     => ['type' => 'CHAR', 'constraint' => 1, 'default' => 'C'],
            'admin_notes'      => ['type' => 'TEXT', 'null' => true],
            'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('uid');
        $this->forge->addUniqueKey('email_address');
        $this->forge->createTable('auth_master');

        $this->forge->addField([
            'id'                => ['type' => 'INTEGER', 'auto_increment' => true],
            'auth_id'           => ['type' => 'INTEGER'],
            'employee_code'     => ['type' => 'VARCHAR', 'constraint' => 32],
            'job_title'         => ['type' => 'VARCHAR', 'constraint' => 128],
            'employment_status' => ['type' => 'CHAR', 'constraint' => 1, 'default' => 'P'],
            'gender'            => ['type' => 'VARCHAR', 'constraint' => 2, 'null' => true],
            'date_of_birth'     => ['type' => 'DATE', 'null' => true],
            'timezone'          => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'avatar_path'       => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'status_message'    => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('auth_id');
        $this->forge->addForeignKey('auth_id', 'auth_master', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('staff_master');

        $this->forge->addField([
            'id'                 => ['type' => 'INTEGER', 'auto_increment' => true],
            'auth_id'            => ['type' => 'INTEGER'],
            'display_name'       => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'status_message'     => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'status_emoji'       => ['type' => 'VARCHAR', 'constraint' => 16, 'null' => true],
            'avatar_path'        => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'timezone'           => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'preferred_language' => ['type' => 'VARCHAR', 'constraint' => 16, 'null' => true],
            'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('auth_id');
        $this->forge->addForeignKey('auth_id', 'auth_master', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('client_master');
    }

    public function down(): void
    {
        $this->forge->dropTable('client_master', true);
        $this->forge->dropTable('staff_master', true);
        $this->forge->dropTable('auth_master', true);
    }
}
