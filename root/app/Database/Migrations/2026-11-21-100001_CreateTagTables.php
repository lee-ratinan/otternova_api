<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Creates the tag tables, for searching businesses by what they offer:
 * - tag_master: the tags every business shares, in whatever language they were written ("Thai massage",
 *   "นวดแผนโบราณ"). There is no translation table: a tag in another language is another tag, as people from different
 *   places search with different words. tag_name is the tag as first written, shown as it is; tag_key is the same
 *   normalised (TagMasterModel::keyOf(): NFC, case folded, single spaces), unique, so "thai  Massage" finds the tag
 *   "Thai Massage" instead of making another. On MySQL tag_key is utf8mb4_bin: the database's utf8mb4_general_ci would
 *   take some distinct Thai spellings for the same, and the model has folded the case already.
 * - business_tag: the tags a business gives itself, each once.
 * public_id is a NanoID (see PublicIdTrait); on MySQL it is ascii_bin, as NanoIDs tell upper from lower case.
 * Depends on business_master.
 */
class CreateTagTables extends Migration
{
    public function up()
    {
        $mysql = $this->db->DBDriver === 'MySQLi';
        // SQLite (the test database) has no ON UPDATE; there the model has to be relied on instead
        $onUpdate = $mysql ? ' ON UPDATE CURRENT_TIMESTAMP' : '';
        $fk       = static fn (string $name): string => $mysql ? $name : ''; // nor named foreign keys

        $this->forge->addField(['id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true]]);
        $this->forge->addField($mysql
            ? ['public_id CHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL']
            : ['public_id' => ['type' => 'CHAR', 'constraint' => 8]]);
        $this->forge->addField(['tag_name' => ['type' => 'VARCHAR', 'constraint' => 64]]);
        $this->forge->addField($mysql
            ? ['tag_key VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL']
            : ['tag_key' => ['type' => 'VARCHAR', 'constraint' => 64]]);
        $this->forge->addField([
            // maintained by the database, not the model
            'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP' . $onUpdate,
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('public_id', 'uq_tag_master_public_id'); // named after the column, see PublicIdTrait
        $this->forge->addUniqueKey('tag_key', 'uq_tag_master_tag_key');
        $this->forge->createTable('tag_master', true); // no ENGINE option so the SQLite test database can run this too

        $this->forge->addField([
            'business_id' => ['type' => 'INT', 'unsigned' => true],
            'tag_id'      => ['type' => 'INT', 'unsigned' => true],
            'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        ]);
        $this->forge->addPrimaryKey(['business_id', 'tag_id']);
        $this->forge->addKey('tag_id', false, false, 'tag_id'); // the businesses of a tag, for the search
        $this->forge->addForeignKey('business_id', 'business_master', 'id', 'CASCADE', 'CASCADE', $fk('fk_business_tag_business'));
        $this->forge->addForeignKey('tag_id', 'tag_master', 'id', 'CASCADE', 'CASCADE', $fk('fk_business_tag_tag'));
        $this->forge->createTable('business_tag', true);
    }

    public function down()
    {
        $this->forge->dropTable('business_tag', true);
        $this->forge->dropTable('tag_master', true);
    }
}
