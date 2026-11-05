<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Creates the artifact_attribute table, the options a business's variants differ by (Color, Size, Duration), and
 * artifact_attribute_value, the choices of each (Blue, Red; S, M, L, XL). Both belong to the business, not to one
 * artifact, so "Color: Blue" is defined once and used by any of its artifacts. sort_order puts the values in their
 * natural order (S before M before L), which their names do not.
 * Each has a translation table, optional, like business_store_translation.
 * public_id is a NanoID (see PublicIdTrait); on MySQL it is ascii_bin, as NanoIDs tell upper from lower case.
 * Depends on business_master.
 */
class CreateArtifactAttributeTables extends Migration
{
    public function up()
    {
        $mysql = $this->db->DBDriver === 'MySQLi';
        // SQLite (the test database) has no ON UPDATE; there the model has to be relied on instead
        $onUpdate  = $mysql ? ' ON UPDATE CURRENT_TIMESTAMP' : '';
        $fkName    = static fn (string $name): string => $mysql ? $name : ''; // nor named foreign keys
        $publicId  = $mysql
            ? ['public_id CHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL']
            : ['public_id' => ['type' => 'CHAR', 'constraint' => 12]];
        $timestamps = [
            // maintained by the database, not the model
            'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP' . $onUpdate,
        ];

        $this->forge->addField(['id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true]]);
        $this->forge->addField($publicId);
        $this->forge->addField([
            'business_id'    => ['type' => 'INT', 'unsigned' => true],
            'attribute_name' => ['type' => 'VARCHAR', 'constraint' => 64],
        ]);
        $this->forge->addField($timestamps);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('public_id', 'uq_artifact_attribute_public_id'); // named after the column, see PublicIdTrait
        $this->forge->addUniqueKey(['business_id', 'attribute_name']);
        $this->forge->addForeignKey('business_id', 'business_master', 'id', 'CASCADE', 'RESTRICT', $fkName('fk_artifact_attribute_business'));
        $this->forge->createTable('artifact_attribute', true); // InnoDB is MySQL's default engine; no ENGINE option so the SQLite test database can run this too

        $this->forge->addField([
            'id'             => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'attribute_id'   => ['type' => 'INT', 'unsigned' => true],
            'language_code'  => ['type' => 'VARCHAR', 'constraint' => 5], // BCP 47: en-US, th
            'attribute_name' => ['type' => 'VARCHAR', 'constraint' => 64],
        ]);
        $this->forge->addField($timestamps);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['attribute_id', 'language_code']);
        $this->forge->addForeignKey('attribute_id', 'artifact_attribute', 'id', 'CASCADE', 'CASCADE', $fkName('fk_artifact_attribute_translation_attribute'));
        $this->forge->createTable('artifact_attribute_translation', true);

        $this->forge->addField(['id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true]]);
        $this->forge->addField($publicId);
        $this->forge->addField([
            'attribute_id'    => ['type' => 'INT', 'unsigned' => true],
            'attribute_value' => ['type' => 'VARCHAR', 'constraint' => 128],
            'sort_order'      => ['type' => 'SMALLINT', 'unsigned' => true, 'default' => 0],
        ]);
        $this->forge->addField($timestamps);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('public_id', 'uq_artifact_attribute_value_public_id'); // named after the column, see PublicIdTrait
        $this->forge->addUniqueKey(['attribute_id', 'attribute_value']);
        $this->forge->addUniqueKey(['attribute_id', 'id']); // target of artifact_variant_attribute_value's foreign key, which keeps a value under its attribute
        $this->forge->addForeignKey('attribute_id', 'artifact_attribute', 'id', 'CASCADE', 'RESTRICT', $fkName('fk_artifact_attribute_value_attribute'));
        $this->forge->createTable('artifact_attribute_value', true);

        $this->forge->addField([
            'id'                 => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'attribute_value_id' => ['type' => 'INT', 'unsigned' => true],
            'language_code'      => ['type' => 'VARCHAR', 'constraint' => 5], // BCP 47: en-US, th
            'attribute_value'    => ['type' => 'VARCHAR', 'constraint' => 128],
        ]);
        $this->forge->addField($timestamps);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['attribute_value_id', 'language_code']);
        $this->forge->addForeignKey('attribute_value_id', 'artifact_attribute_value', 'id', 'CASCADE', 'CASCADE', $fkName('fk_artifact_attribute_value_translation_value'));
        $this->forge->createTable('artifact_attribute_value_translation', true);
    }

    public function down()
    {
        $this->forge->dropTable('artifact_attribute_value_translation', true);
        $this->forge->dropTable('artifact_attribute_value', true);
        $this->forge->dropTable('artifact_attribute_translation', true);
        $this->forge->dropTable('artifact_attribute', true);
    }
}
