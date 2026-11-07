<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Creates the business_store_client_link table: which stores of a business each of its people (business_client_link)
 * works in. One row per store per person; a person may work in several stores of the same business, as some kinds of
 * establishment share staff between branches.
 * business_id is repeated here so that the database itself can check that the store and the person belong to the same
 * business: (business_id, client_id) must be a business_client_link, and (business_id, store_id) a business_store of
 * that business, for which business_store gets a unique key on (business_id, id).
 * A row goes with its store or its business_client_link; log_activity keeps the history.
 * Depends on business_store and business_client_link.
 */
class CreateBusinessStoreClientLinkTable extends Migration
{
    public function up()
    {
        $fkName = fn (string $name): string => $this->db->DBDriver === 'MySQLi' ? $name : ''; // SQLite has no named foreign keys

        // the target of the composite foreign key below; id alone is unique already, so this never rejects a row
        $this->forge->addUniqueKey(['business_id', 'id'], 'uq_business_store_business_id_id');
        $this->forge->processIndexes('business_store');

        $this->forge->addField([
            'id'          => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'business_id' => ['type' => 'INT', 'unsigned' => true],
            'store_id'    => ['type' => 'INT', 'unsigned' => true],
            'client_id'   => ['type' => 'INT', 'unsigned' => true],
            // maintained by the database, not the model
            'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        ]);
        $this->forge->addPrimaryKey('id');
        // one row per store per person; it also serves the store's foreign key and "who works in this store"
        $this->forge->addUniqueKey(['business_id', 'store_id', 'client_id']);
        // serves the person's foreign key and "which stores does this person work in"
        $this->forge->addKey(['business_id', 'client_id']);
        $this->forge->addForeignKey(['business_id', 'store_id'], 'business_store', ['business_id', 'id'], 'CASCADE', 'CASCADE', $fkName('fk_business_store_client_link_store'));
        $this->forge->addForeignKey(['business_id', 'client_id'], 'business_client_link', ['business_id', 'client_id'], 'CASCADE', 'CASCADE', $fkName('fk_business_store_client_link_client'));
        $this->forge->createTable('business_store_client_link', true); // no ENGINE option so the SQLite test database can run this too
    }

    public function down()
    {
        $this->forge->dropTable('business_store_client_link', true);
        $this->forge->dropKey('business_store', 'uq_business_store_business_id_id', false);
    }
}
