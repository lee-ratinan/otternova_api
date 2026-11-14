<?php

use App\Libraries\EmailService;
use CodeIgniter\I18n\Time;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\Mock\MockCache;
use CodeIgniter\Test\TestResponse;
use Config\Services;
use Tests\Support\ArtifactFixturesTrait;

/**
 * /client/order/reschedule/slots and /client/order/reschedule: anyone working in a business moves a private
 * appointment to another time, or to another person, until the cancellation deadline before it; the customer is
 * emailed the new time
 *
 * @internal
 */
final class RescheduleTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use ArtifactFixturesTrait;

    protected $namespace = 'App';

    private string $ownerToken;
    private string $employeeToken;
    private int $businessId;
    private int $storeId;
    private string $store;
    private string $massage;
    private int $variantId;
    private int $serviceId;
    private int $roomId;
    /** @var array<string, array{int, string}> name => [client_id, public_id] */
    private array $people = [];
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

        $this->businessId    = $this->business('otter-spa');
        $this->ownerToken    = $this->createClient('owner@example.com', $this->businessId, 'O');
        $this->employeeToken = $this->createClient('counter@example.com', $this->businessId, 'E');
        [$this->storeId, $this->store] = $this->store($this->businessId, 'Silom');
        $this->db->table('business_store')->where('id', $this->storeId)->update(['store_type' => 'P']);
        foreach (range(1, 7) as $day) {
            $this->db->table('business_store_hours')->insert(['store_id' => $this->storeId, 'day_of_week' => $day, 'open_time' => '10:00:00', 'close_time' => '20:00:00']);
        }

        // a 60-minute massage in the one massage room, cleaned for 15 minutes after
        [$this->serviceId] = $this->artifactRow($this->businessId, 'S');
        $this->db->table('artifact_master')->where('id', $this->serviceId)->update(['artifact_name' => 'Thai massage']);
        $this->massage = $this->fixturePublicId();
        $this->db->table('artifact_variant')->insert(['public_id' => $this->massage, 'business_id' => $this->businessId, 'artifact_id' => $this->serviceId,
            'price_active' => '600.00', 'duration_minutes' => 60, 'booking_type' => 'A', 'staff_required' => 1]);
        $this->variantId = (int) $this->db->insertID();
        $this->db->table('resource_type')->insert(['public_id' => $this->fixturePublicId(), 'business_id' => $this->businessId, 'type_name' => 'Room', 'buffer_minutes' => 15]);
        $typeId = (int) $this->db->insertID();
        $this->db->table('resource_master')->insert(['public_id' => $this->fixturePublicId(), 'business_id' => $this->businessId, 'store_id' => $this->storeId,
            'resource_type_id' => $typeId, 'resource_name' => '101']);
        $this->roomId = (int) $this->db->insertID();
        $this->db->table('business_service_resource_link')->insert(['business_id' => $this->businessId, 'artifact_id' => $this->serviceId, 'resource_type_id' => $typeId]);

        foreach (['Somsak', 'Anong'] as $name) {
            $this->therapist($name);
        }
        $this->cash = $this->fixturePublicId();
        $this->db->table('business_payment_method')->insert(['public_id' => $this->cash, 'business_id' => $this->businessId, 'method_code' => 'cash',
            'handler_code' => 'instruction', 'method_name' => 'Cash']);
    }

    protected function tearDown(): void
    {
        Time::setTestNow();
        parent::tearDown();
    }

    public function testABookingMovesToAnotherTimeAndTheCustomerIsTold(): void
    {
        $publicId = $this->book('2026-11-18T11:00:00+07:00', 'Somsak');

        // until 24 hours before it, by the business's policy
        $line = $this->show($publicId)['lines'][0];
        $this->assertSame([['Somsak'], true, '2026-11-17 04:00:00'], [array_column($line['staff'], 'name'), $line['reschedule']['allowed'], $line['reschedule']['until']]);

        // its own time and the room's cleaning do not stand in its way: 11:30 with Somsak is offered
        $slots = $this->slots($publicId, '2026-11-18');
        $this->assertContains(['11:30', [$this->people['Somsak'][1]]], array_map(static fn (array $s): array => [$s['time'], $s['staff']], $slots));

        $order = $this->move($publicId, '2026-11-18T14:00:00+07:00', 'Somsak', $this->employeeToken);
        $this->assertSame('2026-11-18 07:00:00', $order['lines'][0]['starts_at']);
        $this->assertSame('2026-11-18 08:00:00', $order['lines'][0]['ends_at']);
        $slot = $this->db->table('service_session_slot')->get()->getRowArray(); // the booking's only one, moved in place
        $this->assertSame(['2026-11-18 07:00:00', '2026-11-18 08:00:00'], [$slot['starts_at'], $slot['ends_at']]);
        $this->assertSame('2026-11-18 08:15:00', $this->db->table('service_slot_resource')->where('slot_id', $slot['id'])->get()->getRow()->held_until);
        $this->assertSame('2026-11-18 07:00:00', $this->db->table('service_slot_staff')->where('slot_id', $slot['id'])->get()->getRow()->starts_at);

        // 11:00 is free again for anyone
        $this->assertNotEmpty(array_filter($this->slots($publicId, '2026-11-18'), static fn (array $s): bool => $s['time'] === '11:00'));
        $this->assertCount(1, $this->mailer->sent);
        $this->assertStringContainsString('from 2026-11-18 11:00 to 2026-11-18 14:00', $this->mailer->sent[0]['body']);
    }

    public function testWhenTheTherapistCannotComeAnotherTakesTheSameTime(): void
    {
        $publicId = $this->book('2026-11-18T11:00:00+07:00', 'Somsak');
        $order    = $this->move($publicId, '2026-11-18T11:00:00+07:00', 'Anong');
        $this->assertSame(['Anong'], array_column($order['lines'][0]['staff'], 'name'));
        $this->assertSame(1, $this->db->table('service_slot_staff')->countAllResults());
    }

    public function testATakenTimeIsRefusedAndNothingChanges(): void
    {
        $publicId = $this->book('2026-11-18T11:00:00+07:00', 'Somsak');
        $this->book('2026-11-18T15:00:00+07:00', 'Anong', 'ben@example.com');

        // the room is held 15:00-16:15 by the other booking
        $response = $this->send('v1/client/order/reschedule', ['public_id' => $publicId, 'line_number' => 1, 'starts_at' => '2026-11-18T15:00:00+07:00',
            'staff' => [$this->people['Somsak'][1]]]);
        $response->assertStatus(409);
        $this->assertSame(lang('SalesOrder.cannotReschedule.taken'), $this->body($response)['message']);
        $this->assertSame('2026-11-18 04:00:00', $this->show($publicId)['lines'][0]['starts_at']);
        $this->assertSame([], $this->mailer->sent);
    }

    public function testNotPastTheCancellationDeadline(): void
    {
        $publicId = $this->book('2026-11-18T11:00:00+07:00', 'Somsak');
        // Tuesday 11:30 in Bangkok: within 24 hours of it
        Time::setTestNow('2026-11-17 04:30:00', 'UTC');
        $line = $this->show($publicId)['lines'][0];
        $this->assertSame([false, 'deadline'], [$line['reschedule']['allowed'], $line['reschedule']['blocker']]);
        $response = $this->send('v1/client/order/reschedule/slots', ['public_id' => $publicId, 'line_number' => 1, 'date' => '2026-11-19']);
        $response->assertStatus(409);
        $this->assertSame(lang('SalesOrder.cannotReschedule.deadline'), $this->body($response)['message']);

        // a cancelled order moves no more either
        Time::setTestNow('2026-11-16 03:00:00', 'UTC');
        $this->send('v1/client/order/cancel', ['public_id' => $publicId, 'reason' => 'Customer called'])->assertStatus(200);
        $this->assertSame('ended', $this->show($publicId)['lines'][0]['reschedule']['blocker']);
    }

    // --- helpers ------------------------------------------------------------------------------------------------

    private function therapist(string $name): void
    {
        $email = strtolower($name) . '@example.com';
        $this->createClient($email, $this->businessId, 'E');
        $row = $this->db->table('client_master c')->select('c.id, a.public_id')->join('auth_master a', 'a.id = c.auth_id')->where('a.email_address', $email)->get()->getRow();
        $this->db->table('client_master')->where('id', $row->id)->update(['display_name' => $name]);
        $this->db->table('business_service_client_link')->insert(['business_id' => $this->businessId, 'artifact_id' => $this->serviceId, 'client_id' => $row->id]);
        $this->db->table('business_store_client_link')->insert(['business_id' => $this->businessId, 'store_id' => $this->storeId, 'client_id' => $row->id]);
        foreach (range(1, 7) as $day) {
            $this->db->table('business_client_hours')->insert(['business_id' => $this->businessId, 'client_id' => $row->id, 'store_id' => $this->storeId,
                'day_of_week' => $day, 'start_time' => '10:00:00', 'end_time' => '20:00:00']);
        }
        $this->people[$name] = [(int) $row->id, $row->public_id];
    }

    /** A booking placed on the mart, by its customer */
    private function book(string $startsAt, string $with, string $email = 'ann@example.com'): string
    {
        $response = $this->withBodyFormat('json')->post('v1/mart/order', ['business_slug' => 'otter-spa', 'lang' => 'en-US', 'payment' => $this->cash,
            'lines'    => [['key' => 'm', 'kind' => 'A', 'variant' => $this->massage, 'quantity' => 1, 'store' => $this->store, 'starts_at' => $startsAt,
                'staff' => [$this->people[$with][1]], 'participants' => ['Ann']]],
            'customer' => ['first_name' => 'Ann', 'last_name' => 'Lee', 'email' => $email, 'telephone' => '081 234 5678'],
        ]);
        $response->assertStatus(201);
        $this->mailer->sent = []; // its confirmation: OrderNoticesTest's

        return $this->body($response)['data']['order']['public_id'];
    }

    private function show(string $publicId): array
    {
        return $this->ok($this->send('v1/client/order', ['public_id' => $publicId]))['order'];
    }

    private function slots(string $publicId, string $date): array
    {
        return $this->ok($this->send('v1/client/order/reschedule/slots', ['public_id' => $publicId, 'line_number' => 1, 'date' => $date], $this->employeeToken))['slots'];
    }

    private function move(string $publicId, string $startsAt, string $with, ?string $token = null): array
    {
        return $this->ok($this->send('v1/client/order/reschedule', ['public_id' => $publicId, 'line_number' => 1, 'starts_at' => $startsAt,
            'staff' => [$this->people[$with][1]]], $token))['order'];
    }

    private function ok(TestResponse $response): array
    {
        $response->assertStatus(200);

        return $this->body($response)['data'];
    }
}
