<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * For the financial reports (App\Libraries\LedgerReports):
 * - otternova_ledger_account.entity_id: the legal entity (otternova_ledger_entity) whose books the account is in.
 *   When there are accounts already, they all go to one new entity, "OtterNova (Thailand)" in THB, to be renamed
 *   and completed on the Entities page. NOT NULL on MySQL; SQLite (the test database) cannot add a NOT NULL column
 *   with a foreign key, so there the model's rules have to be relied on instead.
 * - otternova_ledger_master.base_rate and base_amount: the entry in its entity's base currency. base_amount is the
 *   paid amount when that is in the base currency, else the billed amount when that is, else the paid amount times
 *   base_rate (base currency per unit of the paid currency). Entries already there get it where no rate is needed.
 * Depends on otternova_ledger_entity, otternova_ledger_account and otternova_ledger_master.
 */
class AddLedgerEntityAndBaseAmounts extends Migration
{
    public function up()
    {
        $mysql = $this->db->DBDriver === 'MySQLi';

        if ($mysql) {
            $this->db->query('ALTER TABLE `otternova_ledger_account` ADD `entity_id` INT UNSIGNED NULL AFTER `public_id`');
            $this->db->query('ALTER TABLE `otternova_ledger_master` ADD `base_rate` DECIMAL(20,10) NULL AFTER `billed_amount`, ADD `base_amount` DECIMAL(19,4) NULL AFTER `base_rate`');
        } else {
            $this->forge->addColumn('otternova_ledger_account', ['entity_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true]]);
            $this->forge->addColumn('otternova_ledger_master', [
                'base_rate'   => ['type' => 'DECIMAL', 'constraint' => '20,10', 'null' => true],
                'base_amount' => ['type' => 'DECIMAL', 'constraint' => '19,4', 'null' => true],
            ]);
        }

        $this->db->transException(true)->transStart();
        if ($this->db->table('otternova_ledger_account')->countAllResults() > 0) {
            helper('id');
            $first = $this->db->table('otternova_ledger_master')->selectMin('transaction_date')->get()->getRow()->transaction_date ?? null;
            $this->db->table('otternova_ledger_entity')->insert([
                'public_id'               => generateNanoId(4),
                'entity_name'             => 'OtterNova (Thailand)',
                'country_code'            => 'TH',
                'base_currency_code'      => 'THB',
                'timezone'                => 'Asia/Bangkok',
                'fiscal_year_start_month' => 1,
                'registered_on'           => $first,
            ]);
            $this->db->table('otternova_ledger_account')->update(['entity_id' => (int) $this->db->insertID()]);

            $rows = $this->db->table('otternova_ledger_master')->select('id, transaction_currency_code, transaction_amount, billed_currency_code, billed_amount')->get()->getResultArray();
            foreach ($rows as $row) {
                $amount = match ('THB') {
                    $row['transaction_currency_code'] => $row['transaction_amount'],
                    $row['billed_currency_code']      => $row['billed_amount'],
                    default                           => null,
                };
                if ($amount !== null) {
                    $this->db->table('otternova_ledger_master')->where('id', $row['id'])->update(['base_amount' => $amount]);
                }
            }
        }
        $this->db->transComplete();
        $this->db->transException(false);

        if ($mysql) {
            $this->db->query('ALTER TABLE `otternova_ledger_account` MODIFY `entity_id` INT UNSIGNED NOT NULL, ADD KEY `entity_id` (`entity_id`),'
                . ' ADD CONSTRAINT `fk_ledger_account_entity` FOREIGN KEY (`entity_id`) REFERENCES `otternova_ledger_entity` (`id`) ON UPDATE CASCADE');
        }
    }

    public function down()
    {
        if ($this->db->DBDriver === 'MySQLi') {
            $this->db->query('ALTER TABLE `otternova_ledger_account` DROP FOREIGN KEY `fk_ledger_account_entity`, DROP INDEX `entity_id`, DROP COLUMN `entity_id`');
            $this->db->query('ALTER TABLE `otternova_ledger_master` DROP COLUMN `base_rate`, DROP COLUMN `base_amount`');

            return;
        }
        // SQLite drops a column by rebuilding the table; the legacy rename keeps the other tables' foreign keys
        // pointing at it (see MoveAvatarAndTrimProfileLocale)
        $this->db->query('PRAGMA legacy_alter_table = ON');
        try {
            $this->forge->dropColumn('otternova_ledger_account', 'entity_id');
            $this->forge->dropColumn('otternova_ledger_master', ['base_rate', 'base_amount']);
        } finally {
            $this->db->query('PRAGMA legacy_alter_table = OFF');
        }
    }
}
