<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Creates the artifact_variant table, what a customer actually buys: one combination of attribute values of an
 * artifact (Blue / L), with its own price. Every artifact has at least one; one with no options has a single variant
 * with no attribute values. Variants are never deleted; variant_status I takes one off sale.
 * business_id is artifact_master's, copied so sku can be unique within the business; the foreign key on
 * (business_id, artifact_id) keeps the copy right. sku is optional (NULLs do not clash).
 * price_compare, when set, is above price_active, which the model checks. After any change to a variant the model
 * updates artifact_master.price_active_lowest and price_compare_lowest.
 * barcode and weight_grams are for physical products, duration_minutes for services.
 *
 * Also creates artifact_variant_attribute_value, which gives a variant its attribute values. attribute_id is the
 * value's, copied so a variant has at most one value per attribute (not both Red and Blue); the foreign key on
 * (attribute_id, attribute_value_id) keeps the copy right. The model checks what the database cannot: every variant
 * of an artifact uses the same attributes, no two of them have the same values, and the attribute is the business's.
 *
 * public_id is a NanoID (see PublicIdTrait); on MySQL it is ascii_bin, as NanoIDs tell upper from lower case.
 * Depends on business_master, artifact_master and the artifact_attribute tables.
 */
class CreateArtifactVariantTables extends Migration
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
            'business_id'      => ['type' => 'INT', 'unsigned' => true], // artifact_master.business_id
            'artifact_id'      => ['type' => 'INT', 'unsigned' => true],
            'sku'              => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'barcode'          => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true], // physical only
            'price_active'     => ['type' => 'DECIMAL', 'constraint' => '19,2'], // what the customer pays
            'price_compare'    => ['type' => 'DECIMAL', 'constraint' => '19,2', 'null' => true], // the higher price shown struck through
            'variant_status'   => ['type' => 'ENUM', 'constraint' => ['A', 'I'], 'default' => 'A'], // active, inactive
            'weight_grams'     => ['type' => 'INT', 'unsigned' => true, 'null' => true], // physical only
            'duration_minutes' => ['type' => 'SMALLINT', 'unsigned' => true, 'null' => true], // services only
            'sort_order'       => ['type' => 'SMALLINT', 'unsigned' => true, 'default' => 0],
            // maintained by the database, not the model
            'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP' . $onUpdate,
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('public_id', 'uq_artifact_variant_public_id'); // named after the column, see PublicIdTrait
        $this->forge->addUniqueKey(['business_id', 'sku']);
        $this->forge->addUniqueKey(['artifact_id', 'id']); // target of artifact_image's foreign key, which keeps an image's variant in its artifact
        $this->forge->addKey(['business_id', 'artifact_id']);
        $this->forge->addForeignKey(['business_id', 'artifact_id'], 'artifact_master', ['business_id', 'id'], 'CASCADE', 'RESTRICT', $fkName('fk_artifact_variant_artifact'));
        $this->forge->createTable('artifact_variant', true); // InnoDB is MySQL's default engine; no ENGINE option so the SQLite test database can run this too

        $this->forge->addField([
            'id'                 => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'variant_id'         => ['type' => 'INT', 'unsigned' => true],
            'attribute_id'       => ['type' => 'INT', 'unsigned' => true], // artifact_attribute_value.attribute_id
            'attribute_value_id' => ['type' => 'INT', 'unsigned' => true],
            'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP' . $onUpdate,
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['variant_id', 'attribute_id']); // one value per attribute
        $this->forge->addKey(['attribute_id', 'attribute_value_id']);
        $this->forge->addForeignKey('variant_id', 'artifact_variant', 'id', 'CASCADE', 'RESTRICT', $fkName('fk_artifact_variant_attribute_value_variant'));
        $this->forge->addForeignKey(['attribute_id', 'attribute_value_id'], 'artifact_attribute_value', ['attribute_id', 'id'], 'CASCADE', 'RESTRICT', $fkName('fk_artifact_variant_attribute_value_value'));
        $this->forge->createTable('artifact_variant_attribute_value', true);
    }

    public function down()
    {
        $this->forge->dropTable('artifact_variant_attribute_value', true);
        $this->forge->dropTable('artifact_variant', true);
    }
}
