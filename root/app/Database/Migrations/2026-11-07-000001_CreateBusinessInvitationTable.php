<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Creates the business_invitation table (App\Models\BusinessInvitationModel): one row per open invitation of an
 * owner to someone with no account yet, holding the role and owner's notes their business_client_link gets once they
 * sign up with that email address, and only a hash of the token in the emailed link. A row goes once accepted,
 * replaced by a new one to the same address, or found expired; log_activity keeps the history.
 * Depends on business_master and auth_master.
 */
class CreateBusinessInvitationTable extends Migration
{
    public function up()
    {
        $fkName = fn (string $name): string => $this->db->DBDriver === 'MySQLi' ? $name : ''; // SQLite has no named foreign keys

        $this->forge->addField([
            'id'            => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'business_id'   => ['type' => 'INT', 'unsigned' => true],
            'email_address' => ['type' => 'VARCHAR', 'constraint' => 128], // stored lower-cased
            'client_role'   => ['type' => 'ENUM', 'constraint' => ['O', 'M', 'E'], 'default' => 'E'], // owner, manager, employee
            'owner_notes'   => ['type' => 'TEXT', 'null' => true],
            'token_hash'    => ['type' => 'CHAR', 'constraint' => 64],
            'expires_at'    => ['type' => 'INT', 'unsigned' => true], // Unix seconds
            'invited_by'    => ['type' => 'INT', 'unsigned' => true, 'null' => true], // auth_master.id of the owner
            // maintained by the database, not the model
            'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('token_hash');
        $this->forge->addUniqueKey(['business_id', 'email_address']);
        $this->forge->addKey('email_address');
        $this->forge->addKey('expires_at');
        $this->forge->addForeignKey('business_id', 'business_master', 'id', 'CASCADE', 'CASCADE', $fkName('fk_business_invitation_business'));
        $this->forge->addForeignKey('invited_by', 'auth_master', 'id', 'CASCADE', 'SET NULL', $fkName('fk_business_invitation_invited_by'));
        $this->forge->createTable('business_invitation', true); // no ENGINE option so the SQLite test database can run this too
    }

    public function down()
    {
        $this->forge->dropTable('business_invitation', true);
    }
}
