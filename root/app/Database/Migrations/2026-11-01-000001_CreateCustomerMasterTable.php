<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Creates the customer_master table (App\Models\CustomerMasterModel):
 * the essential customer data the system needs; everything else about a customer goes in its own table
 */
class CreateCustomerMasterTable extends Migration
{
    public function up()
    {
        // SQLite (the test database) has no ON UPDATE; there the model has to be relied on instead
        $onUpdate = $this->db->DBDriver === 'MySQLi' ? ' ON UPDATE CURRENT_TIMESTAMP' : '';

        $this->forge->addField([
            'id'               => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'email_address'    => ['type' => 'VARCHAR', 'constraint' => 64],
            'telephone_number' => ['type' => 'VARCHAR', 'constraint' => 24, 'null' => true],
            'customer_name'    => ['type' => 'VARCHAR', 'constraint' => 255],
            'last_activity'    => ['type' => 'DATETIME', 'null' => true],
            // maintained by the database, not the model
            'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP' . $onUpdate,
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('email_address');
        $this->forge->addKey('telephone_number');
        $this->forge->createTable('customer_master', true); // InnoDB is MySQL's default engine; no ENGINE option so the SQLite test database can run this too
    }

    public function down()
    {
        $this->forge->dropTable('customer_master', true);
    }
}
