<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Creates the business_type_translation table: the name of a business type in each language of
 * listSupportedLanguages(). The model requires a row for every one of them; the database cannot.
 * Depends on business_type.
 */
class CreateBusinessTypeTranslationTable extends Migration
{
    public function up()
    {
        // SQLite (the test database) has no ON UPDATE; there the model has to be relied on instead
        $onUpdate = $this->db->DBDriver === 'MySQLi' ? ' ON UPDATE CURRENT_TIMESTAMP' : '';
        $fkName   = fn (string $name): string => $this->db->DBDriver === 'MySQLi' ? $name : ''; // nor named foreign keys

        $this->forge->addField([
            'id'               => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'business_type_id' => ['type' => 'INT', 'unsigned' => true],
            'language_code'    => ['type' => 'VARCHAR', 'constraint' => 5], // BCP 47: en-US, th
            'type_name'        => ['type' => 'VARCHAR', 'constraint' => 255],
            // maintained by the database, not the model
            'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP' . $onUpdate,
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['business_type_id', 'language_code']);
        $this->forge->addUniqueKey(['language_code', 'type_name']); // no two types share a name in one language
        $this->forge->addForeignKey('business_type_id', 'business_type', 'id', 'CASCADE', 'CASCADE', $fkName('fk_business_type_translation_type'));
        $this->forge->createTable('business_type_translation', true); // InnoDB is MySQL's default engine; no ENGINE option so the SQLite test database can run this too
    }

    public function down()
    {
        $this->forge->dropTable('business_type_translation', true);
    }
}
