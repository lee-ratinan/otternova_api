<?php

use App\Libraries\GeoLocator;
use App\Libraries\TrackerMerger;
use App\Libraries\TrackerRollup;
use CodeIgniter\Config\Factories;
use CodeIgniter\I18n\Time;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Tracker as TrackerConfig;
use Tests\Support\ArtifactFixturesTrait;

/**
 * The tracker jobs: merging raw events into visits (tracker:process) and the daily totals (tracker:rollup)
 *
 * @internal
 */
final class TrackerJobsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use ArtifactFixturesTrait;

    private const IPHONE  = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1';
    private const DESKTOP = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36';

    protected $namespace = 'App'; // the app's own migrations, the schema of the real database

    private TrackerConfig $config;
    private TrackerMerger $merger;

    protected function setUp(): void
    {
        parent::setUp();

        Factories::reset('config');
        $this->config = config(TrackerConfig::class);
        $this->merger = new TrackerMerger($this->config, $this->geo(), $this->db);
    }

    public function testMergesASessionSentInSeveralBatchesOverSeveralRuns(): void
    {
        $businessId = $this->business('somchai-cafe');
        $shop       = ['site' => 'mart', 'business_id' => $businessId];
        $product    = '/somchai-cafe/product/Ab3xK9';

        $this->raw($shop + ['event_type' => 'pageview', 'path' => $product, 'occurred_at' => '2026-11-09 10:00:00',
            'referrer' => 'https://www.google.co.th/', 'payload' => ['language' => 'th-TH', 'screen' => '390x844', 'timezone' => 'Asia/Bangkok']]);
        $this->raw($shop + ['event_type' => 'event', 'event_name' => 'view_item', 'path' => $product, 'occurred_at' => '2026-11-09 10:00:01']);
        $this->raw($shop + ['event_type' => 'engage', 'path' => $product, 'occurred_at' => '2026-11-09 10:01:00', 'payload' => ['visible_ms' => 59400, 'scroll_pct' => 70]]);
        $this->assertSame(3, $this->merger->processBatch());

        $this->raw($shop + ['event_type' => 'event', 'event_name' => 'add_to_cart', 'path' => $product, 'occurred_at' => '2026-11-09 10:01:05']);
        $this->raw($shop + ['event_type' => 'engage', 'path' => $product, 'occurred_at' => '2026-11-09 10:01:06', 'payload' => ['visible_ms' => 5600, 'scroll_pct' => 90]]);
        $this->raw($shop + ['event_type' => 'pageview', 'path' => '/somchai-cafe/checkout', 'occurred_at' => '2026-11-09 10:01:07', 'referrer' => 'https://evil.example/']);
        $this->raw($shop + ['event_type' => 'event', 'event_name' => 'checkout_start', 'path' => '/somchai-cafe/checkout', 'occurred_at' => '2026-11-09 10:01:08']);
        $this->raw($shop + ['event_type' => 'event', 'event_name' => 'checkout_confirm', 'path' => '/somchai-cafe/checkout', 'occurred_at' => '2026-11-09 10:03:00',
            'payload' => ['props' => ['order' => 'Zq81', 'value' => 360.5, 'currency' => 'THB']]]);
        $this->assertSame(5, $this->merger->processBatch());
        $this->assertSame(0, $this->merger->processBatch());

        $visits = $this->db->table('tracker_visit')->get()->getResultArray();
        $this->assertCount(1, $visits);
        $visit = $visits[0];
        $this->assertSame($businessId, (int) $visit['business_id']);
        $this->assertSame('2026-11-09 10:00:00', $visit['started_at']);
        $this->assertSame('2026-11-09 10:03:00', $visit['ended_at']);
        $this->assertSame(65, (int) $visit['duration_s']);
        $this->assertSame(2, (int) $visit['pageviews']);
        $this->assertSame(4, (int) $visit['events']);
        $this->assertSame(0, (int) $visit['is_bounce']);
        $this->assertSame($product, $visit['entry_path']);
        $this->assertSame('/somchai-cafe/checkout', $visit['exit_path']);
        $this->assertSame('google.co.th', $visit['referrer_host']); // the landing page's, not a later one's
        $this->assertSame('search', $visit['source_type']);
        $this->assertSame(4, (int) $visit['funnel_stage']);
        $this->assertEqualsWithDelta(360.5, (float) $visit['order_value'], 0.001);
        $this->assertSame('THB', $visit['order_currency']);
        $this->assertSame('TH', $visit['country_code']);
        $this->assertSame('10', $visit['subdivision_code']);
        $this->assertSame('Bangkok', $visit['city']);
        $this->assertSame('mobile', $visit['device_type']);
        $this->assertSame('Safari', $visit['browser']);
        $this->assertSame('iOS', $visit['os']);
        $this->assertSame('th-TH', $visit['language']);
        $this->assertSame('390x844', $visit['screen']);
        $this->assertSame('Asia/Bangkok', $visit['timezone']);

        $pages = $this->db->table('tracker_visit_page')->orderBy('first_viewed_at')->get()->getResultArray();
        $this->assertSame([$product, '/somchai-cafe/checkout'], array_column($pages, 'path'));
        $this->assertSame([1, 1], array_map('intval', array_column($pages, 'views')));
        $this->assertSame([65, 0], array_map('intval', array_column($pages, 'visible_s')));
        $this->assertSame(90, (int) $pages[0]['max_scroll_pct']);

        $visitor = $this->db->table('tracker_visitor')->get()->getRowArray();
        $this->assertSame(1, (int) $visitor['visits']);
        $this->assertSame(2, (int) $visitor['pageviews']);
        $this->assertSame('google.co.th', $visitor['first_referrer_host']);
        $this->assertSame('TH', $visitor['last_country_code']);
        $this->assertSame('2026-11-09 10:03:00', $visitor['last_seen_at']);

        $this->assertSame(0, $this->db->table('tracker_event_raw')->where('processed_at', null)->countAllResults());
    }

    public function testCountsAReturningVisitorAndTellsSourcesApart(): void
    {
        $this->raw(['session_id' => 'SessionAAAAAAAAA', 'occurred_at' => '2026-11-01 08:00:00', 'payload' => ['utm' => ['source' => 'line', 'campaign' => 'launch']]]);
        $this->raw(['session_id' => 'SessionBBBBBBBBB', 'occurred_at' => '2026-11-05 08:00:00', 'referrer' => 'https://m.facebook.com/']);
        $this->raw(['session_id' => 'SessionCCCCCCCCC', 'occurred_at' => '2026-11-06 08:00:00', 'referrer' => 'https://blog.example.org/post']);
        $this->raw(['session_id' => 'SessionDDDDDDDDD', 'occurred_at' => '2026-11-07 08:00:00', 'user_agent' => self::DESKTOP, 'ip_address' => '10.0.0.1']);
        $this->merger->processBatch();

        $visits = $this->db->table('tracker_visit')->orderBy('started_at')->get()->getResultArray();
        $this->assertSame(['campaign', 'social', 'referral', 'direct'], array_column($visits, 'source_type'));
        $this->assertSame(['line', null, null, null], array_column($visits, 'utm_source'));
        $this->assertSame([1, 1, 1, 1], array_map('intval', array_column($visits, 'is_bounce')));
        $this->assertSame(0, (int) $visits[0]['business_id']); // www
        $this->assertSame('desktop', $visits[3]['device_type']);
        $this->assertNull($visits[3]['country_code']); // a private address is nowhere

        $visitor = $this->db->table('tracker_visitor')->get()->getResultArray();
        $this->assertCount(1, $visitor);
        $this->assertSame(4, (int) $visitor[0]['visits']);
        $this->assertSame('2026-11-01 08:00:00', $visitor[0]['first_seen_at']);
        $this->assertSame('2026-11-07 08:00:00', $visitor[0]['last_seen_at']);
        $this->assertSame('line', $visitor[0]['first_utm_source']);
    }

    public function testTheSameBrowserIsAVisitorOfEachBusinessApart(): void
    {
        $first  = $this->business('first-shop');
        $second = $this->business('second-shop');
        $this->raw(['site' => 'mart', 'business_id' => $first, 'session_id' => 'SessionAAAAAAAAA']);
        $this->raw(['site' => 'mart', 'business_id' => $second, 'session_id' => 'SessionBBBBBBBBB']);
        $this->merger->processBatch();

        $this->assertSame(2, $this->db->table('tracker_visitor')->countAllResults());
    }

    public function testProcessesInRoundsOfTheConfiguredSize(): void
    {
        $this->config->processBatchSize = 2;
        for ($i = 0; $i < 5; $i++) {
            $this->raw(['occurred_at' => '2026-11-09 10:00:0' . $i, 'path' => '/page-' . $i]);
        }

        $this->assertSame([2, 2, 1, 0], [$this->merger->processBatch(), $this->merger->processBatch(), $this->merger->processBatch(), $this->merger->processBatch()]);
        $this->assertSame(5, (int) $this->db->table('tracker_visit')->get()->getRowArray()['pageviews']);
        $this->assertSame(5, $this->db->table('tracker_visit_page')->countAllResults());
    }

    public function testDeletesRawEventsSomeDaysAfterTheyWereMerged(): void
    {
        $this->raw(['processed_at' => '2026-11-01 00:00:00']);
        $this->raw(['processed_at' => '2026-11-08 00:00:00']);
        $this->raw();

        $this->assertSame(1, $this->merger->deleteProcessed(Time::parse('2026-11-09 00:00:00', 'UTC')));
        $this->assertSame(2, $this->db->table('tracker_event_raw')->countAllResults());
    }

    public function testRollsUpADay(): void
    {
        $businessId = $this->business('somchai-cafe');
        $shop       = ['site' => 'mart', 'business_id' => $businessId];
        // a returning visitor first seen the day before: buys
        $this->raw($shop + ['visitor_id' => 'VisitorAAAAAAAAA', 'session_id' => 'SessionAAAAAAAAA', 'occurred_at' => '2026-11-08 09:00:00', 'path' => '/somchai-cafe']);
        $this->raw($shop + ['visitor_id' => 'VisitorAAAAAAAAA', 'session_id' => 'SessionBBBBBBBBB', 'occurred_at' => '2026-11-09 09:00:00', 'path' => '/somchai-cafe/product/Ab3x']);
        $this->raw($shop + ['visitor_id' => 'VisitorAAAAAAAAA', 'session_id' => 'SessionBBBBBBBBB', 'occurred_at' => '2026-11-09 09:01:00', 'path' => '/somchai-cafe/product/Ab3x',
            'event_type' => 'event', 'event_name' => 'checkout_confirm', 'payload' => ['props' => ['value' => 100, 'currency' => 'THB']]]);
        // a new visitor: adds to cart, leaves
        $this->raw($shop + ['visitor_id' => 'VisitorBBBBBBBBB', 'session_id' => 'SessionCCCCCCCCC', 'occurred_at' => '2026-11-09 12:00:00', 'path' => '/somchai-cafe/product/Ab3x']);
        $this->raw($shop + ['visitor_id' => 'VisitorBBBBBBBBB', 'session_id' => 'SessionCCCCCCCCC', 'occurred_at' => '2026-11-09 12:00:30', 'path' => '/somchai-cafe/product/Ab3x',
            'event_type' => 'event', 'event_name' => 'add_to_cart']);
        // a new visitor who bounces, from a desktop
        $this->raw($shop + ['visitor_id' => 'VisitorCCCCCCCCC', 'session_id' => 'SessionDDDDDDDDD', 'occurred_at' => '2026-11-09 23:59:59', 'path' => '/somchai-cafe', 'user_agent' => self::DESKTOP]);
        // the website, the next day
        $this->raw(['visitor_id' => 'VisitorDDDDDDDDD', 'session_id' => 'SessionEEEEEEEEE', 'occurred_at' => '2026-11-10 00:00:00']);
        $this->merger->processBatch();

        $rollup = new TrackerRollup($this->config, $this->db);
        $this->assertGreaterThan(0, $rollup->rollupDay('2026-11-09'));
        $written = $rollup->rollupDay('2026-11-09'); // again: replaces, does not add

        $rows = $this->db->table('tracker_daily')->where('day', '2026-11-09')->get()->getResultArray();
        $this->assertCount($written, $rows);
        $find = static function (string $dimension, string $value) use ($rows): array {
            foreach ($rows as $row) {
                if ($row['dimension'] === $dimension && $row['dimension_value'] === $value) {
                    return $row;
                }
            }
            self::fail('no ' . $dimension . ' row for ' . $value);
        };

        $total = $find('total', '');
        $this->assertSame('mart', $total['site']);
        $this->assertSame($businessId, (int) $total['business_id']);
        $this->assertSame(
            ['pageviews' => 3, 'visits' => 3, 'visitors' => 3, 'new_visitors' => 2, 'bounces' => 1,
             // a step counts every visit that got at least that far
             'funnel_view_item' => 2, 'funnel_add_to_cart' => 2, 'funnel_checkout_start' => 1, 'funnel_checkout_confirm' => 1, 'orders' => 1],
            array_map('intval', array_intersect_key($total, array_flip(['pageviews', 'visits', 'visitors', 'new_visitors', 'bounces',
                'funnel_view_item', 'funnel_add_to_cart', 'funnel_checkout_start', 'funnel_checkout_confirm', 'orders']))),
        );
        $this->assertEqualsWithDelta(100.0, (float) $total['revenue'], 0.001);

        $this->assertSame(2, (int) $find('device', 'mobile')['visits']);
        $this->assertSame(1, (int) $find('device', 'desktop')['visits']);
        $this->assertSame(3, (int) $find('country', 'TH')['visits']);
        $this->assertSame(3, (int) $find('source', 'direct')['visits']);
        $product = $find('path', '/somchai-cafe/product/Ab3x');
        $this->assertSame([2, 2, 0], [(int) $product['pageviews'], (int) $product['visitors'], (int) $product['bounces']]);
        $this->assertSame(1, (int) $find('path', '/somchai-cafe')['bounces']);

        $this->assertSame(0, $this->db->table('tracker_daily')->where('day', '2026-11-09')->where('site', 'www')->countAllResults());
    }

    public function testZeroesTheIpAddressesOfOldVisits(): void
    {
        $this->assertSame('203.0.113.0', TrackerRollup::anonymiseIp('203.0.113.77'));
        $this->assertSame('2001:db8:85a3::', TrackerRollup::anonymiseIp('2001:db8:85a3:8d3:1319:8a2e:370:7348'));
        $this->assertSame('', TrackerRollup::anonymiseIp('not an address'));

        $this->raw(['session_id' => 'SessionAAAAAAAAA', 'occurred_at' => '2026-10-01 00:00:00', 'ip_address' => '203.0.113.77']);
        $this->raw(['session_id' => 'SessionBBBBBBBBB', 'occurred_at' => '2026-11-01 00:00:00', 'ip_address' => '203.0.113.88']);
        $this->merger->processBatch();

        $rollup = new TrackerRollup($this->config, $this->db);
        $this->assertSame(1, $rollup->anonymiseIps(Time::parse('2026-11-09 00:00:00', 'UTC')));
        $this->assertSame(0, $rollup->anonymiseIps(Time::parse('2026-11-09 00:00:00', 'UTC')));

        $visits = $this->db->table('tracker_visit')->orderBy('started_at')->get()->getResultArray();
        $this->assertSame(['203.0.113.0', '203.0.113.88'], array_column($visits, 'ip_address'));
        $this->assertSame('TH', $visits[0]['country_code']); // located before it was zeroed
    }

    public function testPurgesExpiredVisitsAndVisitors(): void
    {
        $this->raw(['visitor_id' => 'VisitorAAAAAAAAA', 'session_id' => 'SessionAAAAAAAAA', 'occurred_at' => '2025-01-01 00:00:00']);
        $this->raw(['visitor_id' => 'VisitorBBBBBBBBB', 'session_id' => 'SessionBBBBBBBBB', 'occurred_at' => '2025-01-01 00:00:00']);
        $this->raw(['visitor_id' => 'VisitorBBBBBBBBB', 'session_id' => 'SessionCCCCCCCCC', 'occurred_at' => '2026-11-01 00:00:00']);
        $this->merger->processBatch();

        $purged = (new TrackerRollup($this->config, $this->db))->purge(Time::parse('2026-11-09 00:00:00', 'UTC'));

        $this->assertSame(['visits' => 2, 'visitors' => 1], $purged);
        $this->assertSame(['SessionCCCCCCCCC'], array_column($this->db->table('tracker_visit')->get()->getResultArray(), 'session_id'));
        $this->assertSame(1, $this->db->table('tracker_visit_page')->countAllResults());
        $this->assertSame(['VisitorBBBBBBBBB'], array_column($this->db->table('tracker_visitor')->get()->getResultArray(), 'visitor_id'));
    }

    /**
     * Store a raw event as POST /track would
     * @param array $fields Over a pageview of the website's home page from an iPhone in Bangkok
     * @return void
     */
    private function raw(array $fields = []): void
    {
        $row = $fields + [
            'site'            => 'www',
            'business_id'     => null,
            'visitor_id'      => 'VisitorAAAAAAAAA',
            'session_id'      => 'SessionAAAAAAAAA',
            'event_type'      => 'pageview',
            'event_name'      => null,
            'path'            => '/',
            'referrer'        => null,
            'occurred_at'     => '2026-11-09 10:00:00',
            'ip_address'      => '203.0.113.77',
            'user_agent'      => self::IPHONE,
            'accept_language' => 'th-TH,th;q=0.9',
            'payload'         => [],
            'processed_at'    => null,
        ];
        $row['received_at'] ??= $row['occurred_at'];
        $row['payload'] = $row['payload'] === [] ? null : json_encode($row['payload']);
        $this->db->table('tracker_event_raw')->insert($row);
    }

    /** Every public address is in Bangkok */
    private function geo(): GeoLocator
    {
        return new class ('') extends GeoLocator {
            public function locate(string $ip): ?array
            {
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                    return null;
                }

                return ['country_code' => 'TH', 'subdivision_code' => '10', 'city' => 'Bangkok', 'latitude' => 13.75, 'longitude' => 100.5];
            }
        };
    }
}
