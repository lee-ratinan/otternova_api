<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Creates the payment_transaction table: one try at paying an invoice. An invoice can have several, such as a slip
 * that staff reject and the right one after it; the first to succeed for at least amount_due pays the invoice.
 * txn_status: P pending (sent to the provider, or slip uploaded), S succeeded, F failed or rejected (failure_reason).
 * slip_path is under Config\Billing::$slipUploadPath; never under public/.
 * confirmed_by and confirmed_at are the staff member who checked a slip, or NULL and the provider's time.
 * public_id is a NanoID (see PublicIdTrait); on MySQL it is ascii_bin, as NanoIDs tell upper from lower case.
 * Depends on subscription_invoice, payment_method and auth_master.
 */
class CreatePaymentTransactionTable extends Migration
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
            'invoice_id'        => ['type' => 'INT', 'unsigned' => true],
            'payment_method_id' => ['type' => 'INT', 'unsigned' => true],
            'currency_code'     => ['type' => 'CHAR', 'constraint' => 3],
            'amount'            => ['type' => 'DECIMAL', 'constraint' => '19,2'],
            'txn_status'        => ['type' => 'ENUM', 'constraint' => ['P', 'S', 'F'], 'default' => 'P'], // pending, succeeded, failed
            'provider_ref'      => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true], // provider's charge ID or the bank's reference
            'slip_path'         => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'failure_reason'    => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'confirmed_by'      => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'confirmed_at'      => ['type' => 'DATETIME', 'null' => true],
            // maintained by the database, not the model
            'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP' . $onUpdate,
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('public_id', 'uq_payment_transaction_public_id'); // named after the column, see PublicIdTrait
        $this->forge->addKey('invoice_id');
        $this->forge->addKey(['txn_status', 'created_at']); // the slips waiting for staff
        $this->forge->addKey('payment_method_id');
        $this->forge->addKey('provider_ref');
        $this->forge->addKey('confirmed_by');
        $this->forge->addForeignKey('invoice_id', 'subscription_invoice', 'id', 'CASCADE', 'RESTRICT', $fkName('fk_payment_transaction_invoice'));
        $this->forge->addForeignKey('payment_method_id', 'payment_method', 'id', 'CASCADE', 'RESTRICT', $fkName('fk_payment_transaction_method'));
        $this->forge->addForeignKey('confirmed_by', 'auth_master', 'id', 'CASCADE', 'SET NULL', $fkName('fk_payment_transaction_confirmed_by'));
        $this->forge->createTable('payment_transaction', true);
    }

    public function down()
    {
        $this->forge->dropTable('payment_transaction', true);
    }
}
