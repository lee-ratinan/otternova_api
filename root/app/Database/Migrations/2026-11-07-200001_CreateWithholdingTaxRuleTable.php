<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Creates the withholding_tax_rule table: the tax a business in one country withholds from what it pays us, such as
 * Thailand's 3% on service fees, for which it gives us a certificate (50 ทวิ). A series is one country_code and
 * rule_code; each changes rate on its own, like plan_version.
 *
 * effective_from and effective_to are UTC, the range includes its start but not its end, and effective_to NULL means
 * no end yet, as in CreatePlanVersionTable; open_guard lets each series have only one row with no end. A business
 * opts in with business_master.wht_rule_code, and each invoice is billed on the row of that series in effect when it
 * is made (subscription_invoice.wht_rule_id), so rows are never deleted once in effect.
 *
 * rate is a percentage (3.00 for 3%) of the invoice's amount_due, which is before VAT. Nothing is withheld from an
 * invoice whose amount_due is under min_base_amount (in currency_code); NULL means no minimum. certificate_required 'N' is for a way of withholding that needs no certificate from the business, such
 * as Thailand's e-Withholding Tax, where the bank reports it. certificate_name is what the business calls it
 * ('50 ทวิ', 'BIR 2307'); certificate_due_days, from when the invoice is paid, sets subscription_invoice.wht_cert_due_at.
 * public_id is a NanoID (see PublicIdTrait); on MySQL it is ascii_bin, as NanoIDs tell upper from lower case.
 */
class CreateWithholdingTaxRuleTable extends Migration
{
    public function up()
    {
        $mysql = $this->db->DBDriver === 'MySQLi';
        // SQLite (the test database) has no ON UPDATE; there the model has to be relied on instead
        $onUpdate = $mysql ? ' ON UPDATE CURRENT_TIMESTAMP' : '';

        $this->forge->addField(['id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true]]);
        $this->forge->addField($mysql
            ? ['public_id CHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL']
            : ['public_id' => ['type' => 'CHAR', 'constraint' => 8]]);
        $this->forge->addField([
            'country_code'         => ['type' => 'CHAR', 'constraint' => 2],
            'rule_code'            => ['type' => 'VARCHAR', 'constraint' => 30], // TH_SERVICE
            'rate'                 => ['type' => 'DECIMAL', 'constraint' => '5,2'], // percent of amount_due
            'min_base_amount'      => ['type' => 'DECIMAL', 'constraint' => '19,2', 'null' => true], // NULL = no minimum
            'currency_code'        => ['type' => 'CHAR', 'constraint' => 3], // of min_base_amount
            'certificate_required' => ['type' => 'ENUM', 'constraint' => ['Y', 'N'], 'default' => 'Y'],
            'certificate_name'     => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'certificate_due_days' => ['type' => 'SMALLINT', 'unsigned' => true, 'default' => 30],
            'effective_from'       => ['type' => 'DATETIME'], // UTC, included
            'effective_to'         => ['type' => 'DATETIME', 'null' => true], // UTC, excluded; NULL = no end yet
            'open_guard TINYINT GENERATED ALWAYS AS (CASE WHEN effective_to IS NULL THEN 1 END) STORED',
            // maintained by the database, not the model
            'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP' . $onUpdate,
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('public_id', 'uq_withholding_tax_rule_public_id'); // named after the column, see PublicIdTrait
        $this->forge->addUniqueKey(['country_code', 'rule_code', 'open_guard'], 'uq_withholding_tax_rule_one_open');
        $this->forge->addKey(['country_code', 'rule_code', 'effective_from']); // the rule in effect
        $this->forge->createTable('withholding_tax_rule', true);
    }

    public function down()
    {
        $this->forge->dropTable('withholding_tax_rule', true);
    }
}
