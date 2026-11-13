<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Creates business_shipping_method: the ways a customer of a business can get a physical product (artifact_type P).
 * Its own table, not columns of business_master, as a business may offer several of one kind (standard and express
 * delivery, pickup at one store but not another), and each needs its own name, translations and fees.
 * - method_type H: delivered to the customer's address; L: delivered to a locker or pickup point the customer picks
 *   (the order keeps which one); S: picked up at a store of the business.
 * - store_id NULL: offered at every store of the business; else at that store only (for S, the store picked up at).
 * - method_name and description are in the business's default language; business_shipping_method_translation has
 *   them in other languages of listLanguages(), optional, the parent's shown where a language has none.
 * - fee_basis says what business_shipping_rate's ranges are measured in: P the order's subtotal of physical
 *   products, in business_master.currency_code; W their total weight_grams (artifact_variant) times quantity.
 * - free_over_amount, when set, makes the fee 0 once the subtotal reaches it, whatever fee_basis is, so a business can
 *   charge by weight and still deliver orders of 1,000.00 or more for free. NULL = never free by amount.
 * - eta_days_min and eta_days_max are only shown to the customer (2-3 days); NULL = not shown.
 *
 * Also creates business_shipping_rate, the fee brackets of a method:
 * - range_from inclusive, range_to exclusive, NULL = no upper bound; in fee_basis's unit. Brackets of a method may
 *   not overlap, which the model checks, as the database cannot.
 * - the fee is fee_amount, plus step_fee for every step_size or part of it above range_from when both are set.
 *   "50.00 up to 1 kg, then 15.00 per kg or part of one" (W) is two brackets: 0-1000 fee_amount 50.00; and
 *   1000-NULL fee_amount 50.00, step_size 1000, step_fee 15.00, so 2,300 g costs 50.00 + 2 x 15.00 = 80.00.
 * - a method with no brackets is free (store pickup); one whose brackets do not take the order's subtotal or weight
 *   is not offered for that order (too heavy for it).
 *
 * public_id is a NanoID (see PublicIdTrait); on MySQL it is ascii_bin, as NanoIDs tell upper from lower case.
 * Depends on business_master and business_store.
 */
class CreateBusinessShippingMethodTables extends Migration
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
            'business_id'      => ['type' => 'INT', 'unsigned' => true],
            'store_id'         => ['type' => 'INT', 'unsigned' => true, 'null' => true], // NULL = every store of the business
            'method_type'      => ['type' => 'ENUM', 'constraint' => ['H', 'L', 'S']], // home delivery, locker or pickup point, store pickup
            'method_name'      => ['type' => 'VARCHAR', 'constraint' => 100],
            'description'      => ['type' => 'TEXT', 'null' => true],
            'fee_basis'        => ['type' => 'ENUM', 'constraint' => ['P', 'W'], 'default' => 'P'], // subtotal, weight
            'free_over_amount' => ['type' => 'DECIMAL', 'constraint' => '19,2', 'null' => true], // in business_master.currency_code
            'eta_days_min'     => ['type' => 'TINYINT', 'unsigned' => true, 'null' => true],
            'eta_days_max'     => ['type' => 'TINYINT', 'unsigned' => true, 'null' => true],
            'is_active'        => ['type' => 'ENUM', 'constraint' => ['Y', 'N'], 'default' => 'Y'],
            'sort_order'       => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 0],
            // maintained by the database, not the model
            'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP' . $onUpdate,
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('public_id', 'uq_business_shipping_method_public_id'); // named after the column, see PublicIdTrait
        $this->forge->addKey(['business_id', 'store_id', 'sort_order']);
        $this->forge->addForeignKey('business_id', 'business_master', 'id', 'CASCADE', 'RESTRICT', $fkName('fk_business_shipping_method_business'));
        $this->forge->addForeignKey('store_id', 'business_store', 'id', 'CASCADE', 'RESTRICT', $fkName('fk_business_shipping_method_store'));
        $this->forge->createTable('business_shipping_method', true);

        $this->forge->addField([
            'id'                 => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'shipping_method_id' => ['type' => 'INT', 'unsigned' => true],
            'language_code'      => ['type' => 'VARCHAR', 'constraint' => 16], // BCP 47: en-US, th
            'method_name'        => ['type' => 'VARCHAR', 'constraint' => 100],
            'description'        => ['type' => 'TEXT', 'null' => true],
            'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP' . $onUpdate,
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['shipping_method_id', 'language_code']);
        $this->forge->addForeignKey('shipping_method_id', 'business_shipping_method', 'id', 'CASCADE', 'CASCADE', $fkName('fk_business_shipping_method_translation_method'));
        $this->forge->createTable('business_shipping_method_translation', true);

        $this->forge->addField([
            'id'                 => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'shipping_method_id' => ['type' => 'INT', 'unsigned' => true],
            'range_from'         => ['type' => 'DECIMAL', 'constraint' => '19,2', 'default' => '0.00'], // inclusive; amount or grams
            'range_to'           => ['type' => 'DECIMAL', 'constraint' => '19,2', 'null' => true], // exclusive; NULL = no upper bound
            'fee_amount'         => ['type' => 'DECIMAL', 'constraint' => '19,2', 'default' => '0.00'],
            'step_size'          => ['type' => 'DECIMAL', 'constraint' => '19,2', 'null' => true], // with step_fee, or neither
            'step_fee'           => ['type' => 'DECIMAL', 'constraint' => '19,2', 'null' => true],
            'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP' . $onUpdate,
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['shipping_method_id', 'range_from']); // also the lookup: the bracket with the highest range_from <= value
        $this->forge->addForeignKey('shipping_method_id', 'business_shipping_method', 'id', 'CASCADE', 'CASCADE', $fkName('fk_business_shipping_rate_method'));
        $this->forge->createTable('business_shipping_rate', true);
    }

    public function down()
    {
        $this->forge->dropTable('business_shipping_rate', true);
        $this->forge->dropTable('business_shipping_method_translation', true);
        $this->forge->dropTable('business_shipping_method', true);
    }
}
