<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Creates the business_type table: the kinds of business a client can pick, e.g. restaurant or salon.
 * type_code is a stable key never shown to users; the names shown are in business_type_translation.
 * A type in use cannot be deleted; type_status I (inactive) retires it from new businesses instead.
 */
class CreateBusinessTypeTable extends Migration
{
    public function up()
    {
        // SQLite (the test database) has no ON UPDATE; there the model has to be relied on instead
        $onUpdate = $this->db->DBDriver === 'MySQLi' ? ' ON UPDATE CURRENT_TIMESTAMP' : '';

        $this->forge->addField([
            'id'          => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'type_code'   => ['type' => 'VARCHAR', 'constraint' => 64],
            'type_status' => ['type' => 'ENUM', 'constraint' => ['A', 'I'], 'default' => 'A'],
            // maintained by the database, not the model
            'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP' . $onUpdate,
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('type_code');
        $this->forge->createTable('business_type', true); // InnoDB is MySQL's default engine; no ENGINE option so the SQLite test database can run this too
    }

    public function down()
    {
        $this->forge->dropTable('business_type', true);
    }
}
