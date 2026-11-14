<?php

use CodeIgniter\I18n\Time;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\Mock\MockCache;
use CodeIgniter\Test\TestResponse;
use Config\Services;
use Tests\Support\ArtifactFixturesTrait;

/**
 * /client/counter/*: the people of a business take walk-in and call-in orders at a store's counter, priced and placed
 * as the mart's, without its booking window, the products handed over there, paid there and then or later
 *
 * @internal
 */
final class CounterOrdersTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use ArtifactFixturesTrait;

    protected $namespace = 'App';

    private string $ownerToken;
    private string $employeeToken;
    private int $businessId;
    private int $silomId;
    private string $silom;
    private int $warehouseId;
    private int $bookId;
    private string $book;
    private string $massage;
    private string $classVariant;
    private string $classSession;
    private string $delivery;
    private string $cash;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['jwt', 'authContext', 'martCache'] as $service) {
            Services::resetSingle($service);
        }
        Services::injectMock('cache', new MockCache());
        // Monday 16 Nov 2026, 10:10 in Bangkok
        Time::setTestNow('2026-11-16 03:10:00', 'UTC');

        $this->businessId    = $this->business('otter-spa');
        $this->ownerToken    = $this->createClient('owner@example.com', $this->businessId, 'O');
        $this->employeeToken = $this->createClient('counter@example.com', $this->businessId, 'E');
        [$this->silomId, $this->silom] = $this->store($this->businessId, 'Silom');
        $this->db->table('business_store')->where('id', $this->silomId)->update(['store_type' => 'P']);
        [$this->warehouseId] = $this->store($this->businessId, 'Warehouse');
        foreach (range(1, 7) as $day) {
            $this->db->table('business_store_hours')->insert(['store_id' => $this->silomId, 'day_of_week' => $day, 'open_time' => '10:00:00', 'close_time' => '20:00:00']);
        }

        // a balm, 5 at Silom and 3 at the warehouse
        [$artifactId] = $this->artifactRow($this->businessId);
        $this->db->table('artifact_master')->where('id', $artifactId)->update(['artifact_name' => 'Herbal balm']);
        $this->book = $this->fixturePublicId();
        $this->db->table('artifact_variant')->insert(['public_id' => $this->book, 'business_id' => $this->businessId, 'artifact_id' => $artifactId,
            'price_active' => '250.00', 'sku' => 'BALM-1', 'weight_grams' => 100]);
        $this->bookId = (int) $this->db->insertID();
        $this->db->table('artifact_variant_inventory')->insertBatch([
            ['variant_id' => $this->bookId, 'store_id' => $this->silomId, 'quantity' => 5],
            ['variant_id' => $this->bookId, 'store_id' => $this->warehouseId, 'quantity' => 3],
        ]);

        // a 60-minute Thai massage with Somsak, who works 10:00-20:00
        [$serviceId]   = $this->artifactRow($this->businessId, 'S');
        $this->db->table('artifact_master')->where('id', $serviceId)->update(['artifact_name' => 'Thai massage']);
        $this->massage = $this->fixturePublicId();
        $this->db->table('artifact_variant')->insert(['public_id' => $this->massage, 'business_id' => $this->businessId, 'artifact_id' => $serviceId,
            'price_active' => '600.00', 'duration_minutes' => 60, 'booking_type' => 'A', 'staff_required' => 1]);
        $this->createClient('somsak@example.com', $this->businessId, 'E');
        $somsak = (int) $this->db->table('client_master c')->select('c.id')->join('auth_master a', 'a.id = c.auth_id')->where('a.uid', 'somsakexamplecom')->get()->getRow()->id;
        $this->db->table('business_service_client_link')->insert(['business_id' => $this->businessId, 'artifact_id' => $serviceId, 'client_id' => $somsak]);
        $this->db->table('business_store_client_link')->insert(['business_id' => $this->businessId, 'store_id' => $this->silomId, 'client_id' => $somsak]);
        foreach (range(1, 7) as $day) {
            $this->db->table('business_client_hours')->insert(['business_id' => $this->businessId, 'client_id' => $somsak, 'store_id' => $this->silomId,
                'day_of_week' => $day, 'start_time' => '10:00:00', 'end_time' => '20:00:00']);
        }

        // a yoga class of 10 that started at 10:00 and ends at 11:00
        [$classId]          = $this->artifactRow($this->businessId, 'S');
        $this->classVariant = $this->fixturePublicId();
        $this->db->table('artifact_variant')->insert(['public_id' => $this->classVariant, 'business_id' => $this->businessId, 'artifact_id' => $classId,
            'price_active' => '300.00', 'duration_minutes' => 60, 'booking_type' => 'G', 'capacity' => 10, 'staff_required' => 1]);
        $this->classSession = $this->fixturePublicId();
        $this->db->table('service_session')->insert(['public_id' => $this->classSession, 'business_id' => $this->businessId, 'store_id' => $this->silomId,
            'artifact_id' => $classId, 'variant_id' => (int) $this->db->insertID(), 'session_name' => 'Morning yoga', 'capacity' => 10, 'seats_booked' => 2,
            'starts_at' => '2026-11-16 03:00:00', 'ends_at' => '2026-11-16 04:00:00', 'session_status' => 'O']);
        $this->db->table('service_session_slot')->insert(['business_id' => $this->businessId, 'session_id' => (int) $this->db->insertID(), 'slot_number' => 1,
            'starts_at' => '2026-11-16 03:00:00', 'ends_at' => '2026-11-16 04:00:00', 'slot_status' => 'S']);

        $this->delivery = $this->fixturePublicId();
        $this->db->table('business_shipping_method')->insert(['public_id' => $this->delivery, 'business_id' => $this->businessId, 'method_type' => 'H',
            'method_name' => 'Delivery', 'fee_basis' => 'P']);
        $this->db->table('business_shipping_rate')->insert(['shipping_method_id' => (int) $this->db->insertID(), 'range_from' => '0.00', 'range_to' => null, 'fee_amount' => '40.00']);
        $this->cash = $this->fixturePublicId();
        $this->db->table('business_payment_method')->insert(['public_id' => $this->cash, 'business_id' => $this->businessId, 'method_code' => 'cash',
            'handler_code' => 'instruction', 'method_name' => 'Cash']);
    }

    protected function tearDown(): void
    {
        Time::setTestNow();
        parent::tearDown();
    }

    public function testTheCatalogFindsWhatTheCounterSells(): void
    {
        $stores = $this->data($this->send('v1/client/counter/stores', ['business_slug' => 'otter-spa'], $this->employeeToken))['stores'];
        $this->assertSame([[$this->silom, 'Silom', false]], array_map(static fn (array $s): array => [$s['public_id'], $s['store_name'], $s['mine']], $stores));

        $items = $this->data($this->send('v1/client/counter/catalog', ['business_slug' => 'otter-spa', 'store_public_id' => $this->silom, 'search' => 'balm'], $this->employeeToken))['items'];
        $this->assertSame([[$this->book, 'P', '250.00', 5]], array_map(static fn (array $i): array => [$i['variant'], $i['kind'], $i['price'], $i['stock']], $items));

        $all = $this->data($this->send('v1/client/counter/catalog', ['business_slug' => 'otter-spa', 'store_public_id' => $this->silom]))['items'];
        $this->assertEqualsCanonicalizing(['P', 'A', 'G'], array_column($all, 'kind'));

        // a store of the business, physical
        [, $other] = $this->store($this->business('another'), 'Elsewhere');
        $this->send('v1/client/counter/catalog', ['business_slug' => 'otter-spa', 'store_public_id' => $other])->assertStatus(422);
    }

    public function testAWalkInIsOfferedTheTimeNowAndACallInBeyondTheMartsWindow(): void
    {
        // the mart, with an hour's notice: 11:30 first (11:10 on the step); the counter: from 10:00, the step now is in
        $slots = $this->data($this->send('v1/client/counter/slots', ['business_slug' => 'otter-spa', 'store_public_id' => $this->silom, 'variant' => $this->massage]))['slots'];
        $this->assertSame('10:00', $slots[0]['time']);
        $mart = $this->data($this->get('v1/mart/slots?business_slug=otter-spa&variant=' . $this->massage . '&date=2026-11-16'))['slots'];
        $this->assertNotSame('10:00', $mart[0]['time']);

        // three weeks ahead, past the mart's 3 days
        $later = $this->data($this->send('v1/client/counter/slots', ['business_slug' => 'otter-spa', 'store_public_id' => $this->silom, 'variant' => $this->massage, 'date' => '2026-12-07']))['slots'];
        $this->assertNotEmpty($later);
    }

    public function testAWalkInIsServedHandedOverAndPaidAtTheCounter(): void
    {
        $slot     = $this->data($this->send('v1/client/counter/slots', ['business_slug' => 'otter-spa', 'store_public_id' => $this->silom, 'variant' => $this->massage]))['slots'][0];
        $response = $this->order([
            ['key' => 'm', 'kind' => 'A', 'variant' => $this->massage, 'quantity' => 1, 'store' => $this->silom, 'starts_at' => $slot['starts_at'],
                'staff' => $slot['staff'], 'participants' => ['Ann']],
            ['key' => 'b', 'kind' => 'P', 'variant' => $this->book, 'quantity' => 2],
        ], ['first_name' => 'Ann', 'telephone' => '081-234 5678'], ['paid_now' => true], $this->employeeToken);
        $response->assertStatus(201);
        $publicId = $this->data($response)['public_id'];

        $order = $this->db->table('order_master')->where('public_id', $publicId)->get()->getRowArray();
        $this->assertSame(['counter', $this->silomId, 'paid', 'fully_fulfilled', '', '0812345678'],
            [$order['order_source'], (int) $order['counter_store_id'], $order['financial_status'], $order['fulfillment_status'], $order['email_address'], $order['telephone_number']]);
        $this->assertNotNull($order['created_by']);
        $this->assertNotNull($order['paid_at']);
        $payment = $this->db->table('order_payment')->where('order_id', $order['id'])->get()->getRowArray();
        $this->assertSame(['succeeded', '1100.00', (int) $order['created_by']], [$payment['transaction_status'], sprintf('%.2F', $payment['amount']), (int) $payment['recorded_by']]);
        // out of Silom's stock, not the warehouse's
        $this->assertSame([3, 3], [$this->stock($this->silomId), $this->stock($this->warehouseId)]);

        // a customer who gave only a number, found again by it
        $customer = $this->db->table('customer_master')->where('id', $order['customer_id'])->get()->getRowArray();
        $this->assertSame([null, '0812345678'], [$customer['email_address'], $customer['telephone_number']]);
        $this->order([['key' => 'b', 'kind' => 'P', 'variant' => $this->book, 'quantity' => 1]], ['first_name' => 'Ann', 'telephone' => '0812345678'])->assertStatus(201);
        $this->assertSame(1, $this->db->table('customer_master')->countAllResults());

        // the business sees it as the counter's, taken by the employee
        $show = $this->data($this->send('v1/client/order', ['public_id' => $publicId]))['order'];
        $this->assertSame(['counter', 'Otter Tester', 'Silom'], [$show['order_source'], $show['created_by_name'], $show['counter_store']['store_name']]);
    }

    public function testACallInPaysLaterAndMayBeShipped(): void
    {
        // shipped needs a way to ship
        $lines    = [['key' => 'b', 'kind' => 'P', 'variant' => $this->book, 'quantity' => 1]];
        $response = $this->order($lines, ['first_name' => 'Ben', 'email' => 'ben@example.com'], ['handed_over' => false]);
        $response->assertStatus(409);
        $this->assertSame('shipping', $this->body($response)['data']['reason']);

        $response = $this->order($lines, ['first_name' => 'Ben', 'email' => 'Ben@Example.com'], ['handed_over' => false, 'shipping' => $this->delivery,
            'delivery' => ['line_1' => '1 Main Rd', 'city' => 'Bangkok', 'postal_code' => '10500']]);
        $response->assertStatus(201);
        $order = $this->db->table('order_master')->where('public_id', $this->data($response)['public_id'])->get()->getRowArray();
        $this->assertSame(['pending', 'open', 'Delivery', '290.00'], [$order['financial_status'], $order['fulfillment_status'], $order['shipping_method_name'], sprintf('%.2F', $order['total_amount'])]);
        $this->assertSame('pending', $this->db->table('order_payment')->where('order_id', $order['id'])->get()->getRow()->transaction_status);
    }

    public function testTheCustomerGivesANameAndAWayToBeReached(): void
    {
        $lines    = [['key' => 'b', 'kind' => 'P', 'variant' => $this->book, 'quantity' => 1]];
        $response = $this->order($lines, ['first_name' => '', 'email' => '', 'telephone' => '']);
        $response->assertStatus(422);
        $this->assertEqualsCanonicalizing(['customer.first_name', 'customer.contact'], array_keys($this->body($response)['errors']));
        $this->assertSame(['customer.email' => 'invalid'], $this->body($this->order($lines, ['first_name' => 'Ann', 'email' => 'nope']))['errors']);
    }

    public function testAWalkInMayJoinAClassThatHasStarted(): void
    {
        $sessions = $this->data($this->send('v1/client/counter/sessions', ['business_slug' => 'otter-spa', 'store_public_id' => $this->silom, 'variant' => $this->classVariant]))['sessions'];
        $this->assertSame([[$this->classSession, 8]], array_map(static fn (array $s): array => [$s['public_id'], $s['seats_available']], $sessions));

        $seat = ['key' => 'y', 'kind' => 'G', 'variant' => $this->classVariant, 'quantity' => 1, 'session' => $this->classSession, 'participants' => ['Cat']];
        // the mart has closed it
        $quote = $this->data($this->withBodyFormat('json')->post('v1/mart/quote', ['business_slug' => 'otter-spa', 'lines' => [$seat]]));
        $this->assertSame('session_gone', $quote['lines'][0]['problem']['code']);

        $this->order([$seat], ['first_name' => 'Cat', 'telephone' => '0899999999'], ['paid_now' => true])->assertStatus(201);
        $this->assertSame(3, (int) $this->db->table('service_session')->where('public_id', $this->classSession)->get()->getRow()->seats_booked);
    }

    // --- helpers ------------------------------------------------------------------------------------------------

    private function order(array $lines, array $customer, array $more = [], ?string $token = null): TestResponse
    {
        return $this->send('v1/client/counter/order', ['business_slug' => 'otter-spa', 'store_public_id' => $this->silom, 'lines' => $lines,
            'payment' => $this->cash, 'customer' => $customer] + $more, $token);
    }

    private function data(TestResponse $response): array
    {
        $this->assertContains($response->response()->getStatusCode(), [200, 201]);

        return $this->body($response)['data'];
    }

    private function stock(int $storeId): int
    {
        return (int) $this->db->table('artifact_variant_inventory')->where('variant_id', $this->bookId)->where('store_id', $storeId)->get()->getRow()->quantity;
    }
}
