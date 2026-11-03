<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Creates the business_client_link table: who works in which business, and as what.
 * One row per client per business; a rehire reactivates it, and log_activity keeps the history.
 * Neither side can be deleted while linked, so ownership is never lost silently.
 * Depends on business_master and client_master.
 */
class CreateBusinessClientLinkTable extends Migration
{
    public function up()
    {
        // SQLite (the test database) has no ON UPDATE; there the model has to be relied on instead
        $onUpdate = $this->db->DBDriver === 'MySQLi' ? ' ON UPDATE CURRENT_TIMESTAMP' : '';
        $fkName   = fn (string $name): string => $this->db->DBDriver === 'MySQLi' ? $name : ''; // nor named foreign keys

        $this->forge->addField([
            'id'               => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'business_id'      => ['type' => 'INT', 'unsigned' => true],
            'client_id'        => ['type' => 'INT', 'unsigned' => true],
            'client_status'    => ['type' => 'ENUM', 'constraint' => ['A', 'T'], 'default' => 'A'],      // active, terminated
            'client_role'      => ['type' => 'ENUM', 'constraint' => ['O', 'M', 'E'], 'default' => 'E'], // owner, manager, employee
            'start_date'       => ['type' => 'DATE'],
            'termination_date' => ['type' => 'DATE', 'null' => true, 'default' => null],
            'owner_notes'      => ['type' => 'TEXT', 'null' => true],
            // maintained by the database, not the model
            'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP' . $onUpdate,
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['business_id', 'client_id']);
        $this->forge->addKey('client_id');
        $this->forge->addForeignKey('business_id', 'business_master', 'id', 'CASCADE', 'RESTRICT', $fkName('fk_business_client_link_business'));
        $this->forge->addForeignKey('client_id', 'client_master', 'id', 'CASCADE', 'RESTRICT', $fkName('fk_business_client_link_client'));
        $this->forge->createTable('business_client_link', true); // InnoDB is MySQL's default engine; no ENGINE option so the SQLite test database can run this too
    }

    public function down()
    {
        $this->forge->dropTable('business_client_link', true);
    }
}
