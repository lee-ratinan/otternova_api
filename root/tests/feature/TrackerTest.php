<?php

use CodeIgniter\Config\Factories;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\Mock\MockCache;
use CodeIgniter\Test\TestResponse;
use Config\Services;
use Config\Tracker as TrackerConfig;
use Tests\Support\ArtifactFixturesTrait;

/**
 * POST /track
 *
 * @internal
 */
final class TrackerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use ArtifactFixturesTrait;

    private const WWW     = 'https://www.otternova.test';
    private const MART    = 'https://mart.otternova.test';
    private const BROWSER = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1';

    protected $namespace = 'App'; // the app's own migrations, the schema of the real database

    protected function setUp(): void
    {
        parent::setUp();

        // a fresh cache, guard and config each test: the throttle counts and the slug lookups live in the cache,
        // and the origins would otherwise come from the .env of whoever runs the tests
        Services::injectMock('cache', new MockCache());
        Services::resetSingle('trackerGuard');
        Factories::reset('config');
        config(TrackerConfig::class)->allowedOrigins = [self::WWW, self::MART];
    }

    public function testStoresAPageviewOfTheWebsite(): void
    {
        $response = $this->track([
            'language' => 'th-TH',
            'timezone' => 'Asia/Bangkok',
            'screen'   => [390, 844],
            'events'   => [[
                'type'     => 'pageview',
                'path'     => '/pricing?ignored=1',
                'title'    => "Pricing\n– Otternova",
                'query'    => 'plan=pro',
                'referrer' => 'https://www.google.com/search?q=otternova',
                'utm'      => ['source' => 'google', 'medium' => 'cpc', 'nope' => 'x'],
                'ago_ms'   => 5000,
            ]],
        ]);

        $response->assertStatus(204);
        $rows = $this->rows();
        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertSame('www', $row['site']);
        $this->assertNull($row['business_id']);
        $this->assertSame('VisitorAAAAAAAAA', $row['visitor_id']);
        $this->assertSame('SessionAAAAAAAAA', $row['session_id']);
        $this->assertSame('pageview', $row['event_type']);
        $this->assertSame('/pricing', $row['path']);
        $this->assertSame('https://www.google.com/search?q=otternova', $row['referrer']);
        $this->assertSame(self::BROWSER, $row['user_agent']);
        $this->assertSame('th-TH,th;q=0.9', $row['accept_language']);
        $this->assertNull($row['processed_at']);
        $this->assertSame(5, strtotime($row['received_at']) - strtotime($row['occurred_at']));
        $this->assertSame([
            'language' => 'th-TH',
            'timezone' => 'Asia/Bangkok',
            'screen'   => '390x844',
            'title'    => 'Pricing – Otternova',
            'query'    => 'plan=pro',
            'utm'      => ['source' => 'google', 'medium' => 'cpc'],
        ], json_decode($row['payload'], true));
    }

    public function testStoresTheFunnelOfABusinessOnTheMarketplace(): void
    {
        $businessId = $this->business('somchai-cafe');

        $this->track([
            'site'          => 'mart',
            'business_slug' => 'somchai-cafe',
            'events'        => [
                ['type' => 'pageview', 'path' => '/somchai-cafe/product/Ab3xK9'],
                ['type' => 'event', 'name' => 'view_item', 'path' => '/somchai-cafe/product/Ab3xK9', 'props' => ['artifact' => 'Ab3xK9', 'value' => 120, 'currency' => 'THB']],
                ['type' => 'event', 'name' => 'add_to_cart', 'path' => '/somchai-cafe/product/Ab3xK9', 'props' => ['quantity' => 2, 'gift' => true, 'Bad Key' => 1, 'nested' => ['x' => 1]]],
                ['type' => 'engage', 'path' => '/somchai-cafe/product/Ab3xK9', 'visible_ms' => 99_999_999, 'scroll_pct' => 140],
            ],
        ], self::MART)->assertStatus(204);

        $rows = $this->rows();
        $this->assertCount(4, $rows);
        foreach ($rows as $row) {
            $this->assertSame($businessId, (int) $row['business_id']);
        }
        $this->assertSame(['view_item', 'add_to_cart'], [$rows[1]['event_name'], $rows[2]['event_name']]);
        $this->assertSame(['artifact' => 'Ab3xK9', 'value' => 120, 'currency' => 'THB'], json_decode($rows[1]['payload'], true)['props']);
        $this->assertSame(['quantity' => 2, 'gift' => true], json_decode($rows[2]['payload'], true)['props']);
        $this->assertSame(['visible_ms' => 30 * MINUTE * 1000, 'scroll_pct' => 100], json_decode($rows[3]['payload'], true));
    }

    public function testMarketplaceOwnPagesHaveNoBusiness(): void
    {
        $this->track(['site' => 'mart', 'events' => [['type' => 'pageview', 'path' => '/search']]], self::MART);

        $this->assertNull($this->rows()[0]['business_id']);
    }

    public function testWebsiteIgnoresABusinessSlug(): void
    {
        $this->business('somchai-cafe');
        $this->track(['business_slug' => 'somchai-cafe']);

        $this->assertNull($this->rows()[0]['business_id']);
    }

    public function testDropsAnUnknownBusiness(): void
    {
        $this->track(['site' => 'mart', 'business_slug' => 'no-such-shop'], self::MART)->assertStatus(204);

        $this->assertSame([], $this->rows());
    }

    public function testDropsBatchesFromElsewhereAndFromBots(): void
    {
        $this->track([], 'https://evil.example')->assertStatus(204);
        $this->track([], null)->assertStatus(204);
        $this->track([], self::WWW, 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)')->assertStatus(204);
        $this->track([], self::WWW, '')->assertStatus(204);

        $this->assertSame([], $this->rows());
    }

    public function testAnyOriginWhenNoneIsConfigured(): void
    {
        config(TrackerConfig::class)->allowedOrigins = [];
        Services::resetSingle('trackerGuard');

        $this->track([], 'http://localhost:8301')->assertStatus(204);

        $this->assertCount(1, $this->rows());
    }

    public function testDropsMalformedBatches(): void
    {
        $this->post204('not json');
        $this->post204(str_repeat(' ', 20000));
        $this->track(['site' => 'admin']);
        $this->track(['visitor_id' => 'short']);
        $this->track(['session_id' => null]);
        $this->track(['events' => []]);
        $this->track(['events' => ['type' => 'pageview', 'path' => '/']]);

        $this->assertSame([], $this->rows());
    }

    public function testDropsBadEventsButKeepsTheRest(): void
    {
        $this->track(['events' => [
            ['type' => 'pageview', 'path' => 'https://www.otternova.test/'],
            ['type' => 'pageview', 'path' => '//evil.example/'],
            ['type' => 'click', 'path' => '/'],
            ['type' => 'event', 'name' => 'Add To Cart', 'path' => '/'],
            ['type' => 'pageview', 'path' => '/', 'referrer' => 'javascript:alert(1)'],
            'not an event',
        ]]);

        $rows = $this->rows();
        $this->assertCount(1, $rows);
        $this->assertSame('/', $rows[0]['path']);
        $this->assertNull($rows[0]['referrer']);
    }

    public function testKeepsAtMostTheConfiguredNumberOfEvents(): void
    {
        config(TrackerConfig::class)->maxEvents = 3;
        $events = array_fill(0, 5, ['type' => 'pageview', 'path' => '/']);

        $this->track(['events' => $events]);

        $this->assertCount(3, $this->rows());
    }

    public function testDatesAnEventAtMostAnHourBack(): void
    {
        $this->track(['events' => [['type' => 'pageview', 'path' => '/', 'ago_ms' => 3 * DAY * 1000]]]);

        $row = $this->rows()[0];
        $this->assertSame(HOUR, strtotime($row['received_at']) - strtotime($row['occurred_at']));
    }

    public function testThrottlesAnIpAddress(): void
    {
        config(TrackerConfig::class)->maxPerIpPerMinute = 2;
        Services::resetSingle('trackerGuard');

        for ($i = 0; $i < 4; $i++) {
            $this->track()->assertStatus(204);
        }

        $this->assertCount(2, $this->rows());
    }

    public function testOriginOfAUrl(): void
    {
        $this->assertSame('https://www.otternova.com', TrackerConfig::originOf('https://WWW.Otternova.com/'));
        $this->assertSame('http://localhost:8301', TrackerConfig::originOf('http://localhost:8301/path'));
        $this->assertNull(TrackerConfig::originOf('ftp://example.com'));
        $this->assertNull(TrackerConfig::originOf(''));
    }

    /**
     * POST a batch as the tracker script would: JSON as text/plain
     * @param array       $overrides Merged over a valid www batch of one pageview
     * @param string|null $origin    The Origin header; none when null
     * @param string      $userAgent
     * @return TestResponse
     */
    private function track(array $overrides = [], ?string $origin = self::WWW, string $userAgent = self::BROWSER): TestResponse
    {
        $batch = $overrides + [
            'site'       => 'www',
            'visitor_id' => 'VisitorAAAAAAAAA',
            'session_id' => 'SessionAAAAAAAAA',
            'events'     => [['type' => 'pageview', 'path' => '/']],
        ];

        return $this->post204(json_encode($batch), $origin, $userAgent);
    }

    private function post204(string $body, ?string $origin = self::WWW, string $userAgent = self::BROWSER): TestResponse
    {
        $headers = ['Content-Type' => 'text/plain;charset=UTF-8', 'User-Agent' => $userAgent, 'Accept-Language' => 'th-TH,th;q=0.9'];
        if ($origin !== null) {
            $headers['Origin'] = $origin;
        }
        $response = $this->withHeaders($headers)->withBody($body)->call('POST', 'v1/track');
        $response->assertStatus(204);

        return $response;
    }

    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        return $this->db->table('tracker_event_raw')->orderBy('id')->get()->getResultArray();
    }
}
