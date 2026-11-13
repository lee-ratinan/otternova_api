<?php

use App\Libraries\OrderNumber;
use CodeIgniter\I18n\Time;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\ArtifactFixturesTrait;

/**
 * /client/order-numbers and /client/order-numbers/save: how a business's order numbers look, and the format itself
 *
 * @internal
 */
final class OrderNumbersTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use ArtifactFixturesTrait;

    protected $namespace = 'App';

    private string $ownerToken;
    private int $businessId;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['jwt', 'authContext'] as $service) {
            Services::resetSingle($service);
        }
        // 13 Nov 2026, 23:30 in Bangkok: still the 13th there, the 13th in UTC too
        Time::setTestNow('2026-11-13 16:30:00', 'UTC');
        $this->businessId = $this->business('otter-shop');
        $this->ownerToken = $this->createClient('owner@example.com', $this->businessId, 'O');
        [$storeId]        = $this->store($this->businessId, 'Silom');
        $this->db->table('business_store')->where('id', $storeId)->update(['store_type' => 'P']);
    }

    protected function tearDown(): void
    {
        Time::setTestNow();
        parent::tearDown();
    }

    public function testTheDefaultsAndTheNextNumberAreShown(): void
    {
        $data = $this->body($this->send('v1/client/order-numbers', ['business_slug' => 'otter-shop']))['data'];
        $this->assertSame(['order_prefix' => 'IN', 'order_date_part' => 'M', 'order_reset' => 'M', 'order_digits' => 4], $data['order_numbers']);
        $this->assertSame('IN2611-0001', $data['next']);
    }

    public function testSettingsAreSavedAndChecked(): void
    {
        $response = $this->send('v1/client/order-numbers/save', ['business_slug' => 'otter-shop',
            'order_numbers' => ['order_prefix' => ' shop', 'order_date_part' => 'D', 'order_reset' => 'D', 'order_digits' => '6']]);
        $response->assertStatus(200);
        $this->assertSame('SHOP261113-000001', $this->body($response)['data']['next']);

        // a field left out keeps its value
        $this->send('v1/client/order-numbers/save', ['business_slug' => 'otter-shop', 'order_numbers' => ['order_prefix' => '']])->assertStatus(200);
        $this->assertSame('261113-000001', $this->body($this->send('v1/client/order-numbers', ['business_slug' => 'otter-shop']))['data']['next']);

        $errors = $this->errors(['order_prefix' => 'SHOPS', 'order_digits' => 7]);
        $this->assertSame(['order_prefix', 'order_digits'], array_keys($errors));
        $this->assertSame(['order_prefix'], array_keys($this->errors(['order_prefix' => 'IN1'])));
        // a daily reset with only the month shown would give the same number every day
        $this->assertSame(['order_reset'], array_keys($this->errors(['order_date_part' => 'M', 'order_reset' => 'D'])));
        $this->assertSame(['order_reset'], array_keys($this->errors(['order_date_part' => 'N', 'order_reset' => 'Y'])));
        $this->assertSame(['order_date_part'], array_keys($this->errors(['order_date_part' => 'X'])));
    }

    public function testOnlyItsOwnersAndManagersMayChangeThem(): void
    {
        $employee = $this->createClient('employee@example.com', $this->businessId, 'E');
        $this->send('v1/client/order-numbers', ['business_slug' => 'otter-shop'], $employee)->assertStatus(403);
        $stranger = $this->createClient('stranger@example.com', null, null);
        $this->send('v1/client/order-numbers/save', ['business_slug' => 'otter-shop', 'order_numbers' => ['order_prefix' => 'X']], $stranger)->assertStatus(404);
    }

    public function testNumbersRunOnAndStartAgainEachPeriod(): void
    {
        $business = $this->db->table('business_master')->where('id', $this->businessId)->get()->getRowArray();
        $at       = static fn (string $utc): DateTimeImmutable => new DateTimeImmutable($utc, new DateTimeZone('UTC'));

        $this->assertSame('IN2611-0001', OrderNumber::next($this->db, $business, $at('2026-11-13 16:30:00')));
        $this->assertSame('IN2611-0002', OrderNumber::next($this->db, $business, $at('2026-11-30 10:00:00')));
        // 1 Dec in Bangkok while still 30 Nov in UTC: a new month
        $this->assertSame('IN2612-0001', OrderNumber::next($this->db, $business, $at('2026-11-30 17:30:00')));

        // never reset, with the year shown: the number runs on into the next year
        $business = ['order_prefix' => 'AB', 'order_date_part' => 'Y', 'order_reset' => 'N', 'order_digits' => 3] + $business;
        $this->assertSame('AB26-001', OrderNumber::next($this->db, $business, $at('2026-12-31 10:00:00')));
        $this->assertSame('AB27-002', OrderNumber::next($this->db, $business, $at('2027-01-01 10:00:00')));

        // back to the old prefix: it carries on from where it stopped
        $business = ['order_prefix' => 'IN', 'order_date_part' => 'M', 'order_reset' => 'M', 'order_digits' => 4] + $business;
        $this->assertSame('IN2612-0002', OrderNumber::next($this->db, $business, $at('2026-12-02 10:00:00')));
        // a running number wider than its digits is longer
        $this->db->table('business_order_sequence')->where('prefix', 'IN')->where('period', '202612')->update(['last_number' => 9999]);
        $this->assertSame('IN2612-10000', OrderNumber::next($this->db, $business, $at('2026-12-02 11:00:00')));
    }

    private function errors(array $settings): array
    {
        $response = $this->send('v1/client/order-numbers/save', ['business_slug' => 'otter-shop', 'order_numbers' => $settings]);
        $response->assertStatus(422);

        return $this->body($response)['errors'];
    }
}
