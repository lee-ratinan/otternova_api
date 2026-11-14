<?php

use App\Libraries\EmailService;
use CodeIgniter\I18n\Time;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\Mock\MockCache;
use Config\Services;
use Tests\Support\ArtifactFixturesTrait;

/**
 * /client/order/cancel, /client/order/no-show and /client/order/refund-close: anyone working in a business cancels an
 * order before any of it is sent or starts, giving everything back; the owners and managers are notified and refund
 * what was paid; the customer is emailed. Once a booked service starts it is a no-show, which flags an unpaid customer.
 *
 * @internal
 */
final class SalesOrderCancelTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use ArtifactFixturesTrait;

    protected $namespace = 'App';

    private string $ownerToken;
    private string $employeeToken;
    private int $businessId;
    private int $storeId;
    private int $bookId;
    private string $book;
    private string $classVariant;
    private string $classSession;
    private int $sessionId;
    private string $pickup;
    private string $cash;
    private EmailService $mailer;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['jwt', 'authContext', 'martCache'] as $service) {
            Services::resetSingle($service);
        }
        Services::injectMock('cache', new MockCache());
        $this->mailer = new class () extends EmailService {
            public array $sent = [];

            protected function deliver(string $to, string $subject, string $body): bool
            {
                $this->sent[] = ['to' => $to, 'subject' => $subject, 'body' => $body];

                return true;
            }
        };
        Services::injectMock('emailService', $this->mailer);
        // Monday 16 Nov 2026, 10:00 in Bangkok
        Time::setTestNow('2026-11-16 03:00:00', 'UTC');

        $this->businessId    = $this->business('otter-shop');
        $this->ownerToken    = $this->createClient('owner@example.com', $this->businessId, 'O');
        $this->employeeToken = $this->createClient('staff@example.com', $this->businessId, 'E');
        $this->createClient('manager@example.com', $this->businessId, 'M');
        [$this->storeId] = $this->store($this->businessId, 'Silom');
        $this->db->table('business_store')->where('id', $this->storeId)->update(['store_type' => 'P']);

        [$artifactId] = $this->artifactRow($this->businessId);
        $this->book   = $this->fixturePublicId();
        $this->db->table('artifact_variant')->insert(['public_id' => $this->book, 'business_id' => $this->businessId, 'artifact_id' => $artifactId, 'price_active' => '300.00']);
        $this->bookId = (int) $this->db->insertID();
        $this->db->table('artifact_variant_inventory')->insert(['variant_id' => $this->bookId, 'store_id' => $this->storeId, 'quantity' => 10]);

        // a cooking class of 10 on Friday 20 Nov, 09:00 in Bangkok
        [$serviceId]        = $this->artifactRow($this->businessId, 'S');
        $this->classVariant = $this->fixturePublicId();
        $this->db->table('artifact_variant')->insert(['public_id' => $this->classVariant, 'business_id' => $this->businessId, 'artifact_id' => $serviceId,
            'price_active' => '1500.00', 'duration_minutes' => 180, 'booking_type' => 'G', 'capacity' => 10, 'staff_required' => 1]);
        $variantId          = (int) $this->db->insertID();
        $this->classSession = $this->fixturePublicId();
        $this->db->table('service_session')->insert(['public_id' => $this->classSession, 'business_id' => $this->businessId, 'store_id' => $this->storeId,
            'artifact_id' => $serviceId, 'variant_id' => $variantId, 'session_name' => 'Friday class', 'capacity' => 10, 'seats_booked' => 0,
            'starts_at' => '2026-11-20 02:00:00', 'ends_at' => '2026-11-20 05:00:00', 'session_status' => 'O']);
        $this->sessionId = (int) $this->db->insertID();
        $this->db->table('service_session_slot')->insert(['business_id' => $this->businessId, 'session_id' => $this->sessionId, 'slot_number' => 1,
            'starts_at' => '2026-11-20 02:00:00', 'ends_at' => '2026-11-20 05:00:00', 'slot_status' => 'S']);

        $this->pickup = $this->fixturePublicId();
        $this->db->table('business_shipping_method')->insert(['public_id' => $this->pickup, 'business_id' => $this->businessId, 'method_type' => 'S',
            'method_name' => 'Pickup', 'fee_basis' => 'P', 'store_id' => $this->storeId]);
        $this->db->table('business_shipping_rate')->insert(['shipping_method_id' => (int) $this->db->insertID(), 'range_from' => '0.00', 'range_to' => null, 'fee_amount' => '0.00']);
        $this->cash = $this->fixturePublicId();
        $this->db->table('business_payment_method')->insert(['public_id' => $this->cash, 'business_id' => $this->businessId, 'method_code' => 'cash',
            'handler_code' => 'instruction', 'method_name' => 'Cash']);
        $this->db->table('business_discount')->insert(['public_id' => $this->fixturePublicId(), 'business_id' => $this->businessId,
            'discount_code' => 'TEN', 'discount_type' => 'F', 'discount_value' => '10.00']);
    }

    protected function tearDown(): void
    {
        Time::setTestNow();
        parent::tearDown();
    }

    public function testAnUnpaidOrderIsCancelledAndEverythingGivenBack(): void
    {
        $publicId = $this->place(['book' => 2, 'class' => 3], 'TEN');
        $this->assertSame(8, $this->stock());
        $this->assertSame(3, $this->seats());
        $this->assertSame(1, $this->redemptions());

        // a reason is needed
        $response = $this->cancel($publicId, ' ok ', $this->employeeToken);
        $response->assertStatus(422);
        $this->assertSame(['reason'], array_keys($this->body($response)['errors']));

        $response = $this->cancel($publicId, 'Customer  changed their mind', $this->employeeToken);
        $response->assertStatus(200);
        $order = $this->body($response)['data']['order'];
        $this->assertSame(['closed', 'pending', 'Customer changed their mind', '0.00', 'Otter Tester'],
            [$order['order_status'], $order['financial_status'], $order['cancel_reason'], $order['refund_due_amount'], $order['cancelled_by_name']]);
        $this->assertFalse($order['can_cancel']);
        $this->assertSame('voided', $order['payments'][0]['transaction_status']);
        $this->assertSame([10, 0, 0], [$this->stock(), $this->seats(), $this->redemptions()]);

        // the owner and the manager are told; the employee who did it is not
        $notes = $this->db->table('notification_master')->get()->getResultArray();
        $this->assertCount(2, $notes);
        $this->assertSame(['order.cancelled_unpaid'], array_values(array_unique(array_column($notes, 'event_type'))));
        $this->assertSame('/admin/sales/order/' . $publicId, $notes[0]['target_url']);

        // the customer is emailed, with where to see the order
        $this->assertCount(1, $this->mailer->sent);
        $this->assertSame('ann@example.com', $this->mailer->sent[0]['to']);
        $this->assertStringContainsString('was cancelled', $this->mailer->sent[0]['subject']);
        $this->assertStringContainsString('/otter-shop/check-order-status/', $this->mailer->sent[0]['body']);
        $this->assertStringContainsString('nothing to refund', $this->mailer->sent[0]['body']);

        // once only, and its statuses stay
        $this->assertSame(lang('SalesOrder.cannotCancel.ended'), $this->body($this->cancel($publicId, 'Again please'))['message']);
        $this->send('v1/client/order/status', ['public_id' => $publicId, 'order_status' => 'open'])->assertStatus(409);
    }

    public function testAPaidOrderLeavesARefundForTheManagers(): void
    {
        $publicId = $this->place(['book' => 1]);
        $this->pay($publicId, '300.00');

        $order = $this->body($this->cancel($publicId, 'Out of stock at the store', $this->employeeToken))['data']['order'];
        $this->assertSame(['paid', '300.00'], [$order['financial_status'], $order['refund_due_amount']]);
        $this->assertSame(2, $this->db->table('notification_master')->where('event_type', 'order.cancelled')->where('read_at', null)->countAllResults());
        $this->assertStringContainsString('THB 300.00', $this->mailer->sent[0]['body']);

        // the employee cannot refund it; listed as needing a refund
        $this->send('v1/client/order/payment/create', ['public_id' => $publicId, 'payment' => ['transaction_type' => 'refund', 'amount' => '300']], $this->employeeToken)
            ->assertStatus(403);
        $this->assertSame([$publicId], array_column($this->list(['needs_refund' => true]), 'public_id'));

        $order = $this->refund($publicId, '100.00');
        $this->assertSame(['partially_refunded', '200.00'], [$order['financial_status'], $order['refund_due_amount']]);
        $order = $this->refund($publicId, '200.00');
        $this->assertSame(['full_refunded', '0.00'], [$order['financial_status'], $order['refund_due_amount']]);
        $this->assertSame([], $this->list(['needs_refund' => true]));
        // handled: the notifications are read
        $this->assertSame(0, $this->db->table('notification_master')->where('read_at', null)->countAllResults());
    }

    public function testARefundCanBeSettledWithAFeeKept(): void
    {
        $publicId = $this->place(['book' => 1]);
        $this->pay($publicId, '300.00');
        $this->cancel($publicId, 'Late cancellation by phone')->assertStatus(200);
        $this->refund($publicId, '250.00');

        $this->send('v1/client/order/refund-close', ['public_id' => $publicId, 'reason' => 'Fee kept'], $this->employeeToken)->assertStatus(403);
        $this->send('v1/client/order/refund-close', ['public_id' => $publicId])->assertStatus(422);
        $order = $this->body($this->send('v1/client/order/refund-close', ['public_id' => $publicId, 'reason' => '50 kept as the late fee']))['data']['order'];
        $this->assertSame(['partially_refunded', '0.00', '50 kept as the late fee'], [$order['financial_status'], $order['refund_due_amount'], $order['refund_closed_reason']]);
        $this->assertSame(lang('SalesOrder.noRefundDue'), $this->body($this->send('v1/client/order/refund-close', ['public_id' => $publicId, 'reason' => 'Again']))['message']);
    }

    public function testNothingSentOrStartedCanBeCancelledAndAStartedOneIsANoShow(): void
    {
        $sent = $this->place(['book' => 1]);
        $this->send('v1/client/order/status', ['public_id' => $sent, 'fulfillment_status' => 'partially_fulfilled'])->assertStatus(200);
        $this->assertSame(lang('SalesOrder.cannotCancel.fulfilled'), $this->body($this->cancel($sent, 'Changed my mind'))['message']);

        $class = $this->place(['class' => 2]);
        $show  = $this->body($this->send('v1/client/order', ['public_id' => $class]))['data']['order'];
        $this->assertSame([true, false], [$show['can_cancel'], $show['can_mark_no_show']]);
        $this->send('v1/client/order/no-show', ['public_id' => $class])->assertStatus(409);

        // Friday, 09:30 in Bangkok: the class has started
        Time::setTestNow('2026-11-20 02:30:00', 'UTC');
        $this->assertSame(lang('SalesOrder.cannotCancel.started'), $this->body($this->cancel($class, 'Customer called'))['message']);
        $response = $this->send('v1/client/order/no-show', ['public_id' => $class], $this->employeeToken);
        $response->assertStatus(200);
        $this->assertSame(lang('SalesOrder.noShowFlagged'), $this->body($response)['message']);
        $order = $this->body($response)['data']['order'];
        $this->assertSame(['closed', 1, false], [$order['order_status'], $order['customer']['no_show_count'], $order['can_mark_no_show']]);
        $this->assertNotNull($order['no_show_at']);
        // the seats stay taken: the time has gone
        $this->assertSame(2, $this->seats());
        $this->assertSame(1, $this->db->table('business_customer_flag')->where('flag_type', 'no_show')->countAllResults());
    }

    public function testAPaidNoShowFlagsNobody(): void
    {
        $class = $this->place(['class' => 1]);
        $this->pay($class, '1500.00');
        Time::setTestNow('2026-11-20 03:00:00', 'UTC');
        $response = $this->send('v1/client/order/no-show', ['public_id' => $class]);
        $this->assertSame(lang('SalesOrder.noShowMarked'), $this->body($response)['message']);
        $this->assertSame(0, $this->db->table('business_customer_flag')->countAllResults());
    }

    public function testANoShowAppointmentFreesItsStaffAndRoomForTheRestOfItsTime(): void
    {
        // a massage on Friday 10:00-11:00 in Bangkok with the employee, in a room with a 15-minute turnover
        $publicId = $this->place(['book' => 1]);
        $orderId  = (int) $this->db->table('order_master')->where('public_id', $publicId)->get()->getRow()->id;
        $line     = $this->db->table('order_line')->where('order_id', $orderId)->get()->getRowArray();
        $staffId  = (int) $this->db->table('business_client_link')->where('client_role', 'E')->get()->getRow()->client_id;
        $this->db->table('resource_type')->insert(['public_id' => $this->fixturePublicId(), 'business_id' => $this->businessId, 'type_name' => 'Room', 'buffer_minutes' => 15]);
        $this->db->table('resource_master')->insert(['public_id' => $this->fixturePublicId(), 'business_id' => $this->businessId, 'store_id' => $this->storeId,
            'resource_type_id' => (int) $this->db->insertID(), 'resource_name' => 'Room 1']);
        $roomId = (int) $this->db->insertID();
        $session = $this->db->table('service_session')->where('id', $this->sessionId)->get()->getRowArray();
        $this->db->table('service_session')->insert(['public_id' => $this->fixturePublicId(), 'business_id' => $this->businessId, 'store_id' => $this->storeId,
            'artifact_id' => $session['artifact_id'], 'variant_id' => $session['variant_id'], 'session_name' => 'Massage', 'capacity' => 1, 'seats_booked' => 1,
            'starts_at' => '2026-11-20 03:00:00', 'ends_at' => '2026-11-20 04:00:00', 'session_status' => 'C']);
        $massageId = (int) $this->db->insertID();
        $this->db->table('service_session_slot')->insert(['business_id' => $this->businessId, 'session_id' => $massageId, 'slot_number' => 1,
            'starts_at' => '2026-11-20 03:00:00', 'ends_at' => '2026-11-20 04:00:00', 'slot_status' => 'S']);
        $slotId = (int) $this->db->insertID();
        $this->db->table('service_slot_staff')->insert(['business_id' => $this->businessId, 'slot_id' => $slotId, 'client_id' => $staffId,
            'starts_at' => '2026-11-20 03:00:00', 'ends_at' => '2026-11-20 04:00:00']);
        $this->db->table('service_slot_resource')->insert(['business_id' => $this->businessId, 'slot_id' => $slotId, 'resource_id' => $roomId,
            'starts_at' => '2026-11-20 03:00:00', 'held_until' => '2026-11-20 04:15:00']);
        $this->db->table('order_line')->insert(['order_id' => $orderId, 'line_number' => 2, 'line_kind' => 'A', 'artifact_id' => $session['artifact_id'],
            'variant_id' => $session['variant_id'], 'artifact_name' => 'Massage', 'store_id' => $this->storeId, 'session_id' => $massageId,
            'starts_at' => '2026-11-20 03:00:00', 'ends_at' => '2026-11-20 04:00:00', 'unit_price' => '900.00', 'quantity' => 1, 'tax_calculation' => 'X',
            'line_total' => '900.00']);

        // 10:15: the customer has not come
        Time::setTestNow('2026-11-20 03:15:00', 'UTC');
        $this->send('v1/client/order/no-show', ['public_id' => $publicId], $this->employeeToken)->assertStatus(200);

        $this->assertSame('2026-11-20 03:15:00', $this->db->table('service_session_slot')->where('id', $slotId)->get()->getRow()->ends_at);
        $this->assertSame('2026-11-20 03:15:00', $this->db->table('service_session')->where('id', $massageId)->get()->getRow()->ends_at);
        // who was booked stays on record; staff and room free from 10:15: an unused room needs no turnover
        $staff = $this->db->table('service_slot_staff')->where('slot_id', $slotId)->get()->getRowArray();
        $this->assertSame([$staffId, '2026-11-20 03:15:00'], [(int) $staff['client_id'], $staff['ends_at']]);
        $this->assertSame('2026-11-20 03:15:00', $this->db->table('service_slot_resource')->where('slot_id', $slotId)->get()->getRow()->held_until);
        $this->assertSame(0, $this->db->table('service_slot_staff')->where('client_id', $staffId)->where('starts_at <', '2026-11-20 04:00:00')
            ->where('ends_at >', '2026-11-20 03:15:00')->countAllResults());
    }

    // --- helpers ------------------------------------------------------------------------------------------------

    /**
     * @param array<string, int> $items book and class quantities
     */
    private function place(array $items, ?string $discount = null): string
    {
        $lines = [];
        if (isset($items['book'])) {
            $lines[] = ['key' => 'b', 'kind' => 'P', 'variant' => $this->book, 'quantity' => $items['book']];
        }
        if (isset($items['class'])) {
            $lines[] = ['key' => 'c', 'kind' => 'G', 'variant' => $this->classVariant, 'quantity' => $items['class'], 'session' => $this->classSession,
                'participants' => array_fill(0, $items['class'], 'Ann')];
        }
        $response = $this->withBodyFormat('json')->post('v1/mart/order', array_filter(['business_slug' => 'otter-shop', 'lang' => 'en-US', 'lines' => $lines,
            'shipping' => isset($items['book']) ? $this->pickup : null, 'payment' => $this->cash, 'discount' => $discount,
            'customer' => ['first_name' => 'Ann', 'last_name' => 'Lee', 'email' => 'ann@example.com', 'telephone' => '081 234 5678']]));
        $response->assertStatus(201);
        $this->mailer->sent = []; // its confirmation: OrderNoticesTest's

        return $this->body($response)['data']['order']['public_id'];
    }

    private function cancel(string $publicId, string $reason, ?string $token = null): \CodeIgniter\Test\TestResponse
    {
        return $this->send('v1/client/order/cancel', ['public_id' => $publicId, 'reason' => $reason], $token);
    }

    private function pay(string $publicId, string $amount): void
    {
        $this->send('v1/client/order/payment/create', ['public_id' => $publicId, 'payment' => ['amount' => $amount]])->assertStatus(200);
    }

    private function refund(string $publicId, string $amount): array
    {
        $response = $this->send('v1/client/order/payment/create', ['public_id' => $publicId, 'payment' => ['transaction_type' => 'refund', 'amount' => $amount]]);
        $response->assertStatus(200);

        return $this->body($response)['data']['order'];
    }

    private function list(array $filters): array
    {
        return $this->body($this->send('v1/client/orders', ['business_slug' => 'otter-shop', 'filters' => $filters]))['data']['rows'];
    }

    private function stock(): int
    {
        return (int) $this->db->table('artifact_variant_inventory')->where('variant_id', $this->bookId)->get()->getRow()->quantity;
    }

    private function seats(): int
    {
        return (int) $this->db->table('service_session')->where('id', $this->sessionId)->get()->getRow()->seats_booked;
    }

    private function redemptions(): int
    {
        return (int) $this->db->table('business_discount')->where('discount_code', 'TEN')->get()->getRow()->redemption_count;
    }
}
