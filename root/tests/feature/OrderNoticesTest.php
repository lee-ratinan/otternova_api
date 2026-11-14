<?php

use App\Libraries\EmailService;
use App\Libraries\Notifier;
use CodeIgniter\I18n\Time;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\Mock\MockCache;
use CodeIgniter\Test\TestResponse;
use Config\Services;
use Tests\Support\ArtifactFixturesTrait;

/**
 * What an order tells whom: its customer the confirmation (how to pay while it is not paid), the people booked that
 * their time or more seats are taken (and whether it is paid); and once the customer says they paid
 * (/mart/order/report-payment), the people of its store, to check it and mark it received
 *
 * @internal
 */
final class OrderNoticesTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use ArtifactFixturesTrait;

    protected $namespace = 'App';

    private string $ownerToken;
    private int $businessId;
    private int $storeId;
    private string $store;
    private int $serviceId;
    private string $massage;
    private string $yoga;
    private string $yogaSession;
    /** @var array<string, array{int, string, int}> name => [client_id, public_id, auth_id] */
    private array $people = [];
    private string $transfer;
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

        $this->businessId = $this->business('otter-spa');
        $this->ownerToken = $this->createClient('owner@example.com', $this->businessId, 'O');
        [$this->storeId, $this->store] = $this->store($this->businessId, 'Silom');
        $this->db->table('business_store')->where('id', $this->storeId)->update(['store_type' => 'P']);
        foreach (range(1, 7) as $day) {
            $this->db->table('business_store_hours')->insert(['store_id' => $this->storeId, 'day_of_week' => $day, 'open_time' => '10:00:00', 'close_time' => '20:00:00']);
        }

        [$this->serviceId] = $this->artifactRow($this->businessId, 'S');
        $this->db->table('artifact_master')->where('id', $this->serviceId)->update(['artifact_name' => 'Thai massage']);
        $this->massage = $this->fixturePublicId();
        $this->db->table('artifact_variant')->insert(['public_id' => $this->massage, 'business_id' => $this->businessId, 'artifact_id' => $this->serviceId,
            'price_active' => '600.00', 'duration_minutes' => 60, 'booking_type' => 'A', 'staff_required' => 1]);
        foreach (['Somsak', 'Anong', 'Nok'] as $name) {
            $this->person($name);
        }

        // Anong teaches the Wednesday yoga class of 10, 2 taken
        [$yogaId]   = $this->artifactRow($this->businessId, 'S');
        $this->yoga = $this->fixturePublicId();
        $this->db->table('artifact_variant')->insert(['public_id' => $this->yoga, 'business_id' => $this->businessId, 'artifact_id' => $yogaId,
            'price_active' => '300.00', 'duration_minutes' => 60, 'booking_type' => 'G', 'capacity' => 10, 'staff_required' => 1]);
        $this->yogaSession = $this->fixturePublicId();
        $this->db->table('service_session')->insert(['public_id' => $this->yogaSession, 'business_id' => $this->businessId, 'store_id' => $this->storeId,
            'artifact_id' => $yogaId, 'variant_id' => (int) $this->db->insertID(), 'session_name' => 'Morning yoga', 'capacity' => 10, 'seats_booked' => 2,
            'starts_at' => '2026-11-18 02:00:00', 'ends_at' => '2026-11-18 03:00:00', 'session_status' => 'O']);
        $sessionId = (int) $this->db->insertID();
        $this->db->table('service_session_slot')->insert(['business_id' => $this->businessId, 'session_id' => $sessionId, 'slot_number' => 1,
            'starts_at' => '2026-11-18 02:00:00', 'ends_at' => '2026-11-18 03:00:00', 'slot_status' => 'S']);
        $this->db->table('service_slot_staff')->insert(['business_id' => $this->businessId, 'slot_id' => (int) $this->db->insertID(), 'client_id' => $this->people['Anong'][0],
            'starts_at' => '2026-11-18 02:00:00', 'ends_at' => '2026-11-18 03:00:00']);

        foreach (['transfer' => ['bank_transfer', 'Bank transfer', 'Transfer to KBank 123-4-56789-0.'], 'cash' => ['cash', 'Cash', 'Pay at the counter.']] as $var => [$code, $name, $how]) {
            $this->{$var} = $this->fixturePublicId();
            $this->db->table('business_payment_method')->insert(['public_id' => $this->{$var}, 'business_id' => $this->businessId, 'method_code' => $code,
                'handler_code' => 'instruction', 'method_name' => $name, 'instruction' => $how]);
        }
    }

    protected function tearDown(): void
    {
        Time::setTestNow();
        parent::tearDown();
    }

    public function testABookingIsConfirmedToTheCustomerAndToldToWhomItBooks(): void
    {
        $order = $this->book($this->transfer);

        // the customer: what was booked, that it is not paid, how to pay, and the page that pays it
        $this->assertCount(1, $this->mailer->sent);
        $mail = $this->mailer->sent[0];
        $this->assertSame('ann@example.com', $mail['to']);
        $this->assertStringContainsString('Your order ' . $order['order_number'], $mail['subject']);
        foreach (['Thai massage × 1, 2026-11-18 11:00', 'THB 600.00', 'It is not paid yet. Payment: Bank transfer', 'Transfer to KBank 123-4-56789-0.',
            '/otter-spa/payment/' . $order['order_number'] . '?key=' . $order['key']] as $text) {
            $this->assertStringContainsString($text, $mail['body']);
        }

        // Somsak, booked: his time is taken, not paid yet; nobody else
        $notes = $this->notes(Notifier::ORDER_BOOKED);
        $this->assertSame([$this->people['Somsak'][2]], array_map('intval', array_column($notes, 'auth_id')));
        $shown = Notifier::present($notes[0]);
        $this->assertSame('New booking: Thai massage', $shown['title']);
        $this->assertStringContainsString('Ann Lee booked Thai massage with you on 2026-11-18 11:00 at Silom', $shown['message']);
        $this->assertStringContainsString('Payment: not paid yet, so it may still be cancelled or not come.', $shown['message']);
        $this->assertSame('/admin/sales/order/' . $order['public_id'], $shown['target_url']);
    }

    public function testCashAtTheStoreAndPaidNowAreToldAsSuch(): void
    {
        $this->book($this->cash);
        $this->assertStringContainsString('Payment: to be paid at the store.', Notifier::present($this->notes(Notifier::ORDER_BOOKED)[0])['message']);

        // taken at the counter and paid there: the customer is told it is paid, the person booked too
        $this->db->table('notification_master')->truncate();
        $slots = $this->body($this->send('v1/client/counter/slots', ['business_slug' => 'otter-spa', 'store_public_id' => $this->store, 'variant' => $this->massage, 'date' => '2026-11-19']))['data']['slots'];
        $this->send('v1/client/counter/order', ['business_slug' => 'otter-spa', 'store_public_id' => $this->store, 'payment' => $this->cash, 'paid_now' => true,
            'lines'    => [['key' => 'm', 'kind' => 'A', 'variant' => $this->massage, 'quantity' => 1, 'store' => $this->store, 'starts_at' => $slots[0]['starts_at'],
                'staff' => $slots[0]['staff'], 'participants' => ['Ben']]],
            'customer' => ['first_name' => 'Ben', 'email' => 'ben@example.com']])->assertStatus(201);
        $this->assertStringContainsString('Payment: paid.', Notifier::present($this->notes(Notifier::ORDER_BOOKED)[0])['message']);
        $this->assertStringContainsString(', paid by Cash.', end($this->mailer->sent)['body']);
    }

    public function testMoreSeatsOfAGroupAreToldToItsPeople(): void
    {
        $this->order([['key' => 'y', 'kind' => 'G', 'variant' => $this->yoga, 'quantity' => 2, 'session' => $this->yogaSession, 'participants' => ['Ann', 'Ben']]], $this->transfer);
        $notes = $this->notes(Notifier::ORDER_SEATS_BOOKED);
        $this->assertSame([$this->people['Anong'][2]], array_map('intval', array_column($notes, 'auth_id')));
        $shown = Notifier::present($notes[0]);
        $this->assertSame('2 more taken: Morning yoga', $shown['title']);
        $this->assertStringContainsString('4 of 10 are now taken', $shown['message']);
    }

    public function testTheCustomerSaysTheyPaidAndTheStoreChecksIt(): void
    {
        $order = $this->book($this->transfer);
        $ask   = ['business_slug' => 'otter-spa', 'number' => $order['order_number'], 'key' => $order['key']];

        $this->withBodyFormat('json')->post('v1/mart/order/report-payment', $ask)->assertStatus(422); // neither a reference nor a slip
        $this->withBodyFormat('json')->post('v1/mart/order/report-payment', ['key' => 'wrong', 'reference' => 'X'] + $ask)->assertStatus(404);

        $response = $this->withBodyFormat('json')->post('v1/mart/order/report-payment', ['reference' => 'KB-998877'] + $ask);
        $response->assertStatus(200);
        $this->assertNotNull($this->body($response)['data']['order']['payment']['reported_at']);
        $this->assertSame('P', $this->body($response)['data']['order']['status']);

        // the people of its store (Somsak, Anong, Nok) are told, to check it
        $notes = $this->notes(Notifier::ORDER_PAYMENT_REPORTED);
        $this->assertEqualsCanonicalizing(array_column($this->people, 2), array_map('intval', array_column($notes, 'auth_id')));
        $this->assertStringContainsString('Ann Lee says they paid THB 600.00 for order ' . $order['order_number'] . ' by Bank transfer (KB-998877)', Notifier::present($notes[0])['message']);

        // the business sees it reported, with its reference
        $payment = $this->body($this->send('v1/client/order', ['public_id' => $order['public_id']]))['data']['order']['payments'][0];
        $this->assertSame(['pending', 'KB-998877', false], [$payment['transaction_status'], $payment['reference'], $payment['has_slip']]);
        $this->assertNotNull($payment['reported_at']);

        // marked received: paid, and the notifications are read
        $this->send('v1/client/order/payment/settle', ['public_id' => $payment['public_id'], 'transaction_status' => 'succeeded'])->assertStatus(200);
        $this->assertSame(0, $this->db->table('notification_master')->where('event_type', Notifier::ORDER_PAYMENT_REPORTED)->where('read_at', null)->countAllResults());
        $this->withBodyFormat('json')->post('v1/mart/order/report-payment', ['reference' => 'again'] + $ask)->assertStatus(409);
    }

    public function testTheSlipIsShownToTheBusinessOnly(): void
    {
        $order   = $this->book($this->transfer);
        $folder  = rtrim(config(\Config\Mart::class)->orderSlipUploadPath, '/') . '/';
        @mkdir($folder, 0750, true);
        $payment = $this->db->table('order_payment')->get()->getRowArray();
        $name    = $payment['id'] . '-0123456789abcdef.png';
        file_put_contents($folder . $name, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
        $this->db->table('order_payment')->where('id', $payment['id'])->update(['slip_path' => $name, 'reported_at' => '2026-11-16 03:05:00']);

        try {
            $response = $this->withHeaders(['Authorization' => 'Bearer ' . $this->ownerToken])->get('v1/client/order/payment-slip/' . $payment['public_id']);
            $response->assertStatus(200);
            $this->assertStringStartsWith('image/png', $response->response()->getHeaderLine('Content-Type'));
            $this->assertTrue($this->body($this->send('v1/client/order', ['public_id' => $order['public_id']]))['data']['order']['payments'][0]['has_slip']);

            $other = $this->createClient('else@example.com', $this->business('another'), 'O');
            $this->withHeaders(['Authorization' => 'Bearer ' . $other])->get('v1/client/order/payment-slip/' . $payment['public_id'])->assertStatus(404);
        } finally {
            @unlink($folder . $name);
        }
    }

    // --- helpers ------------------------------------------------------------------------------------------------

    private function person(string $name): void
    {
        $email = strtolower($name) . '@example.com';
        $this->createClient($email, $this->businessId, 'E');
        $row = $this->db->table('client_master c')->select('c.id, a.public_id, a.id AS auth_id')->join('auth_master a', 'a.id = c.auth_id')->where('a.email_address', $email)->get()->getRow();
        $this->db->table('client_master')->where('id', $row->id)->update(['display_name' => $name]);
        $this->db->table('business_service_client_link')->insert(['business_id' => $this->businessId, 'artifact_id' => $this->serviceId, 'client_id' => $row->id]);
        $this->db->table('business_store_client_link')->insert(['business_id' => $this->businessId, 'store_id' => $this->storeId, 'client_id' => $row->id]);
        foreach (range(1, 7) as $day) {
            $this->db->table('business_client_hours')->insert(['business_id' => $this->businessId, 'client_id' => $row->id, 'store_id' => $this->storeId,
                'day_of_week' => $day, 'start_time' => '10:00:00', 'end_time' => '20:00:00']);
        }
        $this->people[$name] = [(int) $row->id, $row->public_id, (int) $row->auth_id];
    }

    /** Somsak, Wednesday 11:00, on the mart */
    private function book(string $payment): array
    {
        return $this->order([['key' => 'm', 'kind' => 'A', 'variant' => $this->massage, 'quantity' => 1, 'store' => $this->store,
            'starts_at' => '2026-11-18T11:00:00+07:00', 'staff' => [$this->people['Somsak'][1]], 'participants' => ['Ann']]], $payment);
    }

    private function order(array $lines, string $payment): array
    {
        $response = $this->withBodyFormat('json')->post('v1/mart/order', ['business_slug' => 'otter-spa', 'lang' => 'en-US', 'payment' => $payment, 'lines' => $lines,
            'customer' => ['first_name' => 'Ann', 'last_name' => 'Lee', 'email' => 'ann@example.com', 'telephone' => '081 234 5678']]);
        $response->assertStatus(201);

        return $this->body($response)['data']['order'];
    }

    private function notes(string $event): array
    {
        return $this->db->table('notification_master')->where('event_type', $event)->orderBy('id')->get()->getResultArray();
    }
}
