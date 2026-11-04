<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Creates the business_store table: a branch of a business, physical (P, such as "Bangkok" or "Pattaya") or online
 * (O, such as "Online Store"). Only a physical store has an address; its subdivision is one of
 * listSupportedSubdivisions(business_master.country_code), which the model checks, as the database cannot.
 * public_id is a NanoID (see PublicIdTrait); on MySQL it is ascii_bin, as NanoIDs tell upper from lower case.
 * Depends on business_master.
 */
class CreateBusinessStoreTable extends Migration
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
            'store_name'             => ['type' => 'VARCHAR', 'constraint' => 255],
            'store_type'             => ['type' => 'ENUM', 'constraint' => ['P', 'O'], 'default' => 'P'], // physical, online
            // physical stores only; NULL for an online store
            'store_address_line_1'   => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'store_address_line_2'   => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'store_city'             => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => true],
            'store_subdivision_code' => ['type' => 'VARCHAR', 'constraint' => 8, 'null' => true], // listSupportedSubdivisions(business_master.country_code)
            'store_postal_code'      => ['type' => 'VARCHAR', 'constraint' => 16, 'null' => true],
            // maintained by the database, not the model
            'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP' . $onUpdate,
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('public_id', 'uq_business_store_public_id'); // named after the column, see PublicIdTrait
        $this->forge->addUniqueKey(['business_id', 'store_name']); // one name per store within a business
        $this->forge->addForeignKey('business_id', 'business_master', 'id', 'CASCADE', 'RESTRICT', $fkName('fk_business_store_business'));
        $this->forge->createTable('business_store', true); // InnoDB is MySQL's default engine; no ENGINE option so the SQLite test database can run this too

        // the store's name in other languages of listLanguages(); optional, business_store.store_name is shown where a language has none
        $this->forge->addField([
            'id'            => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'store_id'      => ['type' => 'INT', 'unsigned' => true],
            'language_code' => ['type' => 'VARCHAR', 'constraint' => 5], // BCP 47: en-US, th
            'store_name'    => ['type' => 'VARCHAR', 'constraint' => 255],
            'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP' . $onUpdate,
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['store_id', 'language_code']);
        $this->forge->addForeignKey('store_id', 'business_store', 'id', 'CASCADE', 'CASCADE', $fkName('fk_business_store_translation_store'));
        $this->forge->createTable('business_store_translation', true);
    }

    public function down()
    {
        $this->forge->dropTable('business_store_translation', true);
        $this->forge->dropTable('business_store', true);
    }
}
