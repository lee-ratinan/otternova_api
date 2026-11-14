<?php

use App\Libraries\PromptPay;
use CodeIgniter\I18n\Time;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\Mock\MockCache;
use Config\Services;
use Tests\Support\ArtifactFixturesTrait;

/**
 * /mart/quote, /mart/order: pricing a cart (stock, seats, the store a product comes from, shipping fees, discount
 * codes, tax) and placing it
 *
 * @internal
 */
final class MartOrderTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use ArtifactFixturesTrait;

    protected $namespace = 'App';

    private int $businessId;
    /** @var array<string, array{int, string}> name => [id, public_id] */
    private array $stores = [];
    /** @var array<string, array{int, string}> name => [variant id, public_id] */
    private array $variants = [];
    private string $classSession;
    private int $sessionId;
    private string $delivery;
    private string $cash;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['jwt', 'authContext', 'martCache'] as $service) {
            Services::resetSingle($service);
        }
        Services::injectMock('cache', new MockCache());
        // Monday 16 Nov 2026, 10:00 in Bangkok
        Time::setTestNow('2026-11-16 03:00:00', 'UTC');

        $this->businessId = $this->business('otter-shop');
        foreach (['Silom' => 'P', 'Warehouse' => 'O'] as $name => $type) {
            $this->stores[$name] = $this->store($this->businessId, $name);
            $this->db->table('business_store')->where('id', $this->stores[$name][0])->update(['store_type' => $type]);
        }

        // a book with 7% VAT included, a mug with 7% added, a sticker with none and no count kept
        foreach (['book' => ['I', '7.00', '300.00', 400, ['Silom' => 5, 'Warehouse' => 2]], 'mug' => ['E', '7.00', '100.00', 700, ['Silom' => 10]],
            'sticker' => ['X', null, '10.00', 10, []]] as $name => [$tax, $rate, $price, $grams, $stock]) {
            [$artifactId] = $this->artifactRow($this->businessId);
            $this->db->table('artifact_master')->where('id', $artifactId)->update(['artifact_name' => ucfirst($name), 'tax_calculation' => $tax, 'tax_percentage' => $rate]);
            $publicId = $this->fixturePublicId();
            $this->db->table('artifact_variant')->insert(['public_id' => $publicId, 'business_id' => $this->businessId, 'artifact_id' => $artifactId,
                'price_active' => $price, 'weight_grams' => $grams]);
            $variantId              = (int) $this->db->insertID();
            $this->variants[$name] = [$variantId, $publicId];
            foreach ($stock as $store => $quantity) {
                $this->db->table('artifact_variant_inventory')->insert(['variant_id' => $variantId, 'store_id' => $this->stores[$store][0], 'quantity' => $quantity]);
            }
        }

        // a cooking class of 10 at Silom on Friday, 3 booked
        [$serviceId] = $this->artifactRow($this->businessId, 'S');
        $classId     = $this->fixturePublicId();
        $this->db->table('artifact_variant')->insert(['public_id' => $classId, 'business_id' => $this->businessId, 'artifact_id' => $serviceId,
            'price_active' => '1500.00', 'duration_minutes' => 180, 'booking_type' => 'G', 'capacity' => 10, 'staff_required' => 1]);
        $this->variants['class'] = [(int) $this->db->insertID(), $classId];
        $this->db->table('business_store')->where('id', $this->stores['Silom'][0])->update(['store_type' => 'P']);
        $this->classSession = $this->fixturePublicId();
        $this->db->table('service_session')->insert(['public_id' => $this->classSession, 'business_id' => $this->businessId, 'store_id' => $this->stores['Silom'][0],
            'artifact_id' => $serviceId, 'variant_id' => $this->variants['class'][0], 'session_name' => 'Friday class', 'capacity' => 10, 'seats_booked' => 3,
            'starts_at' => '2026-11-20 02:00:00', 'ends_at' => '2026-11-20 05:00:00', 'session_status' => 'O']);
        $this->sessionId = (int) $this->db->insertID();
        $this->db->table('service_session_slot')->insert(['business_id' => $this->businessId, 'session_id' => $this->sessionId, 'slot_number' => 1,
            'starts_at' => '2026-11-20 02:00:00', 'ends_at' => '2026-11-20 05:00:00', 'slot_status' => 'S']);

        // delivery: 50.00 up to 1 kg, then 15.00 per kg or part of one; free from 2,000.00
        $this->delivery = $this->fixturePublicId();
        $this->db->table('business_shipping_method')->insert(['public_id' => $this->delivery, 'business_id' => $this->businessId, 'method_type' => 'H',
            'method_name' => 'Delivery', 'fee_basis' => 'W', 'free_over_amount' => '2000.00']);
        $methodId = (int) $this->db->insertID();
        $this->db->table('business_shipping_rate')->insertBatch([
            ['shipping_method_id' => $methodId, 'range_from' => '0.00', 'range_to' => '1000.00', 'fee_amount' => '50.00', 'step_size' => null, 'step_fee' => null],
            ['shipping_method_id' => $methodId, 'range_from' => '1000.00', 'range_to' => null, 'fee_amount' => '50.00', 'step_size' => '1000.00', 'step_fee' => '15.00'],
        ]);
        $this->cash = $this->fixturePublicId();
        $this->db->table('business_payment_method')->insert(['public_id' => $this->cash, 'business_id' => $this->businessId, 'method_code' => 'cash',
            'handler_code' => 'instruction', 'method_name' => 'Cash', 'instruction' => 'Pay at the counter.']);
    }

    protected function tearDown(): void
    {
        Time::setTestNow();
        parent::tearDown();
    }

    public function testTheCartIsPricedWithTaxAndShipping(): void
    {
        $quote = $this->quote([$this->product('book', 1), $this->product('mug', 2), $this->product('sticker', 3)], ['shipping' => $this->delivery]);

        $this->assertSame(0, $quote['problems']);
        [$book, $mug, $sticker] = $quote['lines'];
        // 7% included in 300.00 is 19.63; 7% added to 200.00 is 14.00
        $this->assertSame(['I', '19.63', '300.00'], [$book['tax']['calculation'], $book['tax']['amount'], $book['total']]);
        $this->assertSame(['E', '14.00', '214.00'], [$mug['tax']['calculation'], $mug['tax']['amount'], $mug['total']]);
        $this->assertSame(['X', '0.00', '30.00'], [$sticker['tax']['calculation'], $sticker['tax']['amount'], $sticker['total']]);
        // 400 + 1,400 + 30 g = 1,830 g: 50.00 + 1 step of 15.00
        $this->assertSame('65.00', $quote['shipping']['fee']);
        $this->assertSame(['subtotal' => '530.00', 'discount' => '0.00', 'shipping' => '65.00', 'tax_included' => '19.63', 'tax_added' => '14.00', 'total' => '609.00'],
            $quote['totals']);
        $this->assertTrue($quote['needs_shipping']);
        $this->assertSame([$this->cash], array_column($quote['payment_methods'], 'public_id'));
    }

    public function testDeliveryIsFreeOverItsAmount(): void
    {
        $quote = $this->quote([$this->product('book', 7)], ['shipping' => $this->delivery]);
        // seven books are more than either store has alone
        $this->assertSame(['code' => 'only_left', 'available' => 5], $quote['lines'][0]['problem']);

        $quote = $this->quote([$this->product('mug', 10), $this->product('book', 4)], ['shipping' => $this->delivery]);
        $this->assertSame('0.00', $quote['shipping']['fee']);
    }

    public function testOneStoreWithEverythingIsChosenAndAnOnlineStoreFirst(): void
    {
        $silom     = $this->stores['Silom'][1];
        $warehouse = $this->stores['Warehouse'][1];

        // both have two books: the online store
        $quote = $this->quote([$this->product('book', 2), $this->product('sticker', 1)]);
        $this->assertSame([$warehouse, $warehouse], array_column(array_column($quote['lines'], 'store'), 'public_id'));
        // only Silom has mugs and three books: all from Silom
        $quote = $this->quote([$this->product('book', 3), $this->product('mug', 1)]);
        $this->assertSame([$silom, $silom], array_column(array_column($quote['lines'], 'store'), 'public_id'));

        // no store has both: each line from where it is
        $this->db->table('artifact_variant_inventory')->where('variant_id', $this->variants['book'][0])->where('store_id', $this->stores['Silom'][0])->update(['quantity' => 0]);
        $quote = $this->quote([$this->product('book', 2), $this->product('mug', 1)]);
        $this->assertSame(0, $quote['problems']);
        $this->assertSame([$warehouse, $silom], array_column(array_column($quote['lines'], 'store'), 'public_id'));
    }

    public function testStockAndSeatsAreCheckedAgain(): void
    {
        $quote = $this->quote([$this->product('book', 8), $this->seats(8), ['key' => 'gone', 'kind' => 'P', 'variant' => 'nope12345678', 'quantity' => 1]]);
        $this->assertSame(3, $quote['problems']);
        $this->assertSame(['code' => 'only_left', 'available' => 7], $quote['lines'][0]['problem']);
        $this->assertSame(['code' => 'seats_left', 'available' => 7], $quote['lines'][1]['problem']);
        $this->assertSame('not_found', $quote['lines'][2]['problem']['code']);
        // a line with a problem counts in no total
        $this->assertSame('0.00', $quote['totals']['total']);

        $this->db->table('service_session')->where('id', $this->sessionId)->update(['session_status' => 'C']);
        $this->assertSame('session_gone', $this->quote([$this->seats(1)])['lines'][0]['problem']['code']);
    }

    public function testADiscountCodeIsSharedOverTheLinesItAppliesTo(): void
    {
        $this->discount('BOOKS10', ['discount_type' => 'P', 'discount_value' => '10.00', 'applies_to' => 'P', 'min_subtotal' => '100.00']);

        $quote = $this->quote([$this->product('book', 1), $this->product('mug', 1), $this->seats(1)], ['discount' => 'books10']);
        $this->assertNull($quote['discount_error']);
        $this->assertSame(['code' => 'BOOKS10', 'description' => null, 'amount' => '40.00'], $quote['discount']);
        // 30.00 off the book, 10.00 off the mug, nothing off the class; tax on what is left
        $this->assertSame(['30.00', '10.00', '0.00'], array_column($quote['lines'], 'discount'));
        $this->assertSame('17.66', $quote['lines'][0]['tax']['amount']);
        $this->assertSame('6.30', $quote['lines'][1]['tax']['amount']);
        $this->assertSame('1866.30', $quote['totals']['total']);

        $this->assertSame(['code' => 'min_subtotal', 'amount' => '100.00'], $this->quote([$this->product('sticker', 1)], ['discount' => 'BOOKS10'])['discount_error']);
        $this->assertSame('not_found', $this->quote([$this->product('book', 1)], ['discount' => 'NOPE'])['discount_error']['code']);
        $this->discount('OLD', ['expires_at' => '2026-11-01 00:00:00']);
        $this->assertSame('expired', $this->quote([$this->product('book', 1)], ['discount' => 'OLD'])['discount_error']['code']);
        $this->discount('ONCE', ['max_redemptions' => 1, 'redemption_count' => 1]);
        $this->assertSame('used_up', $this->quote([$this->product('book', 1)], ['discount' => 'ONCE'])['discount_error']['code']);
    }

    public function testAnOrderTakesTheStockAndSeatsAndCanBeShownWithItsKey(): void
    {
        $this->discount('TAKE50', ['discount_type' => 'F', 'discount_value' => '50.00']);
        $response = $this->order([$this->product('book', 2), $this->seats(2, ['Ann', 'Ben'])], ['shipping' => $this->delivery, 'discount' => 'TAKE50']);
        $response->assertStatus(201);
        $order = $this->body($response)['data']['order'];

        $this->assertSame('P', $order['status']);
        $this->assertSame(['Ann', 'Ben'], $order['lines'][1]['participants']);
        $this->assertSame('1 Main Rd', $order['shipping']['address']['line_1']);
        $this->assertSame('Cash', $order['payment']['name']);
        $this->assertSame('Pay at the counter.', $order['payment']['instruction']);
        $this->assertSame(['3600.00', '50.00', '50.00'], [$order['totals']['subtotal'], $order['totals']['discount'], $order['totals']['shipping']]);
        $this->assertSame('3600.00', $order['totals']['total']);

        // from the online store, which had two
        $this->assertSame(0, (int) $this->db->table('artifact_variant_inventory')->where('variant_id', $this->variants['book'][0])
            ->where('store_id', $this->stores['Warehouse'][0])->get()->getRow()->quantity);
        $this->assertSame(5, (int) $this->db->table('service_session')->where('id', $this->sessionId)->get()->getRow()->seats_booked);
        $this->assertSame(1, (int) $this->db->table('business_discount')->where('discount_code', 'TAKE50')->get()->getRow()->redemption_count);
        $this->assertSame('Otter Customer', $this->db->table('customer_master')->where('email_address', 'otter@example.com')->get()->getRow()->customer_name);

        // the business's number, the first of November in Bangkok
        $this->assertSame('IN2611-0001', $order['order_number']);
        $second = $this->body($this->order([$this->product('mug', 1)], ['shipping' => $this->delivery]))['data']['order'];
        $this->assertSame('IN2611-0002', $second['order_number']);

        $path = 'v1/mart/order?business_slug=otter-shop&number=' . $order['order_number'] . '&key=';
        $this->get('v1/mart/order?business_slug=otter-shop&public_id=' . $order['public_id'] . '&key=' . $order['key'])->assertStatus(200);
        $this->get($path . $order['key'])->assertStatus(200);
        $this->get($path . 'wrong')->assertStatus(404);
    }

    public function testAnOrderThatCannotBeHadIsRefusedWithNothingWritten(): void
    {
        $response = $this->order([$this->product('book', 3), $this->product('mug', 11)], ['shipping' => $this->delivery]);
        $response->assertStatus(409);
        $body = $this->body($response);
        $this->assertSame('problems', $body['data']['reason']);
        $this->assertSame(['code' => 'only_left', 'available' => 10], $body['data']['quote']['lines'][1]['problem']);
        $this->assertSame(0, $this->db->table('order_master')->countAllResults());
        // a refused order takes no number
        $this->assertSame(0, $this->db->table('business_order_sequence')->countAllResults());
        $this->assertSame(5, (int) $this->db->table('artifact_variant_inventory')->where('variant_id', $this->variants['book'][0])
            ->where('store_id', $this->stores['Silom'][0])->get()->getRow()->quantity);

        // the names of the people, the address, the shipping and the payment are needed
        $response = $this->order([$this->product('mug', 1), $this->seats(2, ['Ann', ''])], ['shipping' => $this->delivery], ['line_1' => '']);
        $response->assertStatus(409);
        $this->assertSame(['address.line_1' => 'required', 'lines.s1.participants' => 'required'], $this->body($response)['errors']);
        $this->assertSame('shipping', $this->body($this->order([$this->product('mug', 1)], []))['data']['reason']);
        $this->assertSame('payment', $this->body($this->order([$this->product('mug', 1)], ['shipping' => $this->delivery, 'payment' => '']))['data']['reason']);

        $response = $this->withBodyFormat('json')->post('v1/mart/order', ['business_slug' => 'otter-shop', 'lines' => [$this->product('mug', 1)], 'customer' => ['email' => 'nope']]);
        $response->assertStatus(422);
        $this->assertEqualsCanonicalizing(['customer.first_name', 'customer.last_name', 'customer.email', 'customer.telephone'], array_keys($this->body($response)['errors']));
    }

    public function testAPromptPayPayloadCarriesTheIdAndAmount(): void
    {
        $payload = PromptPay::payload('081-234-5678', '1500');
        $this->assertStringContainsString('0016A00000067701011101130066812345678', $payload);
        $this->assertStringContainsString('54071500.00', $payload);
        $this->assertMatchesRegularExpression('/6304[0-9A-F]{4}$/', $payload);
        $this->assertNull(PromptPay::payload('12345', '10.00'));
    }

    public function testACustomerFindsTheirOrderByItsNumberWithTheirEmailOrTelephone(): void
    {
        $order = $this->body($this->order([$this->product('mug', 1)], ['shipping' => $this->delivery]))['data']['order'];
        $number = $order['order_number'];

        foreach (['otter@example.com', ' OTTER@Example.COM ', '0812345678', '+66 81-234-5678', '0066 81 234 5678', '812345678'] as $contact) {
            $response = $this->lookup($number, $contact);
            $response->assertStatus(200);
            $found = $this->body($response)['data']['order'];
            $this->assertSame($number, $found['order_number']);
            $this->assertSame('P', $found['status']);
            $this->assertArrayNotHasKey('key', $found);
        }
        // lower case, as a customer may type it
        $this->lookup(strtolower($number), '0812345678')->assertStatus(200);

        // someone else's contact, or another order's number, is no order, alike
        foreach (['other@example.com', '0812345679', '12345678', 'otter@example'] as $contact) {
            $this->lookup($number, $contact)->assertStatus(404);
        }
        $this->lookup('IN2611-0099', 'otter@example.com')->assertStatus(404);
        $this->lookup('', 'otter@example.com')->assertStatus(422);
        $this->lookup($number, '')->assertStatus(422);

        // only at the business that took it
        $this->business('other-shop');
        $this->withBodyFormat('json')->post('v1/mart/order/lookup', ['business_slug' => 'other-shop', 'lang' => 'en-US', 'number' => $number, 'contact' => 'otter@example.com'])
            ->assertStatus(404);
    }

    public function testWrongContactsForAnOrderNumberAreThrottled(): void
    {
        $number = $this->body($this->order([$this->product('mug', 1)], ['shipping' => $this->delivery]))['data']['order']['order_number'];
        for ($i = 0; $i < config('Mart')->lookupMaxFailures; $i++) {
            $this->lookup($number, 'guess' . $i . '@example.com')->assertStatus(404);
        }
        // even the right one, until the window is over
        $this->lookup($number, 'otter@example.com')->assertStatus(429);
    }

    // --- helpers ------------------------------------------------------------------------------------------------

    private function product(string $name, int $quantity): array
    {
        return ['key' => 'p' . $name, 'kind' => 'P', 'variant' => $this->variants[$name][1], 'quantity' => $quantity];
    }

    private function seats(int $quantity, array $people = []): array
    {
        return ['key' => 's1', 'kind' => 'G', 'variant' => $this->variants['class'][1], 'quantity' => $quantity, 'session' => $this->classSession, 'participants' => $people];
    }

    private function discount(string $code, array $columns): void
    {
        $this->db->table('business_discount')->insert($columns + ['public_id' => $this->fixturePublicId(), 'business_id' => $this->businessId,
            'discount_code' => $code, 'discount_type' => 'P', 'discount_value' => '10.00']);
    }

    private function quote(array $lines, array $choice = []): array
    {
        $response = $this->withBodyFormat('json')->post('v1/mart/quote', ['business_slug' => 'otter-shop', 'lang' => 'en-US', 'lines' => $lines] + $choice);
        $response->assertStatus(200);

        return $this->body($response)['data'];
    }

    private function lookup(string $number, string $contact): \CodeIgniter\Test\TestResponse
    {
        return $this->withBodyFormat('json')->post('v1/mart/order/lookup', ['business_slug' => 'otter-shop', 'lang' => 'en-US', 'number' => $number, 'contact' => $contact]);
    }

    private function order(array $lines, array $choice, array $address = []): \CodeIgniter\Test\TestResponse
    {
        return $this->withBodyFormat('json')->post('v1/mart/order', ['business_slug' => 'otter-shop', 'lang' => 'en-US', 'lines' => $lines] + $choice + ['payment' => $this->cash,
            'customer' => ['first_name' => 'Otter', 'last_name' => 'Customer', 'email' => 'Otter@Example.com', 'telephone' => '081 234 5678'],
            'delivery' => $address + ['line_1' => '1 Main Rd', 'city' => 'Bangkok', 'postal_code' => '10500'],
        ]);
    }
}
