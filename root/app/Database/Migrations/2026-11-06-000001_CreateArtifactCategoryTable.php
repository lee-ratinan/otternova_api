<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Creates the artifact_category table: a business's own grouping of its artifacts (products and services), such
 * as "Drinks" or "Massages". Categories are never deleted; category_status I hides one instead.
 * public_id is a NanoID (see PublicIdTrait); on MySQL it is ascii_bin, as NanoIDs tell upper from lower case.
 * Depends on business_master.
 */
class CreateArtifactCategoryTable extends Migration
{
    public function up()
    {
        $mysql = $this->db->DBDriver === 'MySQLi';
        // SQLite (the test database) has no ON UPDATE; there the model has to be relied on instead
        $onUpdate = $mysql ? ' ON UPDATE CURRENT_TIMESTAMP' : '';
        $fkName   = static fn (string $name): string => $mysql ? $name : ''; // nor named foreign keys

        $this->forge->addField(['id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true]]);
        $this->forge->addField($mysql
            ? ['public_id CHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL']
            : ['public_id' => ['type' => 'CHAR', 'constraint' => 12]]);
        $this->forge->addField([
            'business_id'     => ['type' => 'INT', 'unsigned' => true],
            'category_name'   => ['type' => 'VARCHAR', 'constraint' => 255],
            'category_status' => ['type' => 'ENUM', 'constraint' => ['A', 'I'], 'default' => 'A'], // active, inactive
            'sort_order'      => ['type' => 'SMALLINT', 'unsigned' => true, 'default' => 0],
            // maintained by the database, not the model
            'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP' . $onUpdate,
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('public_id', 'uq_artifact_category_public_id'); // named after the column, see PublicIdTrait
        $this->forge->addUniqueKey(['business_id', 'category_name']); // one name per category within a business
        $this->forge->addForeignKey('business_id', 'business_master', 'id', 'CASCADE', 'RESTRICT', $fkName('fk_artifact_category_business'));
        $this->forge->createTable('artifact_category', true); // InnoDB is MySQL's default engine; no ENGINE option so the SQLite test database can run this too

        // the category's name in other languages of listLanguages(); optional, artifact_category.category_name is shown where a language has none
        $this->forge->addField([
            'id'            => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'category_id'   => ['type' => 'INT', 'unsigned' => true],
            'language_code' => ['type' => 'VARCHAR', 'constraint' => 5], // BCP 47: en-US, th
            'category_name' => ['type' => 'VARCHAR', 'constraint' => 255],
            'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP' . $onUpdate,
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['category_id', 'language_code']);
        $this->forge->addForeignKey('category_id', 'artifact_category', 'id', 'CASCADE', 'CASCADE', $fkName('fk_artifact_category_translation_category'));
        $this->forge->createTable('artifact_category_translation', true);
    }

    public function down()
    {
        $this->forge->dropTable('artifact_category_translation', true);
        $this->forge->dropTable('artifact_category', true);
    }
}
