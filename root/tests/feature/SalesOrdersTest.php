<?php

use CodeIgniter\I18n\Time;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\Mock\MockCache;
use Config\Services;
use Tests\Support\ArtifactFixturesTrait;

/**
 * /client/orders, /client/order, /client/order/status, /client/order/payment/create and /settle: a business lists
 * and searches the orders its customers placed, changes their statuses and records their payments and refunds,
 * which its financial_status follows
 *
 * @internal
 */
final class SalesOrdersTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use ArtifactFixturesTrait;

    protected $namespace = 'App';

    private string $ownerToken;
    private string $employeeToken;
    private int $businessId;
    private string $book;
    private string $cash;
    private string $transfer;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['jwt', 'authContext', 'martCache'] as $service) {
            Services::resetSingle($service);
        }
        Services::injectMock('cache', new MockCache());

        $this->businessId    = $this->business('otter-shop');
        $this->ownerToken    = $this->createClient('owner@example.com', $this->businessId, 'O');
        $this->employeeToken = $this->createClient('staff@example.com', $this->businessId, 'E');
        [$storeId]           = $this->store($this->businessId, 'Silom');
        $this->db->table('business_store')->where('id', $storeId)->update(['store_type' => 'P']);

        [$artifactId] = $this->artifactRow($this->businessId);
        $this->book   = $this->fixturePublicId();
        $this->db->table('artifact_variant')->insert(['public_id' => $this->book, 'business_id' => $this->businessId, 'artifact_id' => $artifactId, 'price_active' => '300.00']);
        $this->db->table('artifact_variant_inventory')->insert(['variant_id' => (int) $this->db->insertID(), 'store_id' => $storeId, 'quantity' => 100]);

        $pickup = $this->fixturePublicId();
        $this->db->table('business_shipping_method')->insert(['public_id' => $pickup, 'business_id' => $this->businessId, 'method_type' => 'S',
            'method_name' => 'Pickup', 'fee_basis' => 'P', 'store_id' => $storeId]);
        $this->db->table('business_shipping_rate')->insert(['shipping_method_id' => (int) $this->db->insertID(), 'range_from' => '0.00', 'range_to' => null, 'fee_amount' => '0.00']);
        foreach (['cash' => 'Cash', 'transfer' => 'Bank transfer'] as $code => $name) {
            $this->{$code} = $this->fixturePublicId();
            $this->db->table('business_payment_method')->insert(['public_id' => $this->{$code}, 'business_id' => $this->businessId, 'method_code' => $code,
                'handler_code' => 'instruction', 'method_name' => $name]);
        }

        // three orders: two on Mon 16 Nov (Bangkok), one late on Tue 17 Nov (Bangkok), which is still the 17th in UTC
        $this->place('2026-11-16 03:00:00', 'Ann', 'Lee', 1, $pickup);
        $this->place('2026-11-16 09:00:00', 'Ben', 'Somchai', 2, $pickup);
        $this->place('2026-11-17 16:30:00', 'Cat', 'Lee', 3, $pickup);
    }

    protected function tearDown(): void
    {
        Time::setTestNow();
        parent::tearDown();
    }

    public function testAPlacedOrderIsOpenAndWaitsForItsPayment(): void
    {
        $order = $this->db->table('order_master')->where('first_name', 'Ann')->get()->getRowArray();
        $this->assertSame(['open', 'pending', 'open'], [$order['order_status'], $order['financial_status'], $order['fulfillment_status']]);
        $payment = $this->db->table('order_payment')->where('order_id', $order['id'])->get()->getRowArray();
        $this->assertSame(['payment', 'pending', '300.00', 'Cash'], [$payment['transaction_type'], $payment['transaction_status'],
            sprintf('%.2F', $payment['amount']), $payment['payment_method_name']]);
    }

    public function testOrdersAreListedSearchedAndFilteredByTheBusinessDays(): void
    {
        $data = $this->list([]);
        $this->assertSame([3, 3], [$data['records_total'], $data['records_filtered']]);
        $this->assertSame(['Cat Lee', 'Ben Somchai', 'Ann Lee'], array_column($data['rows'], 'customer_name'));
        $this->assertSame('Asia/Bangkok', $data['timezone']);
        $this->assertSame(['900.00', 1, 'open', 'pending', 'open'], [$data['rows'][0]['total_amount'], $data['rows'][0]['line_count'],
            $data['rows'][0]['order_status'], $data['rows'][0]['financial_status'], $data['rows'][0]['fulfillment_status']]);

        $this->assertSame(['Cat Lee', 'Ann Lee'], array_column($this->list([], 'lee')['rows'], 'customer_name'));
        $this->assertSame(['Ann Lee'], array_column($this->list([], 'ann LEE')['rows'], 'customer_name'));
        $number = $data['rows'][1]['order_number'];
        $this->assertSame(['Ben Somchai'], array_column($this->list([], $number)['rows'], 'customer_name'));

        // 16:30 UTC on the 17th is 23:30 on the 17th in Bangkok
        $this->assertSame(['Ben Somchai', 'Ann Lee'], array_column($this->list(['date_from' => '2026-11-16', 'date_to' => '2026-11-16'])['rows'], 'customer_name'));
        $this->assertSame(['Cat Lee'], array_column($this->list(['date_from' => '2026-11-17'])['rows'], 'customer_name'));
        $filtered = $this->list(['financial_status' => 'paid']);
        $this->assertSame([3, 0], [$filtered['records_total'], $filtered['records_filtered']]);
        // a filter that is not one is left out
        $this->assertSame(3, $this->list(['order_status' => 'nope', 'date_from' => '2026-02-30'])['records_filtered']);

        $this->send('v1/client/orders', ['business_slug' => 'nobody'])->assertStatus(404);
        $this->send('v1/client/orders', ['business_slug' => 'otter-shop'], $this->createClient('else@example.com', null, null))->assertStatus(404);
        // employees see the sales too
        $this->send('v1/client/orders', ['business_slug' => 'otter-shop'], $this->employeeToken)->assertStatus(200);
    }

    public function testStatusesAreChanged(): void
    {
        $publicId = $this->publicIdOf('Ann');
        $response = $this->send('v1/client/order/status', ['public_id' => $publicId, 'order_status' => 'in_progress', 'fulfillment_status' => 'partially_fulfilled'], $this->employeeToken);
        $response->assertStatus(200);
        $order = $this->body($response)['data']['order'];
        $this->assertSame(['in_progress', 'partially_fulfilled', 'pending'], [$order['order_status'], $order['fulfillment_status'], $order['financial_status']]);
        $this->assertSame(1, $this->db->table('log_activity')->where('table_involved', 'order_master')->countAllResults());

        $response = $this->send('v1/client/order/status', ['public_id' => $publicId, 'order_status' => 'done', 'financial_status' => 'paid']);
        $response->assertStatus(422);
        $this->assertSame(['order_status'], array_keys($this->body($response)['errors']));

        $this->db->table('order_master')->where('public_id', $publicId)->update(['fulfillment_status' => null]);
        $response = $this->send('v1/client/order/status', ['public_id' => $publicId, 'fulfillment_status' => 'fully_fulfilled']);
        $this->assertSame(['fulfillment_status' => lang('SalesOrder.noProducts')], $this->body($response)['errors']);

        $this->send('v1/client/order/status', ['public_id' => 'nope12345678', 'order_status' => 'closed'])->assertStatus(404);
    }

    public function testPaymentsAndRefundsMoveTheFinancialStatus(): void
    {
        $publicId = $this->publicIdOf('Ben'); // 600.00
        $show     = $this->body($this->send('v1/client/order', ['public_id' => $publicId]))['data'];
        $this->assertSame(['Cash', 'Bank transfer'], array_column($show['payment_methods'], 'method_name'));
        $this->assertTrue($show['can_refund']);
        $pending = $show['order']['payments'][0]['public_id'];

        // half paid by transfer; the placed payment is voided
        $order = $this->pay($publicId, ['amount' => '250', 'payment_method_public_id' => $this->transfer, 'reference' => 'TX-1']);
        $this->assertSame('partially_paid', $order['financial_status']);
        $this->assertSame(['paid' => '250.00', 'refunded' => '0.00', 'net_paid' => '250.00', 'balance_due' => '350.00', 'refundable' => '250.00'], $order['money']);
        $this->assertSame('Bank transfer', $order['payments'][1]['payment_method_name']);
        $order = $this->body($this->send('v1/client/order/payment/settle', ['public_id' => $pending, 'transaction_status' => 'voided']))['data']['order'];
        $this->assertSame('voided', $order['payments'][0]['transaction_status']);

        // a failed try counts for nothing; more than the balance is refused
        $this->assertSame('partially_paid', $this->pay($publicId, ['amount' => '350', 'transaction_status' => 'failed', 'failure_reason' => 'Declined'])['financial_status']);
        $response = $this->send('v1/client/order/payment/create', ['public_id' => $publicId, 'payment' => ['amount' => '350.01']]);
        $this->assertSame(['amount' => lang('SalesOrder.overpaid')], $this->body($response)['errors']);

        $order = $this->pay($publicId, ['amount' => '350.00']);
        $this->assertSame('paid', $order['financial_status']);
        $this->assertNotNull($order['paid_at']);

        // the mart shows it confirmed
        $this->assertSame('C', \App\Models\MartOrderModel::customerStatus($this->db->table('order_master')->where('public_id', $publicId)->get()->getRowArray()));

        // refunds: the owners and managers only, up to what was paid
        $response = $this->send('v1/client/order/payment/create', ['public_id' => $publicId, 'payment' => ['transaction_type' => 'refund', 'amount' => '100']], $this->employeeToken);
        $response->assertStatus(403);
        $this->assertSame('partially_refunded', $this->pay($publicId, ['transaction_type' => 'refund', 'amount' => '100'])['financial_status']);
        $response = $this->send('v1/client/order/payment/create', ['public_id' => $publicId, 'payment' => ['transaction_type' => 'refund', 'amount' => '500.01']]);
        $this->assertSame(['amount' => lang('SalesOrder.overRefunded')], $this->body($response)['errors']);
        $order = $this->pay($publicId, ['transaction_type' => 'refund', 'amount' => '500']);
        $this->assertSame('full_refunded', $order['financial_status']);
        $this->assertCount(6, $order['payments']);

        // a settled payment stays as it is
        $response = $this->send('v1/client/order/payment/settle', ['public_id' => $pending, 'transaction_status' => 'succeeded']);
        $this->assertSame(['transaction_status' => lang('SalesOrder.notPending')], $this->body($response)['errors']);
    }

    public function testAPendingPaymentIsSettled(): void
    {
        $publicId = $this->publicIdOf('Ann');
        $pending  = $this->db->table('order_payment')->get()->getRowArray()['public_id'];
        $response = $this->send('v1/client/order/payment/settle', ['public_id' => $pending, 'transaction_status' => 'succeeded', 'reference' => 'Counter'], $this->employeeToken);
        $response->assertStatus(200);
        $order = $this->body($response)['data']['order'];
        $this->assertSame(['paid', 'succeeded', 'Counter'], [$order['financial_status'], $order['payments'][0]['transaction_status'], $order['payments'][0]['reference']]);
        $this->assertSame($publicId, $order['public_id']);

        $response = $this->send('v1/client/order/payment/create', ['public_id' => $publicId, 'payment' => ['amount' => '0', 'transaction_type' => 'gift',
            'payment_method_public_id' => 'nope12345678']]);
        $response->assertStatus(422);
        $this->assertEqualsCanonicalizing(['amount', 'transaction_type', 'payment_method_public_id'], array_keys($this->body($response)['errors']));
    }

    // --- helpers ------------------------------------------------------------------------------------------------

    private function place(string $at, string $first, string $last, int $books, string $pickup): void
    {
        Time::setTestNow($at, 'UTC');
        $this->withBodyFormat('json')->post('v1/mart/order', ['business_slug' => 'otter-shop', 'lang' => 'en-US',
            'lines' => [['key' => 'b', 'kind' => 'P', 'variant' => $this->book, 'quantity' => $books]], 'shipping' => $pickup, 'payment' => $this->cash,
            'customer' => ['first_name' => $first, 'last_name' => $last, 'email' => strtolower($first) . '@example.com', 'telephone' => '081 234 5678'],
        ])->assertStatus(201);
        Time::setTestNow();
        // created_at is the database's clock
        $this->db->table('order_master')->where('first_name', $first)->update(['created_at' => $at]);
    }

    private function list(array $filters, string $search = ''): array
    {
        $response = $this->send('v1/client/orders', ['business_slug' => 'otter-shop', 'search' => $search, 'filters' => $filters]);
        $response->assertStatus(200);

        return $this->body($response)['data'];
    }

    private function pay(string $publicId, array $payment): array
    {
        $response = $this->send('v1/client/order/payment/create', ['public_id' => $publicId, 'payment' => $payment]);
        $response->assertStatus(200);

        return $this->body($response)['data']['order'];
    }

    private function publicIdOf(string $firstName): string
    {
        return $this->db->table('order_master')->where('first_name', $firstName)->get()->getRow()->public_id;
    }
}
