<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Creates the subscription_invoice table: what a subscription owes for one billing period.
 * list_price is copied from plan_version when the invoice is made. A discount is a cut in the price, not a payment:
 * applying one sets discount_id and discount_amount (a percentage rounded to the satang or cent), and
 * amount_due = list_price - discount_amount, never below 0. One discount per invoice, so per period.
 * An invoice with amount_due 0 is paid as soon as it is made, with no payment_transaction.
 *
 * invoice_status: P pending, D paid (paid_at), V void (voided_at). A first invoice left unpaid for
 * Config\Billing::$invoiceHoldHours is voided, and gives its discount's redemption back.
 * period_start and period_end are set when it is paid, following the rules in CreateSubscriptionMasterTable.
 * Amounts are before VAT.
 * public_id is a NanoID (see PublicIdTrait); on MySQL it is ascii_bin, as NanoIDs tell upper from lower case.
 * Depends on subscription_master, plan_version and discount_master.
 */
class CreateSubscriptionInvoiceTable extends Migration
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
            'invoice_no'      => ['type' => 'VARCHAR', 'constraint' => 30], // the number the business sees
            'subscription_id' => ['type' => 'INT', 'unsigned' => true],
            'plan_version_id' => ['type' => 'INT', 'unsigned' => true],
            'currency_code'   => ['type' => 'CHAR', 'constraint' => 3],
            'list_price'      => ['type' => 'DECIMAL', 'constraint' => '19,2'],
            'discount_id'     => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'discount_amount' => ['type' => 'DECIMAL', 'constraint' => '19,2', 'default' => '0.00'],
            'amount_due'      => ['type' => 'DECIMAL', 'constraint' => '19,2'],
            'invoice_status'  => ['type' => 'ENUM', 'constraint' => ['P', 'D', 'V'], 'default' => 'P'], // pending, paid, void
            'due_at'          => ['type' => 'DATETIME'], // first invoice: made + hold hours; renewal: period end + grace days
            'paid_at'         => ['type' => 'DATETIME', 'null' => true],
            'voided_at'       => ['type' => 'DATETIME', 'null' => true],
            'period_start'    => ['type' => 'DATETIME', 'null' => true], // set when paid; included
            'period_end'      => ['type' => 'DATETIME', 'null' => true], // excluded
            // maintained by the database, not the model
            'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP' . $onUpdate,
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('public_id', 'uq_subscription_invoice_public_id'); // named after the column, see PublicIdTrait
        $this->forge->addUniqueKey('invoice_no');
        $this->forge->addKey('subscription_id');
        $this->forge->addKey(['invoice_status', 'due_at']); // the void and suspension runs
        $this->forge->addKey('plan_version_id');
        $this->forge->addKey('discount_id');
        $this->forge->addForeignKey('subscription_id', 'subscription_master', 'id', 'CASCADE', 'RESTRICT', $fkName('fk_subscription_invoice_subscription'));
        $this->forge->addForeignKey('plan_version_id', 'plan_version', 'id', 'CASCADE', 'RESTRICT', $fkName('fk_subscription_invoice_plan_version'));
        $this->forge->addForeignKey('discount_id', 'discount_master', 'id', 'CASCADE', 'RESTRICT', $fkName('fk_subscription_invoice_discount'));
        $this->forge->createTable('subscription_invoice', true);
    }

    public function down()
    {
        $this->forge->dropTable('subscription_invoice', true);
    }
}
