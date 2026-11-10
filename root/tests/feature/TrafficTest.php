<?php

use App\Libraries\GeoLocator;
use App\Libraries\TrackerMerger;
use App\Libraries\TrackerRollup;
use CodeIgniter\Config\Factories;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\Services;
use Config\Tracker as TrackerConfig;
use Tests\Support\ArtifactFixturesTrait;

/**
 * /staff/traffic/* and /client/traffic/*: the traffic the tracker counted
 *
 * @internal
 */
final class TrafficTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use ArtifactFixturesTrait;

    private const PERIOD = ['from' => '2026-11-02', 'to' => '2026-11-03'];

    protected $namespace = 'App'; // the app's own migrations, the schema of the real database

    private string $ownerToken;
    private string $staffToken;
    private int $businessId;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['jwt', 'authContext'] as $service) {
            Services::resetSingle($service);
        }
        Factories::reset('config');
        $this->businessId = $this->business('somchai-cafe');
        $this->ownerToken = $this->createClient('owner@example.com', $this->businessId, 'O');
        $this->staffToken = $this->createStaff('staff@otternova.com');
        $this->seedTraffic();
    }

    public function testWebsiteOverviewForStaff(): void
    {
        $data = $this->data($this->send('v1/staff/traffic/overview', self::PERIOD, $this->staffToken));

        $this->assertSame('Asia/Bangkok', $data['timezone']);
        $this->assertNull($data['currency_code']);
        $this->assertArrayNotHasKey('funnel', $data);
        $this->assertArrayNotHasKey('conversion_rate', $data['totals']);
        $totals = $data['totals'];
        $this->assertSame([3, 4, 2, 2], [$totals['visits'], $totals['pageviews'], $totals['visitors'], $totals['new_visitors']]);
        $this->assertEqualsWithDelta(2 / 3, $totals['bounce_rate'], 0.001);
        $this->assertSame(['2026-11-02', '2026-11-03'], array_column($data['series'], 'day'));
        $this->assertSame([2, 1], array_column($data['series'], 'visits'));
        $this->assertSame(['2026-10-31', '2026-11-01'], [$data['previous']['from'], $data['previous']['to']]);
        $this->assertSame(1, $data['previous']['visits']);
    }

    public function testBusinessOverviewHasTheFunnel(): void
    {
        $owner = $this->data($this->send('v1/client/traffic/overview', self::PERIOD + ['business_slug' => 'somchai-cafe']));
        $staff = $this->data($this->send('v1/staff/traffic/overview', self::PERIOD + ['site' => 'mart', 'business_slug' => 'somchai-cafe'], $this->staffToken));

        $this->assertSame($owner, $staff);
        $this->assertSame('THB', $owner['currency_code']);
        $this->assertSame(2, $owner['totals']['visits']);
        $funnel = $owner['funnel'];
        $this->assertSame(['visits', 'view_item', 'add_to_cart', 'checkout_start', 'checkout_confirm'], array_column($funnel['steps'], 'step'));
        $this->assertSame([2, 2, 1, 1, 1], array_column($funnel['steps'], 'visits'));
        $this->assertEquals([null, 1.0, 0.5, 1.0, 1.0], array_column($funnel['steps'], 'rate_from_previous')); // JSON writes 1.0 as 1
        $this->assertSame(1, $funnel['orders']);
        $this->assertEqualsWithDelta(200.0, $funnel['revenue'], 0.001);
        $this->assertEqualsWithDelta(0.5, $funnel['conversion_rate'], 0.001);
        // the period's sales sit with the other totals, and the period before's with its own, for the change
        $this->assertSame(1, $owner['totals']['orders']);
        $this->assertEqualsWithDelta(0.5, $owner['totals']['conversion_rate'], 0.001);
        $this->assertSame(0, $owner['previous']['orders']);
        $this->assertEqualsWithDelta(0.0, $owner['previous']['conversion_rate'], 0.001);
    }

    public function testWholeMarketplaceForStaff(): void
    {
        $data = $this->data($this->send('v1/staff/traffic/overview', self::PERIOD + ['site' => 'mart'], $this->staffToken));

        $this->assertSame(2, $data['totals']['visits']);
        $this->assertNull($data['currency_code']);

        $top = $this->data($this->send('v1/staff/traffic/businesses', self::PERIOD, $this->staffToken))['businesses'];
        $this->assertEquals([['business_slug' => 'somchai-cafe', 'business_name' => 'somchai-cafe', 'currency_code' => 'THB',
            'visits' => 2, 'pageviews' => 2, 'orders' => 1, 'revenue' => 200.0, 'conversion_rate' => 0.5]], $top);
    }

    public function testBreakdowns(): void
    {
        $pages = $this->data($this->send('v1/staff/traffic/breakdown', self::PERIOD + ['dimension' => 'path'], $this->staffToken))['rows'];
        $this->assertSame(['/', '/pricing'], array_column($pages, 'value'));
        $this->assertSame([3, 1], array_column($pages, 'pageviews'));
        $this->assertSame(40, $pages[1]['avg_time_on_page_s']);

        $referrers = $this->data($this->send('v1/staff/traffic/breakdown', self::PERIOD + ['dimension' => 'referrer'], $this->staffToken))['rows'];
        $this->assertSame(['google.com'], array_column($referrers, 'value'));

        $sources = $this->data($this->send('v1/staff/traffic/breakdown', self::PERIOD + ['dimension' => 'source', 'limit' => 1], $this->staffToken))['rows'];
        $this->assertEquals([['value' => 'direct', 'visits' => 2, 'pageviews' => 2, 'bounce_rate' => 1.0, 'avg_visit_s' => 0, 'orders' => 0, 'revenue' => 0.0]], $sources);

        $countries = $this->data($this->send('v1/client/traffic/breakdown', self::PERIOD + ['business_slug' => 'somchai-cafe', 'dimension' => 'country']))['rows'];
        $this->assertSame([['value' => 'TH', 'visits' => 2]], array_map(static fn (array $r): array => array_intersect_key($r, ['value' => 1, 'visits' => 1]), $countries));

        $this->send('v1/staff/traffic/breakdown', self::PERIOD + ['dimension' => 'weather'], $this->staffToken)->assertStatus(422);
    }

    public function testVisitsShowTheIpAddressToStaffOnly(): void
    {
        $staff = $this->data($this->send('v1/staff/traffic/visits', self::PERIOD + ['length' => 2], $this->staffToken));
        $this->assertSame(3, $staff['records_total']);
        $this->assertCount(2, $staff['rows']);
        $this->assertSame('2026-11-02 23:00:00', $staff['rows'][0]['started_at']); // newest first, UTC
        $this->assertTrue($staff['rows'][0]['is_returning']);
        $this->assertSame('203.0.113.77', $staff['rows'][0]['ip_address']);

        $last = $this->data($this->send('v1/staff/traffic/visits', self::PERIOD + ['start' => 2], $this->staffToken))['rows'];
        $this->assertSame([['path' => '/', 'views' => 1, 'visible_s' => 0], ['path' => '/pricing', 'views' => 1, 'visible_s' => 40]], $last[0]['pages']);
        $this->assertSame('google.com', $last[0]['referrer_host']);
        $this->assertFalse($last[0]['is_returning']);

        $client = $this->data($this->send('v1/client/traffic/visits', self::PERIOD + ['business_slug' => 'somchai-cafe']))['rows'];
        $this->assertCount(2, $client);
        $this->assertArrayNotHasKey('ip_address', $client[0]);
        $this->assertSame(4, max(array_column($client, 'funnel_stage')));
    }

    public function testOnlyOwnersAndManagersOfTheBusiness(): void
    {
        $employee = $this->createClient('staff@cafe.example', $this->businessId, 'E');
        $stranger = $this->createClient('other@example.com', $this->business('other-shop'), 'O');
        $body     = self::PERIOD + ['business_slug' => 'somchai-cafe'];

        $this->withHeaders([])->post('v1/client/traffic/overview')->assertStatus(401);
        $this->send('v1/client/traffic/overview', self::PERIOD)->assertStatus(400);
        $this->send('v1/client/traffic/overview', $body, $employee)->assertStatus(403);
        $this->send('v1/client/traffic/overview', $body, $stranger)->assertStatus(404);
        $this->send('v1/staff/traffic/overview', self::PERIOD)->assertStatus(403); // a client's token
        $this->send('v1/staff/traffic/overview', ['site' => 'mart', 'business_slug' => 'nope'], $this->staffToken)->assertStatus(404);
        $this->send('v1/staff/traffic/overview', ['site' => 'app'], $this->staffToken)->assertStatus(422);
    }

    public function testThePeriod(): void
    {
        $this->send('v1/staff/traffic/overview', ['from' => '2026-11-05', 'to' => '2026-11-01'], $this->staffToken)->assertStatus(422);
        $this->send('v1/staff/traffic/overview', ['from' => '2026-02-30'], $this->staffToken)->assertStatus(422);
        $this->send('v1/staff/traffic/overview', ['from' => '2024-01-01', 'to' => '2026-11-01'], $this->staffToken)->assertStatus(422);

        $data = $this->data($this->send('v1/staff/traffic/overview', [], $this->staffToken));
        $this->assertCount(30, $data['series']); // the last 30 days by default
    }

    /**
     * Visits merged and rolled up, all from 203.0.113.77 (Bangkok); times UTC, 7 hours behind Bangkok
     */
    private function seedTraffic(): void
    {
        $shop = ['site' => 'mart', 'business_id' => $this->businessId];
        // the period before: one www visit on 1 Nov
        $this->raw(['visitor_id' => 'VisitorZZZZZZZZZ', 'session_id' => 'SessionZZZZZZZZZ', 'occurred_at' => '2026-11-01 03:00:00']);
        // www, 2 Nov: A from Google reads two pages; B bounces
        $this->raw(['visitor_id' => 'VisitorAAAAAAAAA', 'session_id' => 'SessionAAAAAAAAA', 'occurred_at' => '2026-11-02 02:00:00', 'referrer' => 'https://www.google.com/']);
        $this->raw(['visitor_id' => 'VisitorAAAAAAAAA', 'session_id' => 'SessionAAAAAAAAA', 'occurred_at' => '2026-11-02 02:00:10', 'path' => '/pricing']);
        $this->raw(['visitor_id' => 'VisitorAAAAAAAAA', 'session_id' => 'SessionAAAAAAAAA', 'occurred_at' => '2026-11-02 02:00:50', 'path' => '/pricing',
            'event_type' => 'engage', 'payload' => ['visible_ms' => 40000, 'scroll_pct' => 80]]);
        $this->raw(['visitor_id' => 'VisitorBBBBBBBBB', 'session_id' => 'SessionBBBBBBBBB', 'occurred_at' => '2026-11-02 05:00:00']);
        // www, 3 Nov in Bangkok (23:00 UTC on 2 Nov): A comes back and bounces
        $this->raw(['visitor_id' => 'VisitorAAAAAAAAA', 'session_id' => 'SessionCCCCCCCCC', 'occurred_at' => '2026-11-02 23:00:00']);
        // the shop, 2 Nov: C buys, D only looks
        foreach (['pageview' => null, 'view_item' => null, 'add_to_cart' => null, 'checkout_start' => null, 'checkout_confirm' => ['value' => 200, 'currency' => 'THB']] as $step => $props) {
            $this->raw($shop + ['visitor_id' => 'VisitorCCCCCCCCC', 'session_id' => 'SessionDDDDDDDDD', 'occurred_at' => '2026-11-02 04:00:00', 'path' => '/somchai-cafe/product/Ab3x',
                'event_type' => $step === 'pageview' ? 'pageview' : 'event', 'event_name' => $step === 'pageview' ? null : $step,
                'payload' => $props === null ? [] : ['props' => $props]]);
        }
        foreach (['pageview', 'view_item'] as $step) {
            $this->raw($shop + ['visitor_id' => 'VisitorDDDDDDDDD', 'session_id' => 'SessionEEEEEEEEE', 'occurred_at' => '2026-11-02 06:00:00', 'path' => '/somchai-cafe/product/Ab3x',
                'event_type' => $step === 'pageview' ? 'pageview' : 'event', 'event_name' => $step === 'pageview' ? null : $step]);
        }

        $config = config(TrackerConfig::class);
        $geo    = new class ('') extends GeoLocator {
            public function locate(string $ip): ?array
            {
                return ['country_code' => 'TH', 'subdivision_code' => '10', 'city' => 'Bangkok', 'latitude' => 13.75, 'longitude' => 100.5];
            }
        };
        (new TrackerMerger($config, $geo, $this->db))->processBatch();
        $rollup = new TrackerRollup($config, $this->db);
        foreach (['2026-10-31', '2026-11-01', '2026-11-02', '2026-11-03'] as $day) {
            $rollup->rollupDay($day);
        }
    }

    private function raw(array $fields): void
    {
        $row = $fields + [
            'site'            => 'www',
            'business_id'     => null,
            'event_type'      => 'pageview',
            'event_name'      => null,
            'path'            => '/',
            'referrer'        => null,
            'ip_address'      => '203.0.113.77',
            'user_agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36',
            'accept_language' => 'th-TH',
            'payload'         => [],
            'processed_at'    => null,
        ];
        $row['received_at'] = $row['occurred_at'];
        $row['payload']     = $row['payload'] === [] ? null : json_encode($row['payload']);
        $this->db->table('tracker_event_raw')->insert($row);
    }

    private function createStaff(string $email): string
    {
        $this->db->table('otternova_department')->insert(['department_code' => 'SAL', 'department_name' => 'Sales', 'country_code' => 'TH']);
        $departmentId = (int) $this->db->insertID();
        $account      = [
            'uid'             => preg_replace('/[^a-z0-9]/', '', $email),
            'email_address'   => $email,
            'password_hash'   => 'x',
            'password_expiry' => '2027-01-01',
            'full_name'       => 'Otter Staff',
            'otp_mode'        => 'X',
            'account_status'  => 'A',
            'account_type'    => 'S',
        ];
        $this->db->table('auth_master')->insert($account);
        $account['id'] = (int) $this->db->insertID();
        $this->db->table('staff_master')->insert([
            'auth_id'               => $account['id'],
            'department_id'         => $departmentId,
            'employee_code'         => 'EMP-' . $account['id'],
            'job_title'             => 'Officer',
            'employment_status'     => 'A',
            'employment_start_date' => '2024-01-01',
            'work_country_code'     => 'TH',
            'legal_first_name'      => 'Otter',
            'legal_last_name'       => 'Staff',
        ]);

        return service('jwt')->issue($account)['access_token'];
    }

    private function data(TestResponse $response): array
    {
        $response->assertStatus(200);

        return $this->body($response)['data'];
    }
}
