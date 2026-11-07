<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\SQLite3\Table;

/**
 * Adds withholding tax (see CreateWithholdingTaxRuleTable) to business_master and subscription_invoice.
 *
 * business_master, after tax_id; the businesses already there withhold nothing:
 * - legal_name VARCHAR(255) NULL: the registered name, as on the certificates and tax invoices, when it is not
 *   business_name
 * - tax_branch_code VARCHAR(10) NULL: the branch of tax_id, '00000' for a Thai head office
 * - wht_status ENUM('N', 'R', 'A') DEFAULT 'N': none, requested by the business, approved by staff. Withholding cuts
 *   what we are paid, so only staff approve it (a juristic person, from tax_id); a request turned down goes back to N
 * - wht_rule_code VARCHAR(30) NULL: the withholding_tax_rule series of its country_code; set with R and kept with A
 * - wht_reviewed_by, wht_reviewed_at: the staff member who last approved or turned down a request
 *
 * subscription_invoice:
 * - invoice_type ENUM('S', 'W') DEFAULT 'S': a subscription period, or a top-up of the tax withheld from another
 *   invoice (parent_invoice_id) whose certificate never came. A top-up has no period and nothing withheld from it,
 *   keeps its parent's plan_version_id, and its list_price and amount_due are the parent's wht_amount
 * - wht_rule_id, wht_rate, wht_amount: the rule in effect when the invoice was made and what it withholds, worked out
 *   again when a discount changes amount_due: wht_amount = round(amount_due * wht_rate / 100, 2); none when the
 *   business is not approved or amount_due is under the rule's min_base_amount
 * - amount_payable: amount_due - wht_amount, what the business actually pays (and what a card is charged); the first
 *   successful payment of at least amount_payable pays the invoice. The invoices already there get amount_due
 * - wht_cert_status ENUM('N', 'W', 'U', 'A', 'T') DEFAULT 'N': no certificate needed, waiting for it, uploaded and
 *   waiting for staff, accepted, or never came and recovered by a top-up invoice. W is set when the invoice is paid,
 *   with wht_cert_due_at = paid_at + the rule's certificate_due_days. Past wht_cert_due_at while still W, a monthly
 *   subscription's renewal is not made payable until the certificate is uploaded, and a yearly one gets a top-up
 *   invoice (T), as its renewal would come after the tax is due
 * Depends on business_master, auth_master, subscription_invoice and withholding_tax_rule.
 */
class AddWithholdingTax extends Migration
{
    private const BUSINESS_COLUMNS = ['legal_name', 'tax_branch_code', 'wht_status', 'wht_rule_code', 'wht_reviewed_by', 'wht_reviewed_at'];
    private const INVOICE_COLUMNS  = ['invoice_type', 'parent_invoice_id', 'wht_rule_id', 'wht_rate', 'wht_amount', 'amount_payable', 'wht_cert_status', 'wht_cert_due_at'];

    public function up()
    {
        if ($this->db->DBDriver === 'MySQLi') {
            $this->upMySql();
        } else {
            $this->upSqlite();
        }
    }

    public function down()
    {
        if ($this->db->DBDriver === 'MySQLi') {
            $this->alter('subscription_invoice', 'DROP FOREIGN KEY `fk_subscription_invoice_parent`, DROP FOREIGN KEY `fk_subscription_invoice_wht_rule`');
            $this->alter('subscription_invoice', implode(', ', array_map(static fn (string $c): string => "DROP COLUMN `{$c}`", self::INVOICE_COLUMNS)));
            $this->alter('business_master', 'DROP FOREIGN KEY `fk_business_master_wht_reviewed_by`');
            $this->alter('business_master', implode(', ', array_map(static fn (string $c): string => "DROP COLUMN `{$c}`", self::BUSINESS_COLUMNS)));
        } else {
            $this->rebuildingTables(function (): void {
                $this->dropColumnsSqlite('subscription_invoice', self::INVOICE_COLUMNS);
                $this->dropColumnsSqlite('business_master', self::BUSINESS_COLUMNS);
            });
        }
    }

    private function upMySql(): void
    {
        $authMaster   = $this->db->escapeIdentifiers($this->db->prefixTable('auth_master'));
        $invoiceTable = $this->db->escapeIdentifiers($this->db->prefixTable('subscription_invoice'));
        $ruleTable    = $this->db->escapeIdentifiers($this->db->prefixTable('withholding_tax_rule'));

        $this->alter('business_master', "ADD `legal_name` VARCHAR(255) NULL AFTER `tax_id`,
            ADD `tax_branch_code` VARCHAR(10) NULL AFTER `legal_name`,
            ADD `wht_status` ENUM('N', 'R', 'A') NOT NULL DEFAULT 'N' AFTER `tax_branch_code`,
            ADD `wht_rule_code` VARCHAR(30) NULL AFTER `wht_status`,
            ADD `wht_reviewed_by` INT UNSIGNED NULL AFTER `wht_rule_code`,
            ADD `wht_reviewed_at` DATETIME NULL AFTER `wht_reviewed_by`,
            ADD KEY `wht_status` (`wht_status`),
            ADD KEY `wht_reviewed_by` (`wht_reviewed_by`),
            ADD CONSTRAINT `fk_business_master_wht_reviewed_by` FOREIGN KEY (`wht_reviewed_by`) REFERENCES {$authMaster} (`id`)
                ON UPDATE CASCADE ON DELETE SET NULL");

        // amount_payable is NULL until the invoices already there have one
        $this->alter('subscription_invoice', "ADD `invoice_type` ENUM('S', 'W') NOT NULL DEFAULT 'S' AFTER `invoice_no`,
            ADD `parent_invoice_id` INT UNSIGNED NULL AFTER `invoice_type`,
            ADD `wht_rule_id` INT UNSIGNED NULL AFTER `amount_due`,
            ADD `wht_rate` DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER `wht_rule_id`,
            ADD `wht_amount` DECIMAL(19,2) NOT NULL DEFAULT 0.00 AFTER `wht_rate`,
            ADD `amount_payable` DECIMAL(19,2) NULL AFTER `wht_amount`,
            ADD `wht_cert_status` ENUM('N', 'W', 'U', 'A', 'T') NOT NULL DEFAULT 'N' AFTER `period_end`,
            ADD `wht_cert_due_at` DATETIME NULL AFTER `wht_cert_status`,
            ADD KEY `parent_invoice_id` (`parent_invoice_id`),
            ADD KEY `wht_rule_id` (`wht_rule_id`),
            ADD KEY `wht_cert_status_wht_cert_due_at` (`wht_cert_status`, `wht_cert_due_at`),
            ADD CONSTRAINT `fk_subscription_invoice_parent` FOREIGN KEY (`parent_invoice_id`) REFERENCES {$invoiceTable} (`id`)
                ON UPDATE CASCADE ON DELETE RESTRICT,
            ADD CONSTRAINT `fk_subscription_invoice_wht_rule` FOREIGN KEY (`wht_rule_id`) REFERENCES {$ruleTable} (`id`)
                ON UPDATE CASCADE ON DELETE RESTRICT");
        $this->db->table('subscription_invoice')->set('amount_payable', 'amount_due', false)->update();
        $this->alter('subscription_invoice', 'MODIFY `amount_payable` DECIMAL(19,2) NOT NULL');
    }

    private function upSqlite(): void
    {
        $this->forge->addColumn('business_master', [
            'legal_name'      => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'tax_branch_code' => ['type' => 'VARCHAR', 'constraint' => 10, 'null' => true],
            'wht_status'      => ['type' => 'ENUM', 'constraint' => ['N', 'R', 'A'], 'default' => 'N'],
            'wht_rule_code'   => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true],
            'wht_reviewed_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'wht_reviewed_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addColumn('subscription_invoice', [
            'invoice_type'      => ['type' => 'ENUM', 'constraint' => ['S', 'W'], 'default' => 'S'],
            'parent_invoice_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'wht_rule_id'       => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'wht_rate'          => ['type' => 'DECIMAL', 'constraint' => '5,2', 'default' => '0.00'],
            'wht_amount'        => ['type' => 'DECIMAL', 'constraint' => '19,2', 'default' => '0.00'],
            'amount_payable'    => ['type' => 'DECIMAL', 'constraint' => '19,2', 'null' => true],
            'wht_cert_status'   => ['type' => 'ENUM', 'constraint' => ['N', 'W', 'U', 'A', 'T'], 'default' => 'N'],
            'wht_cert_due_at'   => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->db->table('subscription_invoice')->set('amount_payable', 'amount_due', false)->update();

        // SQLite has neither named foreign keys nor ON DELETE on a rebuilt table; the keys are added by rebuilding it
        $this->rebuildingTables(function (): void {
            $this->forge->addKey('wht_status');
            $this->forge->addKey('wht_reviewed_by');
            $this->forge->addForeignKey('wht_reviewed_by', 'auth_master', 'id', 'CASCADE', 'SET NULL');
            $this->forge->processIndexes('business_master');

            $this->forge->modifyColumn('subscription_invoice', [
                'amount_payable' => ['name' => 'amount_payable', 'type' => 'DECIMAL', 'constraint' => '19,2', 'null' => false],
            ]);
            $this->forge->addKey('parent_invoice_id');
            $this->forge->addKey('wht_rule_id');
            $this->forge->addKey(['wht_cert_status', 'wht_cert_due_at']);
            $this->forge->addForeignKey('parent_invoice_id', 'subscription_invoice', 'id', 'CASCADE', 'RESTRICT');
            $this->forge->addForeignKey('wht_rule_id', 'withholding_tax_rule', 'id', 'CASCADE', 'RESTRICT');
            $this->forge->processIndexes('subscription_invoice');
        });
    }

    /**
     * @param string $table
     * @param string $changes What follows ALTER TABLE `table`
     * @return void
     */
    private function alter(string $table, string $changes): void
    {
        $this->db->query('ALTER TABLE ' . $this->db->escapeIdentifiers($this->db->prefixTable($table)) . ' ' . $changes);
    }

    /**
     * Drops the columns and the foreign keys on them in one rebuild; Forge::dropColumn() would keep the foreign keys,
     * and the rebuilt table would not be created
     * @param string       $table
     * @param list<string> $columns
     * @return void
     */
    private function dropColumnsSqlite(string $table, array $columns): void
    {
        $rebuild = (new Table($this->db, $this->forge))->fromTable($this->db->DBPrefix . $table);
        foreach ($this->db->getForeignKeyData($table) as $name => $foreignKey) {
            if (array_intersect($foreignKey->column_name, $columns) !== []) {
                $rebuild->dropForeignKey($name);
            }
        }
        $rebuild->dropColumn($columns)->run();
    }

    /**
     * SQLite (the test database) changes tables by rebuilding them; the legacy rename keeps the other tables' foreign
     * keys pointing at them (see AddBusinessAudience)
     * @param callable $work
     * @return void
     */
    private function rebuildingTables(callable $work): void
    {
        $sqlite = $this->db->DBDriver === 'SQLite3';
        if ($sqlite) {
            $this->db->query('PRAGMA legacy_alter_table = ON');
        }
        try {
            $work();
        } finally {
            if ($sqlite) {
                $this->db->query('PRAGMA legacy_alter_table = OFF');
            }
        }
    }
}
