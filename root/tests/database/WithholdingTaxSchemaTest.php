<?php

use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * The withholding tax tables and columns: one open rule per series, the defaults a business and an invoice get, and
 * a top-up invoice pointing at its parent
 *
 * @internal
 */
final class WithholdingTaxSchemaTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace = 'App'; // the app's own migrations, the schema of the real database

    private int $businessId;
    private int $versionId;
    private int $subscriptionId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db->table('business_type')->insert(['public_id' => 'typ1', 'type_code' => 'cafe']);
        $this->db->table('business_master')->insert([
            'business_type_id'      => (int) $this->db->insertID(),
            'business_name'         => 'Otter Café',
            'business_slug'         => 'otter-cafe',
            'contact_email_address' => 'otter@example.com',
            'country_code'          => 'TH',
            'currency_code'         => 'THB',
        ]);
        $this->businessId = (int) $this->db->insertID();

        $this->db->table('plan_master')->insert(['plan_code' => 'boost', 'plan_tier' => 1]);
        $this->db->table('plan_version')->insert([
            'public_id'      => 'ver00001',
            'plan_id'        => (int) $this->db->insertID(),
            'country_code'   => 'TH',
            'currency_code'  => 'THB',
            'billing_cycle'  => 'Y',
            'price'          => '2990.00',
            'effective_from' => '2026-01-01 00:00:00',
        ]);
        $this->versionId = (int) $this->db->insertID();

        $this->db->table('subscription_master')->insert([
            'public_id'       => 'sub000000001',
            'business_id'     => $this->businessId,
            'plan_version_id' => $this->versionId,
        ]);
        $this->subscriptionId = (int) $this->db->insertID();
    }

    public function testABusinessWithholdsNothingUntilApproved(): void
    {
        $this->seeInDatabase('business_master', [
            'id'              => $this->businessId,
            'wht_status'      => 'N',
            'wht_rule_code'   => null,
            'legal_name'      => null,
            'tax_branch_code' => null,
        ]);
    }

    public function testASeriesHasOnlyOneOpenRule(): void
    {
        $old = $this->rule('3.00', '2025-12-31 17:00:00');

        try {
            $this->rule('1.00', '2026-12-31 17:00:00');
            $this->fail('A second open rule was accepted');
        } catch (DatabaseException) {
            // expected
        }

        $this->db->table('withholding_tax_rule')->where('id', $old)->update(['effective_to' => '2026-12-31 17:00:00']);
        $this->rule('1.00', '2026-12-31 17:00:00');

        $this->seeNumRecords(2, 'withholding_tax_rule', ['country_code' => 'TH', 'rule_code' => 'TH_SERVICE']);
    }

    public function testAnInvoiceNeedsAnAmountPayable(): void
    {
        $this->expectException(DatabaseException::class);
        $this->invoice(['invoice_no' => 'INV-1', 'list_price' => '2990.00', 'amount_due' => '2990.00']);
    }

    public function testAnInvoiceWithholdsAndATopUpPointsAtIt(): void
    {
        $ruleId = $this->rule('3.00', '2025-12-31 17:00:00');
        $parent = $this->invoice([
            'invoice_no'      => 'INV-1',
            'list_price'      => '2990.00',
            'amount_due'      => '2990.00',
            'wht_rule_id'     => $ruleId,
            'wht_rate'        => '3.00',
            'wht_amount'      => '89.70',
            'amount_payable'  => '2900.30',
            'wht_cert_status' => 'W',
            'wht_cert_due_at' => '2026-12-07 00:00:00',
        ]);
        $topUp = $this->invoice([
            'invoice_no'        => 'INV-2',
            'invoice_type'      => 'W',
            'parent_invoice_id' => $parent,
            'list_price'        => '89.70',
            'amount_due'        => '89.70',
            'amount_payable'    => '89.70',
        ]);

        $this->seeInDatabase('subscription_invoice', ['id' => $parent, 'invoice_type' => 'S', 'parent_invoice_id' => null]);
        $this->seeInDatabase('subscription_invoice', ['id' => $topUp, 'wht_rate' => '0.00', 'wht_amount' => '0.00', 'wht_cert_status' => 'N']);

        $this->db->table('withholding_tax_certificate')->insert([
            'public_id'   => 'crt000000001',
            'invoice_id'  => $parent,
            'issued_on'   => '2026-11-07',
            'base_amount' => '2990.00',
            'wht_amount'  => '89.70',
            'file_path'   => '2026/11/crt000000001.pdf',
        ]);
        $this->seeInDatabase('withholding_tax_certificate', ['invoice_id' => $parent, 'cert_status' => 'P']);
    }

    private function rule(string $rate, string $from): int
    {
        $this->db->table('withholding_tax_rule')->insert([
            'public_id'        => substr(md5($rate . $from), 0, 8),
            'country_code'     => 'TH',
            'rule_code'        => 'TH_SERVICE',
            'rate'             => $rate,
            'min_base_amount'  => '1000.00',
            'currency_code'    => 'THB',
            'certificate_name' => '50 ทวิ',
            'effective_from'   => $from,
        ]);

        return (int) $this->db->insertID();
    }

    private function invoice(array $row): int
    {
        $this->db->table('subscription_invoice')->insert($row + [
            'public_id'       => substr(md5($row['invoice_no']), 0, 12),
            'subscription_id' => $this->subscriptionId,
            'plan_version_id' => $this->versionId,
            'currency_code'   => 'THB',
            'due_at'          => '2026-11-08 00:00:00',
        ]);

        return (int) $this->db->insertID();
    }
}
