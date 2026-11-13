<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Creates the orders customers place on the mart (App\Models\MartOrderModel):
 *
 * order_master, one order:
 * - access_key: the SHA-256 (hex) of the key the customer's browser was given with the order, which it shows the
 *   order with; the key itself is never stored.
 * - order_status P: placed, waiting for its payment; C: confirmed (paid); X: cancelled.
 * - the customer's name, email address and telephone number as typed for this order; customer_id links them to
 *   customer_master by the email address.
 * - shipping_*: how the physical products get to the customer, NULL when there are none. For H the address columns,
 *   for L pickup_point (the locker or pickup point as the customer typed it), for S pickup_store_id.
 * - payment_*: the method chosen, its code, handler and name as they were when the order was placed.
 * - amounts in currency_code, fixed when the order is placed: subtotal (of the lines' unit prices), discount_amount,
 *   shipping_fee, tax_included (in the prices of lines whose tax is included), tax_added (added to lines whose tax is
 *   excluded), total_amount = subtotal - discount_amount + shipping_fee + tax_added.
 *
 * order_line, a product, an appointment or seats of a group session:
 * - line_kind P: a product, taken out of store_id's stock; A: an appointment at store_id, held by its own
 *   service_session (session_id); G: quantity seats of the group session session_id at store_id.
 * - artifact_name, variant_label and sku as they were. starts_at and ends_at (UTC) for A and G.
 * - discount_amount is the line's share of the order's discount; tax_amount its tax, included or added as
 *   tax_calculation says; line_total = unit_price x quantity - discount_amount (+ tax_amount when E).
 *
 * order_line_participant: the people an appointment or seats are for, one per seat.
 *
 * public_id is a NanoID (see PublicIdTrait); on MySQL it is ascii_bin, as NanoIDs tell upper from lower case.
 * Depends on business_master, business_store, customer_master, artifact_master, artifact_variant, service_session,
 * business_shipping_method, business_payment_method and business_discount.
 */
class CreateOrderTables extends Migration
{
    public function up()
    {
        $mysql    = $this->db->DBDriver === 'MySQLi';
        $onUpdate = $mysql ? ' ON UPDATE CURRENT_TIMESTAMP' : '';
        $fkName   = static fn (string $name): string => $mysql ? $name : '';
        $money    = static fn (bool $null = false): array => ['type' => 'DECIMAL', 'constraint' => '19,2'] + ($null ? ['null' => true] : ['default' => '0.00']);

        $this->forge->addField(['id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true]]);
        $this->forge->addField($mysql
            ? ['public_id CHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL', 'access_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL']
            : ['public_id' => ['type' => 'CHAR', 'constraint' => 12], 'access_key' => ['type' => 'CHAR', 'constraint' => 64]]);
        $this->forge->addField([
            'business_id'          => ['type' => 'INT', 'unsigned' => true],
            'customer_id'          => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'order_status'         => ['type' => 'ENUM', 'constraint' => ['P', 'C', 'X'], 'default' => 'P'],
            'language_code'        => ['type' => 'VARCHAR', 'constraint' => 16],
            'currency_code'        => ['type' => 'CHAR', 'constraint' => 3],
            'first_name'           => ['type' => 'VARCHAR', 'constraint' => 100],
            'last_name'            => ['type' => 'VARCHAR', 'constraint' => 100],
            'email_address'        => ['type' => 'VARCHAR', 'constraint' => 128],
            'telephone_number'     => ['type' => 'VARCHAR', 'constraint' => 24],
            'shipping_method_id'   => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'shipping_method_type' => ['type' => 'ENUM', 'constraint' => ['H', 'L', 'S'], 'null' => true],
            'shipping_method_name' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'address_line_1'       => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'address_line_2'       => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'city'                 => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => true],
            'subdivision_code'     => ['type' => 'VARCHAR', 'constraint' => 8, 'null' => true],
            'postal_code'          => ['type' => 'VARCHAR', 'constraint' => 16, 'null' => true],
            'country_code'         => ['type' => 'CHAR', 'constraint' => 2, 'null' => true],
            'pickup_point'         => ['type' => 'TEXT', 'null' => true],
            'pickup_store_id'      => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'payment_method_id'    => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'payment_method_code'  => ['type' => 'VARCHAR', 'constraint' => 30],
            'payment_handler_code' => ['type' => 'VARCHAR', 'constraint' => 30],
            'payment_method_name'  => ['type' => 'VARCHAR', 'constraint' => 100],
            'discount_id'          => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'discount_code'        => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true],
            'subtotal'             => $money(),
            'discount_amount'      => $money(),
            'shipping_fee'         => $money(),
            'tax_included'         => $money(),
            'tax_added'            => $money(),
            'total_amount'         => $money(),
            'paid_at'              => ['type' => 'DATETIME', 'null' => true],
            'cancelled_at'         => ['type' => 'DATETIME', 'null' => true],
            'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP' . $onUpdate,
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('public_id', 'uq_order_master_public_id'); // named after the column, see PublicIdTrait
        $this->forge->addKey(['business_id', 'order_status', 'created_at']);
        $this->forge->addKey('customer_id');
        $this->forge->addForeignKey('business_id', 'business_master', 'id', 'CASCADE', 'RESTRICT', $fkName('fk_order_master_business'));
        $this->forge->addForeignKey('customer_id', 'customer_master', 'id', 'CASCADE', 'SET NULL', $fkName('fk_order_master_customer'));
        $this->forge->addForeignKey('shipping_method_id', 'business_shipping_method', 'id', 'CASCADE', 'SET NULL', $fkName('fk_order_master_shipping_method'));
        $this->forge->addForeignKey('pickup_store_id', 'business_store', 'id', 'CASCADE', 'SET NULL', $fkName('fk_order_master_pickup_store'));
        $this->forge->addForeignKey('payment_method_id', 'business_payment_method', 'id', 'CASCADE', 'SET NULL', $fkName('fk_order_master_payment_method'));
        $this->forge->addForeignKey('discount_id', 'business_discount', 'id', 'CASCADE', 'SET NULL', $fkName('fk_order_master_discount'));
        $this->forge->createTable('order_master', true);

        $this->forge->addField([
            'id'              => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'order_id'        => ['type' => 'INT', 'unsigned' => true],
            'line_number'     => ['type' => 'SMALLINT', 'unsigned' => true],
            'line_kind'       => ['type' => 'ENUM', 'constraint' => ['P', 'A', 'G']], // product, appointment, group session
            'artifact_id'     => ['type' => 'INT', 'unsigned' => true],
            'variant_id'      => ['type' => 'INT', 'unsigned' => true],
            'artifact_name'   => ['type' => 'VARCHAR', 'constraint' => 255],
            'variant_label'   => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'sku'             => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'store_id'        => ['type' => 'INT', 'unsigned' => true],
            'session_id'      => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'starts_at'       => ['type' => 'DATETIME', 'null' => true],
            'ends_at'         => ['type' => 'DATETIME', 'null' => true],
            'unit_price'      => $money(),
            'compare_price'   => $money(true),
            'quantity'        => ['type' => 'SMALLINT', 'unsigned' => true],
            'discount_amount' => $money(),
            'tax_calculation' => ['type' => 'ENUM', 'constraint' => ['I', 'E', 'X']],
            'tax_percentage'  => ['type' => 'DECIMAL', 'constraint' => '5,2', 'null' => true],
            'tax_amount'      => $money(),
            'line_total'      => $money(),
            'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP' . $onUpdate,
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['order_id', 'line_number']);
        $this->forge->addKey('variant_id');
        $this->forge->addKey('session_id');
        $this->forge->addForeignKey('order_id', 'order_master', 'id', 'CASCADE', 'CASCADE', $fkName('fk_order_line_order'));
        $this->forge->addForeignKey('artifact_id', 'artifact_master', 'id', 'CASCADE', 'RESTRICT', $fkName('fk_order_line_artifact'));
        $this->forge->addForeignKey('variant_id', 'artifact_variant', 'id', 'CASCADE', 'RESTRICT', $fkName('fk_order_line_variant'));
        $this->forge->addForeignKey('store_id', 'business_store', 'id', 'CASCADE', 'RESTRICT', $fkName('fk_order_line_store'));
        $this->forge->addForeignKey('session_id', 'service_session', 'id', 'CASCADE', 'SET NULL', $fkName('fk_order_line_session'));
        $this->forge->createTable('order_line', true);

        $this->forge->addField([
            'id'               => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'line_id'          => ['type' => 'INT', 'unsigned' => true],
            'seat_number'      => ['type' => 'SMALLINT', 'unsigned' => true],
            'participant_name' => ['type' => 'VARCHAR', 'constraint' => 100],
            'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP' . $onUpdate,
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['line_id', 'seat_number']);
        $this->forge->addForeignKey('line_id', 'order_line', 'id', 'CASCADE', 'CASCADE', $fkName('fk_order_line_participant_line'));
        $this->forge->createTable('order_line_participant', true);
    }

    public function down()
    {
        $this->forge->dropTable('order_line_participant', true);
        $this->forge->dropTable('order_line', true);
        $this->forge->dropTable('order_master', true);
    }
}
