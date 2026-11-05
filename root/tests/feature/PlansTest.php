<?php

use CodeIgniter\I18n\Time;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\Services;

/**
 * /staff/plans/*: the Executive Office and Finance keep the plans, their prices, the payment methods and the
 * discount codes
 *
 * @internal
 */
final class PlansTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'App'; // the app's own migrations, the schema of the real database

    private string $token;
    private array $departments = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['jwt', 'authContext'] as $service) {
            Services::resetSingle($service);
        }
        Time::setTestNow('2026-11-10 05:00:00', 'UTC');
        foreach (['EXE' => 'Executive Office', 'FIN' => 'Finance', 'ENG' => 'Engineering'] as $code => $name) {
            $this->db->table('otternova_department')->insert(['department_code' => $code, 'department_name' => $name, 'country_code' => 'TH']);
            $this->departments[$code] = (int) $this->db->insertID();
        }
        $this->token = service('jwt')->issue($this->createStaff('fin@otternova.com', 'FIN'))['access_token'];
        $this->db->table('plan_master')->insert(['plan_code' => 'free', 'plan_tier' => 0]);
    }

    protected function tearDown(): void
    {
        Time::setTestNow();
        parent::tearDown();
    }

    public function testOnlyExecutiveOfficeAndFinanceGetIn(): void
    {
        $engineer = service('jwt')->issue($this->createStaff('eng@otternova.com', 'ENG'))['access_token'];
        $this->send('v1/staff/plans/plans', [], $engineer)->assertStatus(403);
        $this->withHeaders([])->post('v1/staff/plans/plans')->assertStatus(401);
        $this->send('v1/staff/plans/plans', [])->assertStatus(200);
    }

    public function testPlansAreCreatedAndEdited(): void
    {
        $this->send('v1/staff/plans/plan/create', ['plan_code' => 'Boost', 'plan_description' => 'For a growing shop', 'plan_tier' => 1])->assertStatus(200);
        $this->send('v1/staff/plans/plan/create', ['plan_code' => 'velocity', 'plan_tier' => 2])->assertStatus(200);

        // a code or a tier taken
        $response = $this->send('v1/staff/plans/plan/create', ['plan_code' => 'boost', 'plan_tier' => 2]);
        $response->assertStatus(422);
        $this->assertSame(['plan_code', 'plan_tier'], array_keys($this->body($response)['errors']));

        // a field left out keeps its value
        $this->send('v1/staff/plans/plan/edit', ['plan_code' => 'boost', 'plan_tier' => 5])->assertStatus(200);
        $rows = $this->body($this->send('v1/staff/plans/plans', []))['data']['rows'];
        $this->assertSame([['free', 0, null], ['velocity', 2, null], ['boost', 5, 'For a growing shop']],
            array_map(static fn ($row) => [$row['plan_code'], $row['plan_tier'], $row['plan_description']], $rows));

        $response = $this->send('v1/staff/plans/plan/edit', ['plan_code' => 'free', 'plan_tier' => 9]);
        $response->assertStatus(422);
        $this->assertSame(lang('Plans.freePlanTier'), $this->body($response)['errors']['plan_tier']);
        $this->send('v1/staff/plans/plan/edit', ['plan_code' => 'nope'])->assertStatus(404);
        $this->seeInDatabase('log_activity', ['table_involved' => 'plan_master', 'activity_summary' => 'Updated plan boost']);
    }

    public function testAPriceChangeEndsTheOpenPriceWhenItStarts(): void
    {
        $this->send('v1/staff/plans/plan/create', ['plan_code' => 'boost', 'plan_tier' => 1])->assertStatus(200);
        $first = $this->version(['price' => 299, 'effective_from' => '2026-01-01T00:00:00+07:00']);

        // Bangkok 2026-11-15 00:00 is 2026-11-14 17:00 UTC
        $second = $this->version(['price' => '399', 'effective_from' => '2026-11-15T00:00:00+07:00']);
        $this->seeInDatabase('plan_version', ['public_id' => $first, 'effective_from' => '2025-12-31 17:00:00', 'effective_to' => '2026-11-14 17:00:00']);
        $this->seeInDatabase('plan_version', ['public_id' => $second, 'price' => '399.00', 'effective_to' => null]);

        $rows = $this->body($this->send('v1/staff/plans/versions', ['plan_code' => 'boost']))['data']['rows'];
        $this->assertSame([['399.00', 'S'], ['299.00', 'C']], array_map(static fn ($row) => [$row['price'], $row['state']], $rows));

        // in the past, before the last change, in another currency, with no offset, for the free plan
        $response = $this->send('v1/staff/plans/version/create', $this->versionBody(['price' => 1, 'effective_from' => '2026-11-01T00:00:00+07:00', 'currency_code' => 'USD']));
        $response->assertStatus(422);
        $this->assertSame(['currency_code', 'effective_from'], array_keys($this->body($response)['errors']));
        $response = $this->send('v1/staff/plans/version/create', $this->versionBody(['plan_code' => 'free', 'price' => 0, 'effective_from' => '2026-12-01 00:00:00']));
        $this->assertEqualsCanonicalizing(['plan_code', 'price', 'effective_from'], array_keys($this->body($response)['errors']));

        // the scheduled change is taken back, and the old price runs on
        $this->send('v1/staff/plans/version/cancel', ['public_id' => $second])->assertStatus(200);
        $this->dontSeeInDatabase('plan_version', ['public_id' => $second]);
        $this->seeInDatabase('plan_version', ['public_id' => $first, 'effective_to' => null]);
        $this->send('v1/staff/plans/version/cancel', ['public_id' => $first])->assertStatus(409);
    }

    public function testPaymentMethodsAreCreatedAndEdited(): void
    {
        $this->send('v1/staff/plans/payment-method/create', ['method_code' => 'promptpay', 'country_code' => 'th', 'currency_code' => 'thb', 'confirmation_mode' => 'M', 'sort_order' => 1])->assertStatus(200);
        $response = $this->send('v1/staff/plans/payment-method/create', ['method_code' => 'card', 'currency_code' => 'USD', 'confirmation_mode' => 'A']);
        $response->assertStatus(422);
        $this->assertSame(['provider_code' => lang('Plans.providerRequired')], $this->body($response)['errors']);

        $this->send('v1/staff/plans/payment-method/edit', ['method_code' => 'promptpay', 'is_active' => 'N'])->assertStatus(200);
        $rows = $this->body($this->send('v1/staff/plans/payment-methods', []))['data']['rows'];
        $this->assertSame([['promptpay', 'TH', 'THB', 'N', 1]], array_map(static fn ($row) => [$row['method_code'], $row['country_code'], $row['currency_code'], $row['is_active'], $row['sort_order']], $rows));
    }

    public function testDiscountsAreCheckedSavedAndListed(): void
    {
        $this->send('v1/staff/plans/plan/create', ['plan_code' => 'boost', 'plan_tier' => 1])->assertStatus(200);

        // a percentage with a currency, over 100, expiring before it starts
        $response = $this->send('v1/staff/plans/discount/update', $this->discountBody(['discount_value' => '120', 'currency_code' => 'THB', 'expires_at' => '2026-10-01T00:00:00Z']));
        $response->assertStatus(422);
        $this->assertSame(['discount_value', 'currency_code', 'expires_at'], array_keys($this->body($response)['errors']));

        // a fixed amount needs its currency and country; the plan and business must exist
        $response = $this->send('v1/staff/plans/discount/update', $this->discountBody(['discount_type' => 'F', 'discount_value' => '100', 'country_code' => '', 'plan_code' => 'nope', 'business_slug' => 'nobody']));
        $this->assertSame(['business_slug', 'plan_code', 'currency_code', 'country_code'], array_keys($this->body($response)['errors']));

        $response = $this->send('v1/staff/plans/discount/update', $this->discountBody(['discount_code' => 'welcome10', 'plan_code' => 'boost']));
        $response->assertStatus(200);
        $publicId = $this->body($response)['data']['public_id'];
        $this->seeInDatabase('discount_master', ['public_id' => $publicId, 'discount_code' => 'WELCOME10', 'discount_value' => '10.00', 'max_per_business' => 1, 'expires_at' => '2026-12-31 17:00:00']);

        $this->send('v1/staff/plans/discount/update', $this->discountBody(['discount_code' => 'WELCOME10']))->assertStatus(422); // taken
        $this->send('v1/staff/plans/discount/update', $this->discountBody(['public_id' => $publicId, 'discount_code' => 'WELCOME10', 'max_per_business' => '']))->assertStatus(200);
        $discount = $this->body($this->send('v1/staff/plans/discount', ['public_id' => $publicId]))['data']['discount'];
        $this->assertSame(['WELCOME10', null, null, 'P'], [$discount['discount_code'], $discount['plan_code'], $discount['max_per_business'], $discount['discount_type']]);

        $list = $this->body($this->send('v1/staff/plans/discounts', ['search' => 'welcome']))['data'];
        $this->assertSame([1, 1], [$list['records_total'], $list['records_filtered']]);
    }

    private function version(array $fields): string
    {
        $response = $this->send('v1/staff/plans/version/create', $this->versionBody($fields));
        $response->assertStatus(200);

        return $this->body($response)['data']['public_id'];
    }

    private function versionBody(array $fields): array
    {
        return $fields + ['plan_code' => 'boost', 'country_code' => 'TH', 'currency_code' => 'THB', 'billing_cycle' => 'M'];
    }

    private function discountBody(array $fields): array
    {
        return $fields + [
            'discount_code'        => 'NEWYEAR',
            'discount_description' => '10% off your first month',
            'discount_type'        => 'P',
            'discount_value'       => '10',
            'currency_code'        => '',
            'country_code'         => 'TH',
            'business_slug'        => '',
            'plan_code'            => '',
            'billing_cycle'        => '',
            'is_listed'            => 'Y',
            'max_redemptions'      => '100',
            'valid_from'           => '2026-11-01T00:00:00+07:00',
            'expires_at'           => '2027-01-01T00:00:00+07:00',
        ];
    }

    private function createStaff(string $email, string $department): array
    {
        $account = [
            'uid'             => preg_replace('/[^a-z0-9]/', '', $email),
            'email_address'   => $email,
            'password_hash'   => password_hash('correct horse battery staple', PASSWORD_DEFAULT),
            'password_expiry' => '2027-01-01',
            'full_name'       => 'Otter Tester',
            'otp_mode'        => 'X',
            'account_status'  => 'A',
            'account_type'    => 'S',
        ];
        $this->db->table('auth_master')->insert($account);
        $account['id'] = $this->db->insertID();
        $this->db->table('staff_master')->insert([
            'auth_id'               => $account['id'],
            'department_id'         => $this->departments[$department],
            'employee_code'         => 'EMP-' . $account['id'],
            'job_title'             => 'Officer',
            'employment_status'     => 'A',
            'employment_start_date' => '2024-01-01',
            'work_country_code'     => 'TH',
            'legal_first_name'      => 'Otter',
            'legal_last_name'       => 'Tester',
        ]);

        return $account;
    }

    private function send(string $path, array $body, ?string $token = null): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . ($token ?? $this->token)])
            ->withBodyFormat('json')
            ->post($path, $body);
    }

    private function body(TestResponse $response): array
    {
        return json_decode($response->getJSON(), true);
    }
}
