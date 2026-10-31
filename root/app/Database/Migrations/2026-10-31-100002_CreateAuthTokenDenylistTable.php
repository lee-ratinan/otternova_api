<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Creates the auth_token_denylist table (App\Models\AuthTokenDenylistModel):
 * the jti of every signed-out access token, kept until the token would have expired anyway
 */
class CreateAuthTokenDenylistTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'jti'        => ['type' => 'VARCHAR', 'constraint' => 64],
            'expires_at' => ['type' => 'INT', 'unsigned' => true], // Unix seconds, the token's exp
            // maintained by the database, not the model
            'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        ]);
        $this->forge->addPrimaryKey('jti');
        $this->forge->addKey('expires_at');
        $this->forge->createTable('auth_token_denylist', true); // InnoDB is MySQL's default engine; no ENGINE option so the SQLite test database can run this too
    }

    public function down()
    {
        $this->forge->dropTable('auth_token_denylist', true);
    }
}
