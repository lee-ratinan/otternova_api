<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Creates the business_website_featured table: the artifacts a business picks for the sections of its main page,
 * section B "best sellers" and T "trending now", at most three each and both optional. (The "highest rated" section
 * needs no picking: it is the business's active artifacts by artifact_master.rating_average.)
 * sort_order is the place in the section, 1 to 3, which the model checks; the unique key on it then keeps a section
 * at three. The foreign key on (business_id, artifact_id) keeps the artifact in the same business. Artifacts are never
 * deleted; one that is no longer active is left out of the page, not removed from here.
 * Depends on business_website and artifact_master.
 */
class CreateBusinessWebsiteFeaturedTable extends Migration
{
    public function up()
    {
        $mysql = $this->db->DBDriver === 'MySQLi';
        // SQLite (the test database) has no ON UPDATE; there the model has to be relied on instead
        $onUpdate = $mysql ? ' ON UPDATE CURRENT_TIMESTAMP' : '';
        $fkName   = static fn (string $name): string => $mysql ? $name : ''; // nor named foreign keys

        $this->forge->addField([
            'id'          => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'business_id' => ['type' => 'INT', 'unsigned' => true],
            'artifact_id' => ['type' => 'INT', 'unsigned' => true],
            'section'     => ['type' => 'ENUM', 'constraint' => ['B', 'T']], // best sellers, trending now
            'sort_order'  => ['type' => 'TINYINT', 'unsigned' => true], // 1-3
            // maintained by the database, not the model
            'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP' . $onUpdate,
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['business_id', 'section', 'sort_order']);
        $this->forge->addUniqueKey(['business_id', 'section', 'artifact_id']); // an artifact once per section
        $this->forge->addKey(['business_id', 'artifact_id']);
        $this->forge->addForeignKey('business_id', 'business_website', 'business_id', 'CASCADE', 'CASCADE', $fkName('fk_business_website_featured_website'));
        $this->forge->addForeignKey(['business_id', 'artifact_id'], 'artifact_master', ['business_id', 'id'], 'CASCADE', 'RESTRICT', $fkName('fk_business_website_featured_artifact'));
        $this->forge->createTable('business_website_featured', true); // InnoDB is MySQL's default engine; no ENGINE option so the SQLite test database can run this too
    }

    public function down()
    {
        $this->forge->dropTable('business_website_featured', true);
    }
}
