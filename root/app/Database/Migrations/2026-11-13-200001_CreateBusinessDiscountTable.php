<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Creates business_discount: the discount codes a business gives its own customers on the mart. Not to be confused
 * with discount_master, Otternova's discounts on its plans.
 * - discount_code is unique within the business, told apart without regard to case (the collation's), and stored in
 *   upper case by whoever writes it.
 * - discount_type P: discount_value percent off; F: discount_value off, in business_master.currency_code, never more
 *   than what it applies to.
 * - applies_to A: every line of the order; P: the physical products only; S: the services only. Shipping fees never.
 * - min_subtotal: the subtotal of the lines it applies to must reach it; NULL = any.
 * - max_redemptions: orders it may be used on; NULL = no limit. redemption_count counts the orders placed with it.
 * - valid_from, expires_at: UTC; NULL = from always, until forever.
 * public_id is a NanoID (see PublicIdTrait); on MySQL it is ascii_bin, as NanoIDs tell upper from lower case.
 * Depends on business_master.
 */
class CreateBusinessDiscountTable extends Migration
{
    public function up()
    {
        $mysql    = $this->db->DBDriver === 'MySQLi';
        $onUpdate = $mysql ? ' ON UPDATE CURRENT_TIMESTAMP' : '';
        $fkName   = static fn (string $name): string => $mysql ? $name : '';

        $this->forge->addField(['id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true]]);
        $this->forge->addField($mysql
            ? ['public_id CHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL']
            : ['public_id' => ['type' => 'CHAR', 'constraint' => 12]]);
        $this->forge->addField([
            'business_id'      => ['type' => 'INT', 'unsigned' => true],
            'discount_code'    => ['type' => 'VARCHAR', 'constraint' => 30],
            'description'      => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'discount_type'    => ['type' => 'ENUM', 'constraint' => ['P', 'F']], // percent, fixed amount
            'discount_value'   => ['type' => 'DECIMAL', 'constraint' => '19,2'],
            'applies_to'       => ['type' => 'ENUM', 'constraint' => ['A', 'P', 'S'], 'default' => 'A'], // all, products, services
            'min_subtotal'     => ['type' => 'DECIMAL', 'constraint' => '19,2', 'null' => true],
            'max_redemptions'  => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'redemption_count' => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'valid_from'       => ['type' => 'DATETIME', 'null' => true],
            'expires_at'       => ['type' => 'DATETIME', 'null' => true],
            'is_active'        => ['type' => 'ENUM', 'constraint' => ['Y', 'N'], 'default' => 'Y'],
            'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP' . $onUpdate,
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('public_id', 'uq_business_discount_public_id'); // named after the column, see PublicIdTrait
        $this->forge->addUniqueKey(['business_id', 'discount_code']);
        $this->forge->addForeignKey('business_id', 'business_master', 'id', 'CASCADE', 'RESTRICT', $fkName('fk_business_discount_business'));
        $this->forge->createTable('business_discount', true);
    }

    public function down()
    {
        $this->forge->dropTable('business_discount', true);
    }
}
