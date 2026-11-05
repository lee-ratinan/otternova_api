<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Creates the plan_master table: the plans a business can be on (free, boost, velocity, quantum).
 * plan_code is both the key the code checks and, through ucfirst(), the name shown; plan names are not translated.
 * plan_tier orders the plans, so that a change between two of them is an upgrade or a downgrade.
 * The prices are in plan_version; the free plan has none, as a business with no live subscription is on it.
 */
class CreatePlanMasterTable extends Migration
{
    public function up()
    {
        // SQLite (the test database) has no ON UPDATE; there the model has to be relied on instead
        $onUpdate = $this->db->DBDriver === 'MySQLi' ? ' ON UPDATE CURRENT_TIMESTAMP' : '';

        $this->forge->addField([
            'id'               => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'plan_code'        => ['type' => 'VARCHAR', 'constraint' => 20], // lower case: free, boost, velocity, quantum
            'plan_description' => ['type' => 'TEXT', 'null' => true],
            'plan_tier'        => ['type' => 'TINYINT', 'unsigned' => true], // 0 free, then higher for the bigger plans
            // maintained by the database, not the model
            'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP' . $onUpdate,
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('plan_code');
        $this->forge->addUniqueKey('plan_tier'); // no two plans of the same rank
        $this->forge->createTable('plan_master', true); // InnoDB is MySQL's default engine; no ENGINE option so the SQLite test database can run this too
    }

    public function down()
    {
        $this->forge->dropTable('plan_master', true);
    }
}
