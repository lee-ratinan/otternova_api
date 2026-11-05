<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Adds the four plans and the two Thai payment methods. No plan_version rows: the prices are set by staff.
 * Depends on plan_master and payment_method.
 */
class SeedPlansAndPaymentMethods extends Migration
{
    public function up()
    {
        // the test database starts empty; the tests create the rows they need
        if (ENVIRONMENT === 'testing') {
            return;
        }

        $plans = $this->db->table('plan_master');
        foreach (['free' => 0, 'boost' => 1, 'velocity' => 2, 'quantum' => 3] as $code => $tier) {
            if ($plans->where('plan_code', $code)->countAllResults() === 0) {
                $plans->insert(['plan_code' => $code, 'plan_tier' => $tier]);
            }
        }

        $methods = $this->db->table('payment_method');
        foreach (['promptpay' => 1, 'bank_transfer' => 2] as $code => $order) {
            if ($methods->where('method_code', $code)->countAllResults() === 0) {
                $methods->insert([
                    'method_code'       => $code,
                    'country_code'      => 'TH',
                    'currency_code'     => 'THB',
                    'confirmation_mode' => 'M',
                    'sort_order'        => $order,
                ]);
            }
        }
    }

    public function down()
    {
        // nothing to do: rolling back further drops both tables
    }
}
