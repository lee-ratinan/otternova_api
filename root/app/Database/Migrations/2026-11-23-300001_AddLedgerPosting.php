<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Posting the subscription billing to the ledger (App\Libraries\LedgerPosting):
 * - otternova_ledger_posting_rule: which entity books the invoices of the businesses of a country from a date on,
 *   and into which of its accounts: receivable, deferred revenue, revenue, cash and the tax customers withhold
 *   (with its WS tax code), under one category. Invoices before effective_from are never posted, so the months
 *   already entered by hand are not counted twice.
 * - otternova_ledger_master.source_key: the billing event a row was posted for, such as inv:123:paid:1, so each is
 *   posted once; such rows are read-only. NULL for the rows entered by hand.
 * - otternova_ledger_counterparty.business_id: the business a counterparty was made for, by the posting.
 * Depends on otternova_ledger_entity, otternova_ledger_account, otternova_ledger_tax_code, otternova_ledger_category,
 * otternova_ledger_master, otternova_ledger_counterparty and business_master.
 */
class AddLedgerPosting extends Migration
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
        $accounts = ['receivable_account_id', 'deferred_revenue_account_id', 'revenue_account_id', 'cash_account_id', 'wht_receivable_account_id'];
        $this->forge->addField([
            'entity_id'      => ['type' => 'INT', 'unsigned' => true],
            'country_code'   => ['type' => 'CHAR', 'constraint' => 2], // of the businesses billed
            'effective_from' => ['type' => 'DATE'],
            'effective_to'   => ['type' => 'DATE', 'null' => true],
        ]);
        foreach ($accounts as $column) {
            $this->forge->addField([$column => ['type' => 'INT', 'unsigned' => true, 'null' => $column === 'wht_receivable_account_id']]);
        }
        $this->forge->addField([
            'wht_tax_code_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'category_id'     => ['type' => 'INT', 'unsigned' => true],
            'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP' . $onUpdate,
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('public_id', 'uq_otternova_ledger_posting_rule_public_id'); // named after the column, see PublicIdTrait
        $this->forge->addKey(['country_code', 'effective_from']);
        $this->forge->addKey('entity_id');
        $this->forge->addForeignKey('entity_id', 'otternova_ledger_entity', 'id', 'CASCADE', 'RESTRICT', $fk('fk_ledger_posting_rule_entity'));
        foreach ($accounts as $column) {
            $this->forge->addKey($column);
            $this->forge->addForeignKey($column, 'otternova_ledger_account', 'id', 'CASCADE', 'RESTRICT', $fk('fk_ledger_posting_rule_' . str_replace('_account_id', '', $column)));
        }
        $this->forge->addKey('wht_tax_code_id');
        $this->forge->addKey('category_id');
        $this->forge->addForeignKey('wht_tax_code_id', 'otternova_ledger_tax_code', 'id', 'CASCADE', 'RESTRICT', $fk('fk_ledger_posting_rule_wht_tax_code'));
        $this->forge->addForeignKey('category_id', 'otternova_ledger_category', 'id', 'CASCADE', 'RESTRICT', $fk('fk_ledger_posting_rule_category'));
        $this->forge->createTable('otternova_ledger_posting_rule', true); // no ENGINE option so the SQLite test database can run this too

        if ($mysql) {
            $this->db->query('ALTER TABLE `otternova_ledger_master` ADD `source_key` VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER `journal_id`,'
                . ' ADD UNIQUE KEY `uq_otternova_ledger_master_source_key` (`source_key`)');
            $this->db->query('ALTER TABLE `otternova_ledger_counterparty` ADD `business_id` INT UNSIGNED NULL AFTER `tax_branch`,'
                . ' ADD UNIQUE KEY `uq_otternova_ledger_counterparty_business` (`business_id`),'
                . ' ADD CONSTRAINT `fk_ledger_counterparty_business` FOREIGN KEY (`business_id`) REFERENCES `business_master` (`id`) ON UPDATE CASCADE');
        } else {
            $this->forge->addColumn('otternova_ledger_master', ['source_key' => ['type' => 'VARCHAR', 'constraint' => 48, 'null' => true]]);
            $this->forge->addColumn('otternova_ledger_counterparty', ['business_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true]]);
            $this->db->query('CREATE UNIQUE INDEX uq_otternova_ledger_master_source_key ON otternova_ledger_master (source_key)');
            $this->db->query('CREATE UNIQUE INDEX uq_otternova_ledger_counterparty_business ON otternova_ledger_counterparty (business_id)');
        }
    }

    public function down()
    {
        if ($this->db->DBDriver === 'MySQLi') {
            $this->db->query('ALTER TABLE `otternova_ledger_counterparty` DROP FOREIGN KEY `fk_ledger_counterparty_business`,'
                . ' DROP INDEX `uq_otternova_ledger_counterparty_business`, DROP COLUMN `business_id`');
            $this->db->query('ALTER TABLE `otternova_ledger_master` DROP INDEX `uq_otternova_ledger_master_source_key`, DROP COLUMN `source_key`');
        } else {
            // SQLite (3.35 and later) drops a column in place once no index uses it
            $this->db->query('DROP INDEX IF EXISTS uq_otternova_ledger_counterparty_business');
            $this->db->query('DROP INDEX IF EXISTS uq_otternova_ledger_master_source_key');
            $this->db->query('ALTER TABLE otternova_ledger_counterparty DROP COLUMN business_id');
            $this->db->query('ALTER TABLE otternova_ledger_master DROP COLUMN source_key');
        }
        $this->forge->dropTable('otternova_ledger_posting_rule', true);
    }
}
