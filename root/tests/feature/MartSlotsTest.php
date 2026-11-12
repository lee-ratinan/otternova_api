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
 * /mart/slots: the times a customer may book an appointment at, within the booking window
 *
 * @internal
 */
final class MartSlotsTest extends CIUnitTestCase
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
    private string $variant;
    private int $variantId;
    private int $therapist;

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

        // a 60-minute massage, booked by time
        [$this->serviceId] = $this->artifactRow($this->businessId, 'S');
        $this->variant     = $this->fixturePublicId();
        $this->db->table('artifact_variant')->insert(['public_id' => $this->variant, 'business_id' => $this->businessId, 'artifact_id' => $this->serviceId,
            'price_active' => '800.00', 'duration_minutes' => 60, 'booking_type' => 'A', 'staff_required' => 1]);
        $this->variantId = (int) $this->db->insertID();

        // Somsak works 09:00-14:00 every day at the store
        $this->createClient('somsak@example.com', $this->businessId, 'E');
        $this->therapist = (int) $this->db->table('client_master c')->select('c.id')->join('auth_master a', 'a.id = c.auth_id')
            ->where('a.uid', 'somsakexamplecom')->get()->getRow()->id;
        $this->db->table('client_master')->where('id', $this->therapist)->update(['display_name' => 'Somsak']);
        $this->db->table('business_service_client_link')->insert(['business_id' => $this->businessId, 'artifact_id' => $this->serviceId, 'client_id' => $this->therapist]);
        $this->db->table('business_store_client_link')->insert(['business_id' => $this->businessId, 'store_id' => $this->storeId, 'client_id' => $this->therapist]);
        foreach (range(1, 7) as $day) {
            $this->db->table('business_client_hours')->insert(['business_id' => $this->businessId, 'client_id' => $this->therapist, 'store_id' => $this->storeId,
                'day_of_week' => $day, 'start_time' => '09:00:00', 'end_time' => '14:00:00']);
        }
    }

    protected function tearDown(): void
    {
        Time::setTestNow();
        parent::tearDown();
    }

    public function testOnlyAnAppointmentVariantIsSearched(): void
    {
        $this->get('v1/mart/slots?business_slug=otter-spa&variant=nope')->assertStatus(404);
        $this->db->table('artifact_variant')->where('id', $this->variantId)->update(['booking_type' => 'G', 'capacity' => 10]);
        $this->get('v1/mart/slots?business_slug=otter-spa&variant=' . $this->variant)->assertStatus(404);
        $this->db->table('artifact_variant')->where('id', $this->variantId)->update(['booking_type' => 'A', 'capacity' => null, 'variant_status' => 'I']);
        $this->get('v1/mart/slots?business_slug=otter-spa&variant=' . $this->variant)->assertStatus(404);
    }

    public function testTheDatesFollowTheBookingWindow(): void
    {
        // the business's rule: 3 days ahead, an hour's notice
        $data = $this->slots();
        $this->assertSame(['first_date' => '2026-11-16', 'last_date' => '2026-11-19'], $data['window']);
        $this->assertSame('2026-11-16', $data['date']);
        $this->assertSame(['horizon_days' => 3, 'notice_unit' => 'M', 'notice_value' => 60, 'notice_time' => null], $data['rule']);
        // from 11:00, an hour from now, to 13:00, the last that ends by closing
        $this->assertSame(['11:00', '11:30', '12:00', '12:30', '13:00'], array_column($data['slots'], 'time'));
        $this->assertSame('2026-11-16T11:00:00+07:00', $data['slots'][0]['starts_at']);
        $this->assertSame('2026-11-16T12:00:00+07:00', $data['slots'][0]['ends_at']);

        $this->assertSame(['10:00', '10:30', '11:00', '11:30', '12:00', '12:30', '13:00'], array_column($this->slots('&date=2026-11-19')['slots'], 'time'));
        $this->assertSame('Bookings are taken from 2026-11-16 to 2026-11-19.', $this->errors('&date=2026-11-20')['date']);
        $this->assertArrayHasKey('date', $this->errors('&date=2026-11-31'));

        // the variant's own: a day ahead, booked by 6pm the day before
        $this->db->table('artifact_variant')->where('id', $this->variantId)->update(['booking_horizon_days' => 2,
            'booking_notice_unit' => 'D', 'booking_notice_value' => 1, 'booking_notice_time' => '18:00:00']);
        $this->assertSame(['first_date' => '2026-11-17', 'last_date' => '2026-11-18'], $this->slots()['window']);
        $this->assertArrayHasKey('date', $this->errors('&date=2026-11-16'));
    }

    public function testHeldPeopleAndResourcesAreNotOffered(): void
    {
        // a treatment room, cleaned for 30 minutes after each use
        $this->db->table('resource_type')->insert(['public_id' => $this->fixturePublicId(), 'business_id' => $this->businessId, 'type_name' => 'Room', 'buffer_minutes' => 30]);
        $typeId = (int) $this->db->insertID();
        $this->db->table('resource_master')->insert(['public_id' => $this->fixturePublicId(), 'business_id' => $this->businessId, 'store_id' => $this->storeId,
            'resource_type_id' => $typeId, 'resource_name' => '101']);
        $roomId = (int) $this->db->insertID();
        $this->db->table('business_service_resource_link')->insert(['business_id' => $this->businessId, 'artifact_id' => $this->serviceId, 'resource_type_id' => $typeId]);
        $all = ['10:00', '10:30', '11:00', '11:30', '12:00', '12:30', '13:00'];
        $this->assertSame($all, array_column($this->slots('&date=2026-11-17')['slots'], 'time'));

        // Somsak is booked 11:00-12:00 on Tuesday
        $slotId = $this->heldSlot('2026-11-17 04:00:00', '2026-11-17 05:00:00');
        $this->db->table('service_slot_staff')->insert(['business_id' => $this->businessId, 'slot_id' => $slotId, 'client_id' => $this->therapist,
            'starts_at' => '2026-11-17 04:00:00', 'ends_at' => '2026-11-17 05:00:00']);
        $this->assertSame(['10:00', '12:00', '12:30', '13:00'], array_column($this->slots('&date=2026-11-17')['slots'], 'time'));

        // and the only room 12:00-13:00, so until 13:30 with its cleaning
        $slotId = $this->heldSlot('2026-11-17 05:00:00', '2026-11-17 06:00:00');
        $this->db->table('service_slot_resource')->insert(['business_id' => $this->businessId, 'slot_id' => $slotId, 'resource_id' => $roomId,
            'starts_at' => '2026-11-17 05:00:00', 'held_until' => '2026-11-17 06:30:00']);
        $this->assertSame(['10:00'], array_column($this->slots('&date=2026-11-17')['slots'], 'time'));

        // with the room out of service, the store can hold the service no more
        $this->db->table('resource_master')->where('id', $roomId)->update(['resource_status' => 'I']);
        $this->assertSame([], $this->slots('&date=2026-11-18')['slots']);
    }

    public function testPeopleGoOutByPublicIdOnlyAndCanBeChosen(): void
    {
        $data = $this->slots('&date=2026-11-17');
        $this->assertCount(1, $data['staff']);
        $ref = $data['staff'][0]['public_id'];
        $this->assertSame($this->db->table('auth_master')->select('public_id')->where('uid', 'somsakexamplecom')->get()->getRow()->public_id, $ref);
        $this->assertSame('Somsak', $data['staff'][0]['name']);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{12}$/', $ref);
        $this->assertSame([$ref], $data['slots'][0]['staff']);
        $this->assertStringNotContainsString('somsak@', json_encode($data));
        $this->assertStringNotContainsString('somsakexamplecom', json_encode($data));

        $this->assertCount(7, $this->slots('&date=2026-11-17&staff=' . $ref . '&store=' . $this->storePublicId)['slots']);
        $this->assertArrayHasKey('staff', $this->errors('&staff=nobody12345'));
        $this->assertArrayHasKey('store', $this->errors('&store=nostore12345'));

        // a day off on Tuesday: nobody to perform it then
        $this->db->table('business_client_hours_exceptions')->insert(['business_id' => $this->businessId, 'client_id' => $this->therapist,
            'exception_date' => '2026-11-17', 'exception_type' => 'V']);
        $this->assertSame([], $this->slots('&date=2026-11-17')['slots']);
    }

    public function testTheFirstDayWithAFreeTimeIsChosen(): void
    {
        // Monday 13:30 in Bangkok: with an hour's notice, nothing ends by the 14:00 closing today
        Time::setTestNow('2026-11-16 06:30:00', 'UTC');
        $data = $this->slots();
        $this->assertSame('2026-11-16', $data['window']['first_date']);
        $this->assertSame('2026-11-17', $data['date']);
        $this->assertCount(7, $data['slots']);
        // asked for, today is answered even when empty
        $this->assertSame([], $this->slots('&date=2026-11-16')['slots']);
    }

    public function testEachTimeComesWithOnePersonAndTheirRestrictions(): void
    {
        $somsak = $this->db->table('auth_master')->select('public_id')->where('uid', 'somsakexamplecom')->get()->getRow()->public_id;
        $anong  = $this->person('anong@example.com', 'Anong', $this->storeId);
        $this->db->table('business_client_restriction')->insert(['business_id' => $this->businessId, 'client_id' => $this->therapist, 'restriction_type' => 'G', 'excluded_gender' => 'M']);
        $this->db->table('business_client_restriction')->insert(['business_id' => $this->businessId, 'client_id' => $this->therapist, 'restriction_type' => 'A', 'age_min' => 18]);

        $data = $this->slots('&date=2026-11-17');
        $this->assertSame([
            ['public_id' => $anong, 'name' => 'Anong', 'restrictions' => null],
            ['public_id' => $somsak, 'name' => 'Somsak', 'restrictions' => ['excluded_genders' => ['M'], 'age_min' => 18, 'age_max' => null]],
        ], $data['staff']);
        // every start once for each of them, by name
        $this->assertCount(14, $data['slots']);
        $this->assertSame(['10:00', [$anong]], [$data['slots'][0]['time'], $data['slots'][0]['staff']]);
        $this->assertSame(['10:00', [$somsak]], [$data['slots'][1]['time'], $data['slots'][1]['staff']]);
        // or only the person asked for
        $this->assertSame([[$somsak]], array_values(array_unique(array_column($this->slots('&date=2026-11-17&staff=' . $somsak)['slots'], 'staff'), SORT_REGULAR)));

        // a service needing two: each start once, with both
        $this->db->table('artifact_variant')->where('id', $this->variantId)->update(['staff_required' => 2]);
        $slots = $this->slots('&date=2026-11-17')['slots'];
        $this->assertCount(7, $slots);
        $this->assertSame([$anong, $somsak], $slots[0]['staff']);
    }

    public function testOneStoreIsAnsweredTheFirstByNameUnlessAsked(): void
    {
        [$asokId, $asok] = $this->store($this->businessId, 'Asok');
        $this->db->table('business_store')->where('id', $asokId)->update(['store_type' => 'P', 'store_address_line_1' => '1 Sukhumvit Rd', 'store_city' => 'Bangkok']);
        foreach (range(1, 7) as $day) {
            $this->db->table('business_store_hours')->insert(['store_id' => $asokId, 'day_of_week' => $day, 'open_time' => '18:00:00', 'close_time' => '20:00:00']);
        }
        $anong = $this->person('anong@example.com', 'Anong', $asokId, '18:00:00', '20:00:00');

        $data = $this->slots('&date=2026-11-17');
        $this->assertSame($asok, $data['store']);
        $this->assertSame(['Asok', 'Bangkok'], array_column($data['stores'], 'name'));
        $this->assertSame('1 Sukhumvit Rd', $data['stores'][0]['address']['line_1']);
        $this->assertSame([$anong], array_column($data['staff'], 'public_id'));
        $this->assertSame(['18:00', '18:30', '19:00'], array_column($data['slots'], 'time'));

        $data = $this->slots('&date=2026-11-17&store=' . $this->storePublicId);
        $this->assertSame($this->storePublicId, $data['store']);
        $this->assertSame(['Somsak'], array_column($data['staff'], 'name'));
        $this->assertCount(7, $data['slots']);
        // a person of another store is not one of this one's
        $this->assertArrayHasKey('staff', $this->errors('&store=' . $this->storePublicId . '&staff=' . $anong));
    }

    /**
     * Another person who performs the service, every day at a store
     * @return string Their public_id
     */
    private function person(string $email, string $name, int $storeId, string $start = '09:00:00', string $end = '14:00:00'): string
    {
        $this->createClient($email, $this->businessId, 'E');
        $row = $this->db->table('client_master c')->select('c.id, a.public_id')->join('auth_master a', 'a.id = c.auth_id')
            ->where('a.email_address', $email)->get()->getRow();
        $this->db->table('client_master')->where('id', $row->id)->update(['display_name' => $name]);
        $this->db->table('business_service_client_link')->insert(['business_id' => $this->businessId, 'artifact_id' => $this->serviceId, 'client_id' => $row->id]);
        $this->db->table('business_store_client_link')->insert(['business_id' => $this->businessId, 'store_id' => $storeId, 'client_id' => $row->id]);
        foreach (range(1, 7) as $day) {
            $this->db->table('business_client_hours')->insert(['business_id' => $this->businessId, 'client_id' => $row->id, 'store_id' => $storeId,
                'day_of_week' => $day, 'start_time' => $start, 'end_time' => $end]);
        }

        return $row->public_id;
    }

    /** @return array The answer's data */
    private function slots(string $query = ''): array
    {
        $response = $this->get('v1/mart/slots?business_slug=otter-spa&variant=' . $this->variant . $query);
        $response->assertStatus(200);

        return $this->data($response);
    }

    /** @return array The 422 answer's errors */
    private function errors(string $query): array
    {
        $response = $this->get('v1/mart/slots?business_slug=otter-spa&variant=' . $this->variant . $query);
        $response->assertStatus(422);

        return $this->body($response)['errors'];
    }

    private function data(TestResponse $response): array
    {
        return $this->body($response)['data'];
    }

    /** A session slot of the business at the store, for holds to hang on; times in UTC */
    private function heldSlot(string $startsAt, string $endsAt): int
    {
        $this->db->table('service_session')->insert(['public_id' => $this->fixturePublicId(), 'business_id' => $this->businessId, 'store_id' => $this->storeId,
            'artifact_id' => $this->serviceId, 'variant_id' => $this->variantId, 'session_name' => 'Booking', 'capacity' => 1, 'session_status' => 'O',
            'starts_at' => $startsAt, 'ends_at' => $endsAt]);
        $sessionId = (int) $this->db->insertID();
        $this->db->table('service_session_slot')->insert(['business_id' => $this->businessId, 'session_id' => $sessionId, 'slot_number' => 1,
            'starts_at' => $startsAt, 'ends_at' => $endsAt]);

        return (int) $this->db->insertID();
    }
}
