<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Creates the payment_method table: the ways an invoice can be paid, offered where country_code matches the business
 * (NULL = every country) and the invoice is in currency_code.
 * confirmation_mode A is confirmed by the provider (provider_code) on its own; M by staff checking the slip.
 * The details shown to the payer (bank account, PromptPay ID) are in Config\Billing, not here.
 * The first rows, promptpay and bank_transfer, are added by SeedPlansAndPaymentMethods.
 */
class CreatePaymentMethodTable extends Migration
{
    public function up()
    {
        // SQLite (the test database) has no ON UPDATE; there the model has to be relied on instead
        $onUpdate = $this->db->DBDriver === 'MySQLi' ? ' ON UPDATE CURRENT_TIMESTAMP' : '';

        $this->forge->addField([
            'id'                => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'method_code'       => ['type' => 'VARCHAR', 'constraint' => 30], // promptpay, bank_transfer
            'country_code'      => ['type' => 'CHAR', 'constraint' => 2, 'null' => true], // NULL = every country
            'currency_code'     => ['type' => 'CHAR', 'constraint' => 3],
            'confirmation_mode' => ['type' => 'ENUM', 'constraint' => ['A', 'M']], // by the provider, by staff
            'provider_code'     => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true], // NULL when confirmed by staff
            'is_active'         => ['type' => 'ENUM', 'constraint' => ['Y', 'N'], 'default' => 'Y'],
            'sort_order'        => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 0],
            // maintained by the database, not the model
            'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP' . $onUpdate,
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('method_code');
        $this->forge->createTable('payment_method', true);
    }

    public function down()
    {
        $this->forge->dropTable('payment_method', true);
    }
}
