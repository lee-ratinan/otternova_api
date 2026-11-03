<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Creates the otternova_ledger_master table (App\Models\OtternovaLedgerMasterModel): one money movement per row,
 * out of from_account_id and into to_account_id. That pair is the two sides of a double entry (a credit to the
 * from account, a debit to the to account), so there is no credit/debit column: it depends on the account looked at.
 * A payment split across several accounts is entered as one row per account.
 * Every foreign key is RESTRICT, so no ledger row is ever removed by a cascade.
 * Depends on otternova_ledger_account, otternova_ledger_category, otternova_ledger_counterparty and auth_master.
 */
class CreateOtternovaLedgerMasterTable extends Migration
{
    public function up()
    {
        // SQLite (the test database) has no ON UPDATE; there the model has to be relied on instead
        $onUpdate = $this->db->DBDriver === 'MySQLi' ? ' ON UPDATE CURRENT_TIMESTAMP' : '';
        $fkName   = fn (string $name): string => $this->db->DBDriver === 'MySQLi' ? $name : ''; // nor named foreign keys

        $this->forge->addField([
            'id'                        => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'transaction_date'          => ['type' => 'DATE'], // when the money moved, not when the row was entered
            'transaction_detail'        => ['type' => 'TEXT'],
            'reference_number'          => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => true], // of the bill or receipt, if any
            'category_id'               => ['type' => 'INT', 'unsigned' => true],
            // what was actually paid, out of from_account_id; always positive
            'transaction_currency_code' => ['type' => 'CHAR', 'constraint' => 3],
            'transaction_amount'        => ['type' => 'DECIMAL', 'constraint' => '19,4'],
            // what was billed, which may differ in currency or amount (exchange, fees, discounts)
            'billed_currency_code'      => ['type' => 'CHAR', 'constraint' => 3],
            'billed_amount'             => ['type' => 'DECIMAL', 'constraint' => '19,4'],
            'from_account_id'           => ['type' => 'INT', 'unsigned' => true],
            'to_account_id'             => ['type' => 'INT', 'unsigned' => true],
            'counterparty_id'           => ['type' => 'INT', 'unsigned' => true, 'null' => true], // required when either account is external
            'receipt_file_path'         => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true], // PDF under Config\LedgerReceipt::$uploadPath
            'created_by'                => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            // maintained by the database, not the model
            'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP' . $onUpdate,
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('transaction_date');
        $this->forge->addKey('reference_number');
        $this->forge->addKey('category_id');
        $this->forge->addKey('from_account_id');
        $this->forge->addKey('to_account_id');
        $this->forge->addKey('counterparty_id');
        $this->forge->addKey('created_by');
        $this->forge->addForeignKey('category_id', 'otternova_ledger_category', 'id', 'CASCADE', 'RESTRICT', $fkName('fk_ledger_master_category'));
        $this->forge->addForeignKey('from_account_id', 'otternova_ledger_account', 'id', 'CASCADE', 'RESTRICT', $fkName('fk_ledger_master_from_account'));
        $this->forge->addForeignKey('to_account_id', 'otternova_ledger_account', 'id', 'CASCADE', 'RESTRICT', $fkName('fk_ledger_master_to_account'));
        $this->forge->addForeignKey('counterparty_id', 'otternova_ledger_counterparty', 'id', 'CASCADE', 'RESTRICT', $fkName('fk_ledger_master_counterparty'));
        $this->forge->addForeignKey('created_by', 'auth_master', 'id', 'CASCADE', 'SET NULL', $fkName('fk_ledger_master_created_by'));
        $this->forge->createTable('otternova_ledger_master', true); // InnoDB is MySQL's default engine; no ENGINE option so the SQLite test database can run this too
    }

    public function down()
    {
        $this->forge->dropTable('otternova_ledger_master', true);
    }
}
