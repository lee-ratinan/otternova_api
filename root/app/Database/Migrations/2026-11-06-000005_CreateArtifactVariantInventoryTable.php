<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Creates the artifact_variant_inventory table: how many of a physical product's variant one store holds. One row
 * per variant per store, addressed by that pair, so no public_id. Only for artifact_type P, and only for a store of
 * the variant's business; the model checks both, as the database cannot.
 * quantity is signed, so stock sold beyond what was counted shows as negative instead of failing the sale. Change it
 * in one statement (quantity = quantity + ?) so concurrent sales do not overwrite each other; each movement is kept
 * in log_activity, not in a table of its own.
 * Depends on artifact_variant and business_store.
 */
class CreateArtifactVariantInventoryTable extends Migration
{
    public function up()
    {
        $mysql = $this->db->DBDriver === 'MySQLi';
        // SQLite (the test database) has no ON UPDATE; there the model has to be relied on instead
        $onUpdate = $mysql ? ' ON UPDATE CURRENT_TIMESTAMP' : '';
        $fkName   = static fn (string $name): string => $mysql ? $name : ''; // nor named foreign keys

        $this->forge->addField([
            'id'         => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'variant_id' => ['type' => 'INT', 'unsigned' => true],
            'store_id'   => ['type' => 'INT', 'unsigned' => true],
            'quantity'   => ['type' => 'INT', 'default' => 0],
            // maintained by the database, not the model
            'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP' . $onUpdate,
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['variant_id', 'store_id']);
        $this->forge->addKey('store_id'); // a store's stock
        $this->forge->addForeignKey('variant_id', 'artifact_variant', 'id', 'CASCADE', 'RESTRICT', $fkName('fk_artifact_variant_inventory_variant'));
        $this->forge->addForeignKey('store_id', 'business_store', 'id', 'CASCADE', 'RESTRICT', $fkName('fk_artifact_variant_inventory_store'));
        $this->forge->createTable('artifact_variant_inventory', true); // InnoDB is MySQL's default engine; no ENGINE option so the SQLite test database can run this too
    }

    public function down()
    {
        $this->forge->dropTable('artifact_variant_inventory', true);
    }
}
