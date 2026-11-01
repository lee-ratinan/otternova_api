<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Creates the client_master table (App\Models\ClientMasterModel)
 * Depends on auth_master.
 */
class CreateClientMasterTable extends Migration
{
    public function up()
    {
        // SQLite (the test database) has no ON UPDATE; there the model has to be relied on instead
        $onUpdate = $this->db->DBDriver === 'MySQLi' ? ' ON UPDATE CURRENT_TIMESTAMP' : '';
        $fkName   = fn (string $name): string => $this->db->DBDriver === 'MySQLi' ? $name : ''; // nor named foreign keys

        $this->forge->addField([
            'id'                 => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'auth_id'            => ['type' => 'INT', 'unsigned' => true],
            'legal_first_name'   => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => true],
            'legal_middle_name'  => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => true],
            'legal_last_name'    => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => true],
            'display_name'       => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'national_id_type'   => ['type' => 'ENUM', 'constraint' => ['N', 'P'], 'null' => true],
            'national_id_number' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'status_message'     => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'status_emoji'       => ['type' => 'VARCHAR', 'constraint' => 16, 'null' => true],
            'gender'             => ['type' => 'ENUM', 'constraint' => ['M', 'F', 'NB', 'X', 'N'], 'null' => true],
            'date_of_birth'      => ['type' => 'DATE', 'null' => true],
            'nationality_code'   => ['type' => 'CHAR', 'constraint' => 2, 'null' => true],
            'preferred_language' => ['type' => 'VARCHAR', 'constraint' => 16, 'null' => true],
            'timezone'           => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'avatar_path'        => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'bio'                => ['type' => 'TEXT', 'null' => true],
            'marketing_opt_in'   => ['type' => 'TINYINT', 'constraint' => 1, 'unsigned' => true, 'default' => 0],
            'internal_notes'     => ['type' => 'TEXT', 'null' => true],
            // maintained by the database, not the model
            'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP' . $onUpdate,
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('auth_id');
        $this->forge->addForeignKey('auth_id', 'auth_master', 'id', 'CASCADE', 'CASCADE', $fkName('fk_client_master_auth'));
        $this->forge->createTable('client_master', true); // InnoDB is MySQL's default engine; no ENGINE option so the SQLite test database can run this too
    }

    public function down()
    {
        $this->forge->dropTable('client_master', true);
    }
}
