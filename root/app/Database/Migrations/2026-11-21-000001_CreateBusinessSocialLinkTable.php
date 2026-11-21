<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Creates the business_social_link table: a business's social media accounts, one row per platform. platform is a key
 * of listSocialMediaKeys(), which the model checks, as the database cannot; a VARCHAR rather than an ENUM so a new
 * platform needs no migration. profile_url is the full, normalised https URL of the account, as not every platform
 * turns a handle into a URL the same way (LINE short links, Facebook profile.php?id=...). sort_order is the order the
 * links are shown in.
 * public_id is a NanoID (see PublicIdTrait); on MySQL it is ascii_bin, as NanoIDs tell upper from lower case.
 * Depends on business_master.
 */
class CreateBusinessSocialLinkTable extends Migration
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
        $this->forge->addField([
            'business_id' => ['type' => 'INT', 'unsigned' => true],
            'platform'    => ['type' => 'VARCHAR', 'constraint' => 16], // listSocialMediaKeys()
            'profile_url' => ['type' => 'VARCHAR', 'constraint' => 255],
            'sort_order'  => ['type' => 'SMALLINT', 'unsigned' => true, 'default' => 0],
            // maintained by the database, not the model
            'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP' . $onUpdate,
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('public_id', 'uq_business_social_link_public_id'); // named after the column, see PublicIdTrait
        $this->forge->addUniqueKey(['business_id', 'platform'], 'uq_business_social_link_business_platform'); // one account per platform
        $this->forge->addKey(['business_id', 'sort_order'], false, false, 'business_id_sort_order');
        $this->forge->addForeignKey('business_id', 'business_master', 'id', 'CASCADE', 'CASCADE', $fk('fk_business_social_link_business'));
        $this->forge->createTable('business_social_link', true); // no ENGINE option so the SQLite test database can run this too
    }

    public function down()
    {
        $this->forge->dropTable('business_social_link', true);
    }
}
