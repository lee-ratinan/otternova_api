<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Adds Thailand's withholding on service fees: a Thai juristic person paying us (a Thai sole proprietor) for a
 * service withholds 3% of the amount before VAT, files it on ภ.ง.ด.3, and gives us a 50 ทวิ. Nothing is withheld
 * from a payment under ฿1,000. Other countries are added by staff once checked with an accountant.
 * Depends on withholding_tax_rule.
 */
class SeedThaiWithholdingTaxRule extends Migration
{
    public function up()
    {
        // the test database starts empty; the tests create the rows they need
        if (ENVIRONMENT === 'testing') {
            return;
        }

        helper('id');
        $rules = $this->db->table('withholding_tax_rule');
        if ($rules->where('country_code', 'TH')->where('rule_code', 'TH_SERVICE')->countAllResults() === 0) {
            $rules->insert([
                'public_id'            => generateNanoId(8),
                'country_code'         => 'TH',
                'rule_code'            => 'TH_SERVICE',
                'rate'                 => '3.00',
                'min_base_amount'      => '1000.00',
                'currency_code'        => 'THB',
                'certificate_required' => 'Y',
                'certificate_name'     => '50 ทวิ',
                'certificate_due_days' => 30,
                'effective_from'       => '2025-12-31 17:00:00', // Bangkok 2026-01-01 00:00
            ]);
        }
    }

    public function down()
    {
        // nothing to do: rolling back further drops the table
    }
}
