<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Creates the business_master_translation table: a business's name in other languages of listLanguages().
 * Optional; business_master.business_name is shown where a language has no row.
 * Depends on business_master.
 */
class CreateBusinessMasterTranslationTable extends Migration
{
    public function up()
    {
        // SQLite (the test database) has no ON UPDATE; there the model has to be relied on instead
        $onUpdate = $this->db->DBDriver === 'MySQLi' ? ' ON UPDATE CURRENT_TIMESTAMP' : '';
        $fkName   = fn (string $name): string => $this->db->DBDriver === 'MySQLi' ? $name : ''; // nor named foreign keys

        $this->forge->addField([
            'id'            => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'business_id'   => ['type' => 'INT', 'unsigned' => true],
            'language_code' => ['type' => 'VARCHAR', 'constraint' => 5], // BCP 47: en-US, th
            'business_name' => ['type' => 'VARCHAR', 'constraint' => 255],
            // maintained by the database, not the model
            'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP' . $onUpdate,
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['business_id', 'language_code']);
        $this->forge->addForeignKey('business_id', 'business_master', 'id', 'CASCADE', 'CASCADE', $fkName('fk_business_master_translation_business'));
        $this->forge->createTable('business_master_translation', true); // InnoDB is MySQL's default engine; no ENGINE option so the SQLite test database can run this too
    }

    public function down()
    {
        $this->forge->dropTable('business_master_translation', true);
    }
}
