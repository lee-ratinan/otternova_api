<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Tax in the ledger (App\Libraries\LedgerTaxReports):
 * - otternova_ledger_tax_code: an entity's VAT and withholding tax codes. tax_kind: VO output VAT (on sales, owed),
 *   VI input VAT (on purchases, claimable), WD withholding tax OtterNova deducted from a payee (owed to the revenue
 *   department), WS withholding tax a customer deducted from OtterNova (a credit against its income tax).
 *   account_id is the account the tax goes into or out of, such as Output VAT payable; none for a 0% code, which is
 *   put on the sale or purchase itself.
 * - otternova_ledger_master.tax_code_id, tax_base_amount: the tax a row is, and what it was worked out on, in the
 *   entity's base currency. journal_id groups the rows of one transaction, such as a bill and its VAT.
 * - otternova_ledger_counterparty.tax_id and tax_branch: for the VAT and withholding tax reports.
 * - otternova_ledger_entity.vat_threshold_amount, vat_registered_on: the revenue in a fiscal year at which the entity
 *   must register for VAT (THB 1.8 million in Thailand, filled in for the Thai entities already there), and when it
 *   did; before that its sales carry no VAT.
 * - otternova_ledger_report_snapshot.report_type also VT (VAT), WT (withholding tax), RR (revenue register), ER
 *   (expense register): the tax reports kept when a month closes. SQLite (the test database) has no ENUM.
 * Depends on otternova_ledger_entity, otternova_ledger_account, otternova_ledger_master,
 * otternova_ledger_counterparty and otternova_ledger_report_snapshot.
 */
class AddLedgerTaxCodes extends Migration
{
    public function up()
    {
        $mysql = $this->db->DBDriver === 'MySQLi';
        $fk    = static fn (string $name): string => $mysql ? $name : ''; // SQLite has no named foreign keys
        // SQLite (the test database) has no ON UPDATE; there the model has to be relied on instead
        $onUpdate = $mysql ? ' ON UPDATE CURRENT_TIMESTAMP' : '';

        $this->forge->addField(['id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true]]);
        $this->forge->addField($mysql
            ? ['public_id CHAR(4) CHARACTER SET ascii COLLATE ascii_bin NOT NULL']
            : ['public_id' => ['type' => 'CHAR', 'constraint' => 4]]);
        $this->forge->addField([
            'entity_id'      => ['type' => 'INT', 'unsigned' => true],
            'tax_code'       => ['type' => 'VARCHAR', 'constraint' => 16], // such as VAT7, WHT3
            'tax_name'       => ['type' => 'VARCHAR', 'constraint' => 128],
            'tax_kind'       => ['type' => 'ENUM', 'constraint' => ['VO', 'VI', 'WD', 'WS']],
            'rate'           => ['type' => 'DECIMAL', 'constraint' => '5,2'], // percent
            'account_id'     => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'effective_from' => ['type' => 'DATE'],
            'effective_to'   => ['type' => 'DATE', 'null' => true],
            'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP' . $onUpdate,
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('public_id', 'uq_otternova_ledger_tax_code_public_id'); // named after the column, see PublicIdTrait
        $this->forge->addUniqueKey(['entity_id', 'tax_code'], 'uq_otternova_ledger_tax_code_entity_code');
        $this->forge->addKey('account_id');
        $this->forge->addForeignKey('entity_id', 'otternova_ledger_entity', 'id', 'CASCADE', 'RESTRICT', $fk('fk_ledger_tax_code_entity'));
        $this->forge->addForeignKey('account_id', 'otternova_ledger_account', 'id', 'CASCADE', 'RESTRICT', $fk('fk_ledger_tax_code_account'));
        $this->forge->createTable('otternova_ledger_tax_code', true); // no ENGINE option so the SQLite test database can run this too

        if ($mysql) {
            $this->db->query('ALTER TABLE `otternova_ledger_master` ADD `tax_code_id` INT UNSIGNED NULL AFTER `base_amount`,'
                . ' ADD `tax_base_amount` DECIMAL(19,4) NULL AFTER `tax_code_id`,'
                . ' ADD `journal_id` CHAR(12) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER `tax_base_amount`,'
                . ' ADD KEY `tax_code_id` (`tax_code_id`), ADD KEY `journal_id` (`journal_id`),'
                . ' ADD CONSTRAINT `fk_ledger_master_tax_code` FOREIGN KEY (`tax_code_id`) REFERENCES `otternova_ledger_tax_code` (`id`) ON UPDATE CASCADE');
            $this->db->query('ALTER TABLE `otternova_ledger_counterparty` ADD `tax_id` VARCHAR(32) NULL AFTER `counterparty_description`,'
                . ' ADD `tax_branch` VARCHAR(16) NULL AFTER `tax_id`');
            $this->db->query('ALTER TABLE `otternova_ledger_entity` ADD `vat_threshold_amount` DECIMAL(19,4) NULL AFTER `fiscal_year_start_month`,'
                . ' ADD `vat_registered_on` DATE NULL AFTER `vat_threshold_amount`');
            $this->db->query("ALTER TABLE `otternova_ledger_report_snapshot` MODIFY `report_type` ENUM('TB','BS','PL','PY','GL','VT','WT','RR','ER') NOT NULL");
        } else {
            $this->forge->addColumn('otternova_ledger_master', [
                'tax_code_id'     => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'tax_base_amount' => ['type' => 'DECIMAL', 'constraint' => '19,4', 'null' => true],
                'journal_id'      => ['type' => 'CHAR', 'constraint' => 12, 'null' => true],
            ]);
            $this->forge->addColumn('otternova_ledger_counterparty', [
                'tax_id'     => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
                'tax_branch' => ['type' => 'VARCHAR', 'constraint' => 16, 'null' => true],
            ]);
            $this->forge->addColumn('otternova_ledger_entity', [
                'vat_threshold_amount' => ['type' => 'DECIMAL', 'constraint' => '19,4', 'null' => true],
                'vat_registered_on'    => ['type' => 'DATE', 'null' => true],
            ]);
            $this->snapshotTypes(['TB', 'BS', 'PL', 'PY', 'GL', 'VT', 'WT', 'RR', 'ER']);
        }
        $this->db->table('otternova_ledger_entity')->where('country_code', 'TH')->where('vat_threshold_amount', null)
            ->where('base_currency_code', 'THB')->update(['vat_threshold_amount' => '1800000']);
    }

    public function down()
    {
        if ($this->db->DBDriver === 'MySQLi') {
            $this->db->query("DELETE FROM `otternova_ledger_report_snapshot` WHERE `report_type` IN ('VT','WT','RR','ER')");
            $this->db->query("ALTER TABLE `otternova_ledger_report_snapshot` MODIFY `report_type` ENUM('TB','BS','PL','PY','GL') NOT NULL");
            $this->db->query('ALTER TABLE `otternova_ledger_entity` DROP COLUMN `vat_threshold_amount`, DROP COLUMN `vat_registered_on`');
            $this->db->query('ALTER TABLE `otternova_ledger_counterparty` DROP COLUMN `tax_id`, DROP COLUMN `tax_branch`');
            $this->db->query('ALTER TABLE `otternova_ledger_master` DROP FOREIGN KEY `fk_ledger_master_tax_code`, DROP INDEX `tax_code_id`, DROP INDEX `journal_id`,'
                . ' DROP COLUMN `tax_code_id`, DROP COLUMN `tax_base_amount`, DROP COLUMN `journal_id`');
        } else {
            // SQLite (3.35 and later) drops a column that no index uses in place, without rebuilding the table
            $this->forge->dropTable('otternova_ledger_tax_code', true);
            foreach ([
                'otternova_ledger_entity'       => ['vat_threshold_amount', 'vat_registered_on'],
                'otternova_ledger_counterparty' => ['tax_id', 'tax_branch'],
                'otternova_ledger_master'       => ['tax_code_id', 'tax_base_amount', 'journal_id'],
            ] as $table => $columns) {
                foreach ($columns as $column) {
                    $this->db->query('ALTER TABLE ' . $this->db->escapeIdentifiers($table) . ' DROP COLUMN ' . $this->db->escapeIdentifiers($column));
                }
            }

            $this->db->table('otternova_ledger_report_snapshot')->whereIn('report_type', ['VT', 'WT', 'RR', 'ER'])->delete();
            $this->snapshotTypes(['TB', 'BS', 'PL', 'PY', 'GL']);

            return;
        }
        $this->forge->dropTable('otternova_ledger_tax_code', true);
    }

    /**
     * SQLite (the test database) keeps an ENUM as a CHECK constraint, which only a new table changes: the snapshots
     * are copied into one with these report types
     * @param list<string> $types
     * @return void
     */
    private function snapshotTypes(array $types): void
    {
        $rows = $this->db->table('otternova_ledger_report_snapshot')->get()->getResultArray();
        $this->forge->dropTable('otternova_ledger_report_snapshot', true);
        $this->forge->addField([
            'id'             => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'period_id'      => ['type' => 'INT', 'unsigned' => true],
            'report_type'    => ['type' => 'ENUM', 'constraint' => $types],
            'date_from'      => ['type' => 'DATE', 'null' => true],
            'date_to'        => ['type' => 'DATE'],
            'content_json'   => ['type' => 'TEXT'],
            'content_sha256' => ['type' => 'CHAR', 'constraint' => 64],
            'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['period_id', 'report_type'], 'uq_otternova_ledger_report_snapshot_period_type');
        $this->forge->addForeignKey('period_id', 'otternova_ledger_period', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->createTable('otternova_ledger_report_snapshot', true);
        if ($rows !== []) {
            $this->db->table('otternova_ledger_report_snapshot')->insertBatch($rows);
        }
    }
}
