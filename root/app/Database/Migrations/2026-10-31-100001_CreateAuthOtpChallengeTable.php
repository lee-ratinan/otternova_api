<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Creates the auth_otp_challenge table (App\Models\AuthOtpChallengeModel):
 * one row per pending email OTP, holding only hashes of the otp_token and of the code
 */
class CreateAuthOtpChallengeTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'           => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'auth_id'      => ['type' => 'INT', 'unsigned' => true],
            'account_type' => ['type' => 'CHAR', 'constraint' => 1],
            'token_hash'   => ['type' => 'CHAR', 'constraint' => 64],
            'otp_hash'     => ['type' => 'VARCHAR', 'constraint' => 255],
            'attempts'     => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 0],
            'expires_at'   => ['type' => 'INT', 'unsigned' => true], // Unix seconds
            // maintained by the database, not the model
            'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('token_hash');
        $this->forge->addKey('expires_at');
        $this->forge->addForeignKey('auth_id', 'auth_master', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('auth_otp_challenge', true); // InnoDB is MySQL's default engine; no ENGINE option so the SQLite test database can run this too
    }

    public function down()
    {
        $this->forge->dropTable('auth_otp_challenge', true);
    }
}
