<?php

use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * The guards the billing tables keep themselves: one open plan_version per series (open_guard) and one live
 * subscription per business (live_guard)
 *
 * @internal
 */
final class BillingSchemaTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace = 'App'; // the app's own migrations, the schema of the real database

    private int $planId;
    private int $businessId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db->table('plan_master')->insert(['plan_code' => 'boost', 'plan_tier' => 1]);
        $this->planId = (int) $this->db->insertID();

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
    }

    public function testASeriesHasOnlyOneOpenVersion(): void
    {
        $old = $this->version('299.00', '2026-01-01 00:00:00');

        // a second open row in the same series is refused
        try {
            $this->version('399.00', '2026-11-14 17:00:00');
            $this->fail('A second open version was accepted');
        } catch (DatabaseException) {
            // expected
        }

        // another series (yearly, or another country) is not affected
        $this->version('2990.00', '2026-01-01 00:00:00', 'Y');
        $this->version('9.00', '2026-01-01 00:00:00', 'M', 'ZZ', 'USD');

        // ending the old row first lets the new one in, even with the end in the future
        $this->db->table('plan_version')->where('id', $old)->update(['effective_to' => '2026-11-14 17:00:00']);
        $this->version('399.00', '2026-11-14 17:00:00');

        $this->seeNumRecords(2, 'plan_version', ['plan_id' => $this->planId, 'country_code' => 'TH', 'billing_cycle' => 'M']);
        $this->seeInDatabase('plan_version', ['id' => $old, 'open_guard' => null]);
    }

    public function testABusinessHasOnlyOneLiveSubscription(): void
    {
        $version = $this->version('299.00', '2026-01-01 00:00:00');
        $first   = $this->subscription($version, 'A', 'sub000000001');

        foreach (['P', 'A', 'S'] as $status) {
            try {
                $this->subscription($version, $status, 'sub00000000' . $status);
                $this->fail('A second live subscription (' . $status . ') was accepted');
            } catch (DatabaseException) {
                // expected
            }
        }

        // once the first has ended, a new one can start, and ended ones can pile up
        $this->db->table('subscription_master')->where('id', $first)->update(['subscription_status' => 'X']);
        $this->subscription($version, 'C', 'sub000000002');
        $this->subscription($version, 'P', 'sub000000003');

        $this->seeNumRecords(3, 'subscription_master', ['business_id' => $this->businessId]);
    }

    private function version(string $price, string $from, string $cycle = 'M', string $country = 'TH', string $currency = 'THB'): int
    {
        $this->db->table('plan_version')->insert([
            'public_id'      => substr(md5($price . $from . $cycle . $country), 0, 8),
            'plan_id'        => $this->planId,
            'country_code'   => $country,
            'currency_code'  => $currency,
            'billing_cycle'  => $cycle,
            'price'          => $price,
            'effective_from' => $from,
        ]);

        return (int) $this->db->insertID();
    }

    private function subscription(int $versionId, string $status, string $publicId): int
    {
        $this->db->table('subscription_master')->insert([
            'public_id'           => $publicId,
            'business_id'         => $this->businessId,
            'plan_version_id'     => $versionId,
            'subscription_status' => $status,
        ]);

        return (int) $this->db->insertID();
    }
}
