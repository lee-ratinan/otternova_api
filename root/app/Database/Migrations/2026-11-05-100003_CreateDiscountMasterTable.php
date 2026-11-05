<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Creates the discount_master table: discount codes a business applies at checkout, with no staff involved.
 * A listed code (is_listed 'Y') is offered on the checkout page; any code can also be typed in.
 * A discount always covers one invoice, so one billing period; the next invoice is at full price again.
 *
 * discount_type P is a percentage (discount_value 10.00 = 10%) and has no currency_code.
 * discount_type F is a fixed amount in currency_code, which is required, as is a country_code.
 * Who may use it: business_id when it is for that business only; otherwise every business in country_code, where
 * 'ZZ' is the rest of the world as in plan_version, and NULL is every country, allowed for percentages only.
 * plan_id and billing_cycle limit it to that plan or cycle; NULL is any.
 * The model checks those combinations, as the database cannot.
 *
 * max_redemptions caps the uses (NULL = no cap). redemption_count is raised when a code is applied to an invoice,
 * not when it is paid, by one UPDATE that only succeeds below the cap, and lowered when that invoice is voided
 * unpaid; so a business paying by transfer never finds the cap reached after it applied the code.
 * max_per_business caps the invoices of one business with the code (NULL = no cap).
 * valid_from and expires_at are UTC; the code can be applied from valid_from and no longer from expires_at.
 * An invoice it was already applied to keeps its price.
 * discount_code is stored in upper case by the model; MySQL's collation also compares it without case.
 * public_id is a NanoID (see PublicIdTrait); on MySQL it is ascii_bin, as NanoIDs tell upper from lower case.
 * Depends on business_master, plan_master and auth_master.
 */
class CreateDiscountMasterTable extends Migration
{
    public function up()
    {
        $mysql = $this->db->DBDriver === 'MySQLi';
        // SQLite (the test database) has no ON UPDATE; there the model has to be relied on instead
        $onUpdate = $mysql ? ' ON UPDATE CURRENT_TIMESTAMP' : '';
        $fkName   = static fn (string $name): string => $mysql ? $name : ''; // nor named foreign keys

        $this->forge->addField(['id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true]]);
        $this->forge->addField($mysql
            ? ['public_id CHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL']
            : ['public_id' => ['type' => 'CHAR', 'constraint' => 8]]);
        $this->forge->addField([
            'discount_code'        => ['type' => 'VARCHAR', 'constraint' => 30],
            'discount_description' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true], // shown at checkout
            'discount_type'        => ['type' => 'ENUM', 'constraint' => ['P', 'F']], // percentage, fixed amount
            'discount_value'       => ['type' => 'DECIMAL', 'constraint' => '19,2'],
            'currency_code'        => ['type' => 'CHAR', 'constraint' => 3, 'null' => true], // F only
            'country_code'         => ['type' => 'CHAR', 'constraint' => 2, 'null' => true], // NULL = every country, P only
            'business_id'          => ['type' => 'INT', 'unsigned' => true, 'null' => true], // for this business only
            'plan_id'              => ['type' => 'INT', 'unsigned' => true, 'null' => true], // NULL = any plan
            'billing_cycle'        => ['type' => 'ENUM', 'constraint' => ['M', 'Y'], 'null' => true], // NULL = either
            'is_listed'            => ['type' => 'ENUM', 'constraint' => ['Y', 'N'], 'default' => 'Y'], // offered at checkout
            'max_redemptions'      => ['type' => 'INT', 'unsigned' => true, 'null' => true], // NULL = no cap
            'redemption_count'     => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'max_per_business'     => ['type' => 'TINYINT', 'unsigned' => true, 'null' => true, 'default' => 1], // NULL = no cap
            'valid_from'           => ['type' => 'DATETIME'], // UTC, included
            'expires_at'           => ['type' => 'DATETIME'], // UTC, excluded
            'created_by'           => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            // maintained by the database, not the model
            'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP' . $onUpdate,
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('public_id', 'uq_discount_master_public_id'); // named after the column, see PublicIdTrait
        $this->forge->addUniqueKey('discount_code');
        $this->forge->addKey(['is_listed', 'expires_at']); // the codes offered at checkout
        $this->forge->addKey('business_id');
        $this->forge->addKey('plan_id');
        $this->forge->addKey('created_by');
        $this->forge->addForeignKey('business_id', 'business_master', 'id', 'CASCADE', 'RESTRICT', $fkName('fk_discount_master_business'));
        $this->forge->addForeignKey('plan_id', 'plan_master', 'id', 'CASCADE', 'RESTRICT', $fkName('fk_discount_master_plan'));
        $this->forge->addForeignKey('created_by', 'auth_master', 'id', 'CASCADE', 'SET NULL', $fkName('fk_discount_master_created_by'));
        $this->forge->createTable('discount_master', true);
    }

    public function down()
    {
        $this->forge->dropTable('discount_master', true);
    }
}
