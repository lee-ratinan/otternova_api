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
 * An appointment for more than one customer: a Thai massage for a couple, one booking of two people, held by two
 * therapists in one room that takes two
 *
 * @internal
 */
final class CoupleAppointmentTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use ArtifactFixturesTrait;

    protected $namespace = 'App';

    private string $ownerToken;
    private int $businessId;
    private int $storeId;
    private string $storePublicId;
    private int $serviceId;
    private string $couple;
    private int $coupleId;
    private int $single;
    private int $suite;
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

        $this->businessId = $this->business('otter-spa');
        $this->ownerToken = $this->createClient('owner@example.com', $this->businessId, 'O');
        [$this->storeId, $this->storePublicId] = $this->store($this->businessId, 'Bangkok');
        $this->db->table('business_store')->where('id', $this->storeId)->update(['store_type' => 'P']);
        foreach (range(1, 7) as $day) {
            $this->db->table('business_store_hours')->insert(['store_id' => $this->storeId, 'day_of_week' => $day, 'open_time' => '10:00:00', 'close_time' => '14:00:00']);
        }

        // Thai massage for a couple: 90 minutes, 2 therapists, 2 customers, 2,400.00 for the two
        [$this->serviceId] = $this->artifactRow($this->businessId, 'S');
        $this->couple      = $this->fixturePublicId();
        $this->db->table('artifact_variant')->insert(['public_id' => $this->couple, 'business_id' => $this->businessId, 'artifact_id' => $this->serviceId,
            'price_active' => '2400.00', 'duration_minutes' => 90, 'booking_type' => 'A', 'staff_required' => 2, 'capacity' => 2]);
        $this->coupleId = (int) $this->db->insertID();

        // one massage room: room 101 takes one, the couple suite two; 101 is offered first
        $this->db->table('resource_type')->insert(['public_id' => $this->fixturePublicId(), 'business_id' => $this->businessId, 'type_name' => 'Massage room', 'buffer_minutes' => 15]);
        $typeId = (int) $this->db->insertID();
        foreach (['101' => [1, 0], 'Couple suite' => [2, 1]] as $name => [$capacity, $sort]) {
            $this->db->table('resource_master')->insert(['public_id' => $this->fixturePublicId(), 'business_id' => $this->businessId, 'store_id' => $this->storeId,
                'resource_type_id' => $typeId, 'resource_name' => $name, 'capacity' => $capacity, 'sort_order' => $sort]);
            $capacity === 1 ? $this->single = (int) $this->db->insertID() : $this->suite = (int) $this->db->insertID();
        }
        $this->db->table('business_service_resource_link')->insert(['business_id' => $this->businessId, 'artifact_id' => $this->serviceId, 'resource_type_id' => $typeId, 'quantity' => 1]);

        foreach (['somsak' => 'Somsak', 'anong' => 'Anong'] as $user => $name) {
            $this->therapist($user . '@example.com', $name);
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

    public function testACoupleIsBookedInOneOrderWithTwoTherapistsAndTheRoomForTwo(): void
    {
        $slot = $this->slots('2026-11-17')[0];
        $this->assertCount(2, $slot['staff']);

        $response = $this->order($slot, ['Ann Lee', 'Ben Lee']);
        $response->assertStatus(201);
        $order = $this->body($response)['data']['order'];
        $line  = $order['lines'][0];
        $this->assertSame(['A', 1, ['Ann Lee', 'Ben Lee'], '2400.00'], [$line['kind'], $line['quantity'], $line['participants'], $order['totals']['total']]);

        // one booking holding both therapists and the room that takes two, not room 101
        $session = $this->db->table('service_session')->where('session_status', 'C')->get()->getRowArray();
        $this->assertSame([2, 2], [(int) $session['capacity'], (int) $session['seats_booked']]);
        $slotId = (int) $this->db->table('service_session_slot')->where('session_id', $session['id'])->get()->getRow()->id;
        $this->assertSame(2, $this->db->table('service_slot_staff')->where('slot_id', $slotId)->countAllResults());
        $this->assertSame([$this->suite], array_map('intval', array_column($this->db->table('service_slot_resource')->where('slot_id', $slotId)->get()->getResultArray(), 'resource_id')));

        // that time is gone: both therapists and the only room for two are held
        $this->assertNotContains($slot['time'], array_column($this->slots('2026-11-17'), 'time'));
    }

    public function testEveryCustomerOfTheBookingIsNamed(): void
    {
        $slot     = $this->slots('2026-11-17')[0];
        $response = $this->order($slot, ['Ann Lee']);
        $response->assertStatus(409);
        $this->assertSame(['lines.c.participants' => 'required'], $this->body($response)['errors']);
    }

    public function testWithoutAFreeRoomForTwoNoTimeIsOffered(): void
    {
        $this->db->table('resource_master')->where('id', $this->suite)->update(['resource_status' => 'I']);
        $this->assertSame([], $this->slots('2026-11-17'));
    }

    public function testTwoRoomsForOneEachCanHoldTheCoupleToo(): void
    {
        // the business books its couples into two rooms side by side instead
        $this->db->table('resource_master')->where('id', $this->suite)->update(['resource_status' => 'I']);
        $this->db->table('resource_master')->insert(['public_id' => $this->fixturePublicId(), 'business_id' => $this->businessId, 'store_id' => $this->storeId,
            'resource_type_id' => (int) $this->db->table('resource_type')->get()->getRow()->id, 'resource_name' => '102', 'capacity' => 1, 'sort_order' => 0]);
        $this->db->table('business_service_resource_link')->update(['quantity' => 2]);

        $this->order($this->slots('2026-11-17')[0], ['Ann Lee', 'Ben Lee'])->assertStatus(201);
        $this->assertSame(2, $this->db->table('service_slot_resource')->countAllResults());
    }

    // --- helpers ------------------------------------------------------------------------------------------------

    private function therapist(string $email, string $name): void
    {
        $this->createClient($email, $this->businessId, 'E');
        $id = (int) $this->db->table('client_master c')->select('c.id')->join('auth_master a', 'a.id = c.auth_id')->where('a.email_address', $email)->get()->getRow()->id;
        $this->db->table('client_master')->where('id', $id)->update(['display_name' => $name]);
        $this->db->table('business_service_client_link')->insert(['business_id' => $this->businessId, 'artifact_id' => $this->serviceId, 'client_id' => $id]);
        $this->db->table('business_store_client_link')->insert(['business_id' => $this->businessId, 'store_id' => $this->storeId, 'client_id' => $id]);
        foreach (range(1, 7) as $day) {
            $this->db->table('business_client_hours')->insert(['business_id' => $this->businessId, 'client_id' => $id, 'store_id' => $this->storeId,
                'day_of_week' => $day, 'start_time' => '09:00:00', 'end_time' => '14:00:00']);
        }
    }

    private function slots(string $date): array
    {
        $response = $this->get('v1/mart/slots?business_slug=otter-spa&variant=' . $this->couple . '&date=' . $date);
        $response->assertStatus(200);

        return $this->body($response)['data']['slots'];
    }

    private function order(array $slot, array $people): TestResponse
    {
        return $this->withBodyFormat('json')->post('v1/mart/order', ['business_slug' => 'otter-spa', 'lang' => 'en-US', 'payment' => $this->cash,
            'lines'    => [['key' => 'c', 'kind' => 'A', 'variant' => $this->couple, 'quantity' => 1, 'store' => $this->storePublicId,
                'starts_at' => $slot['starts_at'], 'staff' => $slot['staff'], 'participants' => $people]],
            'customer' => ['first_name' => 'Ann', 'last_name' => 'Lee', 'email' => 'ann@example.com', 'telephone' => '081 234 5678'],
        ]);
    }
}
