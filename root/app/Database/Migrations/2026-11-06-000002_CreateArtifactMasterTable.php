<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Creates the artifact_master table: a product (artifact_type P, physical, sold from stock) or a service (S) of a
 * business. Artifacts are never deleted; artifact_status I hides one for now and D retires it for good.
 * What a customer buys is always an artifact_variant: an artifact with no options has one variant with no attribute
 * values. The prices are the variants'; price_active_lowest is the cheapest active variant's price_active, and
 * price_compare_lowest that same variant's price_compare, both kept up to date by the model whenever a variant is
 * saved. Prices are in business_master.currency_code, which never changes.
 * tax_calculation I: the price includes tax_percentage already; E: tax_percentage is added on top; X: no tax, and
 * tax_percentage is NULL. It applies to every variant.
 * rating_sum, rating_count and rating_average cover the approved artifact_review rows only, and are kept up to date
 * by the model as reviews are approved or hidden; rating_average = rating_sum / rating_count, rounded, 0 when none.
 * category_id must be a category of the same business_id, which the model checks, as the database cannot.
 * public_id is a NanoID (see PublicIdTrait); on MySQL it is ascii_bin, as NanoIDs tell upper from lower case.
 * Depends on business_master and artifact_category.
 */
class CreateArtifactMasterTable extends Migration
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
            'business_id'          => ['type' => 'INT', 'unsigned' => true],
            'category_id'          => ['type' => 'INT', 'unsigned' => true, 'null' => true], // NULL = uncategorised
            'artifact_name'        => ['type' => 'VARCHAR', 'constraint' => 255],
            'artifact_description' => ['type' => 'TEXT', 'null' => true],
            'artifact_type'        => ['type' => 'ENUM', 'constraint' => ['P', 'S']], // physical product, service; fixed once created
            'artifact_status'      => ['type' => 'ENUM', 'constraint' => ['A', 'I', 'D'], 'default' => 'I'], // active, inactive, discontinued
            'artifact_label'       => ['type' => 'ENUM', 'constraint' => ['N', 'P', 'R', 'L'], 'null' => true], // new, popular, recommended, limited
            'price_active_lowest'  => ['type' => 'DECIMAL', 'constraint' => '19,2', 'default' => '0.00'],
            'price_compare_lowest' => ['type' => 'DECIMAL', 'constraint' => '19,2', 'null' => true],
            'tax_calculation'      => ['type' => 'ENUM', 'constraint' => ['I', 'E', 'X']], // inclusive, exclusive, no tax
            'tax_percentage'       => ['type' => 'DECIMAL', 'constraint' => '5,2', 'null' => true], // 7.00 for 7%; NULL when X
            'rating_sum'           => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'rating_count'         => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'rating_average'       => ['type' => 'DECIMAL', 'constraint' => '3,2', 'default' => '0.00'], // 0.00-5.00
            // maintained by the database, not the model
            'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP' . $onUpdate,
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('public_id', 'uq_artifact_master_public_id'); // named after the column, see PublicIdTrait
        $this->forge->addUniqueKey(['business_id', 'id']); // target of artifact_variant's foreign key, which keeps a variant in its artifact's business
        $this->forge->addKey(['business_id', 'artifact_status']); // the listing of a business
        $this->forge->addKey('category_id');
        $this->forge->addForeignKey('business_id', 'business_master', 'id', 'CASCADE', 'RESTRICT', $fkName('fk_artifact_master_business'));
        $this->forge->addForeignKey('category_id', 'artifact_category', 'id', 'CASCADE', 'RESTRICT', $fkName('fk_artifact_master_category'));
        $this->forge->createTable('artifact_master', true); // InnoDB is MySQL's default engine; no ENGINE option so the SQLite test database can run this too

        // the artifact's name and description in other languages of listLanguages(); optional, artifact_master's are shown where a language has none
        $this->forge->addField([
            'id'                   => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'artifact_id'          => ['type' => 'INT', 'unsigned' => true],
            'language_code'        => ['type' => 'VARCHAR', 'constraint' => 5], // BCP 47: en-US, th
            'artifact_name'        => ['type' => 'VARCHAR', 'constraint' => 255],
            'artifact_description' => ['type' => 'TEXT', 'null' => true],
            'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP' . $onUpdate,
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['artifact_id', 'language_code']);
        $this->forge->addForeignKey('artifact_id', 'artifact_master', 'id', 'CASCADE', 'CASCADE', $fkName('fk_artifact_master_translation_artifact'));
        $this->forge->createTable('artifact_master_translation', true);
    }

    public function down()
    {
        $this->forge->dropTable('artifact_master_translation', true);
        $this->forge->dropTable('artifact_master', true);
    }
}
