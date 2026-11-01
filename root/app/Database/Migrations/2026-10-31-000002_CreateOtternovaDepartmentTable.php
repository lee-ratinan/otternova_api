<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Creates the otternova_department table (App\Models\OtternovaDepartmentModel)
 */
class CreateOtternovaDepartmentTable extends Migration
{
    public function up()
    {
        // SQLite (the test database) has no ON UPDATE; there the model has to be relied on instead
        $onUpdate = $this->db->DBDriver === 'MySQLi' ? ' ON UPDATE CURRENT_TIMESTAMP' : '';

        $this->forge->addField([
            'id'              => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'department_code' => ['type' => 'VARCHAR', 'constraint' => 3],
            'department_name' => ['type' => 'VARCHAR', 'constraint' => 255],
            'country_code'    => ['type' => 'CHAR', 'constraint' => 2],
            // maintained by the database, not the model
            'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP' . $onUpdate,
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('department_code');
        $this->forge->createTable('otternova_department', true); // InnoDB is MySQL's default engine; no ENGINE option so the SQLite test database can run this too
    }

    public function down()
    {
        $this->forge->dropTable('otternova_department', true);
    }
}
