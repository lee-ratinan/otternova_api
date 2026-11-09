<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\ArtifactFixturesTrait;

/**
 * The sessions of a business's group services: POST /client/sessions, /client/session, /client/session/options,
 * /client/session/create and /edit
 *
 * @internal
 */
final class ServiceSessionsTest extends CIUnitTestCase
{
    use ArtifactFixturesTrait;
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'App'; // the app's own migrations, the schema of the real database

    private int $businessId;
    private string $ownerToken;
    private int $storeId;
    private string $storePublicId;
    private int $serviceId;
    private string $groupVariant;
    private string $studio;
    private string $teacher = 'somsakexamplecom';

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['jwt', 'authContext'] as $service) {
            Services::resetSingle($service);
        }
        $this->businessId = $this->business('otter-school');
        $this->ownerToken = $this->createClient('owner@example.com', $this->businessId, 'O');
        [$this->storeId, $this->storePublicId] = $this->store($this->businessId, 'Bangkok');
        $this->db->table('business_store')->where('id', $this->storeId)->update(['store_type' => 'P']);

        // Muay Thai for tourists: a group of up to 12 with one instructor, in a studio that fits 20 and is cleaned for 30 minutes after
        [$this->serviceId] = $this->artifactRow($this->businessId, 'S');
        $this->groupVariant = $this->variant($this->serviceId, 'G', 12);
        $this->db->table('resource_type')->insert(['public_id' => $this->fixturePublicId(), 'business_id' => $this->businessId, 'type_name' => 'Studio', 'buffer_minutes' => 30]);
        $typeId = (int) $this->db->insertID();
        $this->studio = $this->fixturePublicId();
        $this->db->table('resource_master')->insert(['public_id' => $this->studio, 'business_id' => $this->businessId, 'store_id' => $this->storeId,
            'resource_type_id' => $typeId, 'resource_name' => 'Main studio', 'capacity' => 20]);
        $this->db->table('business_service_resource_link')->insert(['business_id' => $this->businessId, 'artifact_id' => $this->serviceId, 'resource_type_id' => $typeId]);
        $this->createClient('somsak@example.com', $this->businessId, 'E');
        $this->canTeach($this->teacher);
    }

    public function testAGroupSessionIsCreatedWithItsSlotsAndWhatTheyHold(): void
    {
        $options = $this->body($this->send('v1/client/session/options', ['business_slug' => 'otter-school']))['data'];
        $this->assertSame([$this->groupVariant], array_column($options['variants'], 'public_id'));
        $this->assertSame(12, $options['variants'][0]['capacity']);
        $this->assertSame([$this->storePublicId], array_column($options['stores'], 'public_id'));
        $this->assertArrayNotHasKey('staff', $options);
        $options = $this->body($this->send('v1/client/session/options', ['business_slug' => 'otter-school', 'variant_public_id' => $this->groupVariant,
            'store_public_id' => $this->storePublicId]))['data'];
        $this->assertSame([['uid' => $this->teacher, 'full_name' => 'Otter Tester']], $options['staff']);
        $this->assertSame([$this->studio], array_column($options['resources'], 'public_id'));
        $this->assertSame([1], array_column($options['needs'], 'quantity'));

        $response = $this->send('v1/client/session/create', [
            'business_slug' => 'otter-school',
            'session'       => ['variant_public_id' => $this->groupVariant, 'store_public_id' => $this->storePublicId,
                'session_name' => 'Japanese Basic Course 1', 'session_status' => 'O', 'booking_closes_at' => '2026-11-09T18:00'],
            'translations'  => ['th' => ['session_name' => 'คอร์สญี่ปุ่นพื้นฐาน 1']],
            'slots'         => [ // given out of order: numbered in the order they meet
                ['starts_at' => '2026-11-17T11:00', 'ends_at' => '2026-11-17T13:00', 'staff' => [$this->teacher], 'resources' => [$this->studio]],
                ['starts_at' => '2026-11-10T11:00', 'ends_at' => '2026-11-10T13:00', 'staff' => [$this->teacher], 'resources' => [$this->studio]],
            ],
        ]);
        $response->assertStatus(200);
        $data    = $this->body($response)['data'];
        $session = $data['session'];
        $this->assertSame(12, $session['capacity']); // the variant's
        $this->assertSame(['2026-11-10T04:00:00Z', '2026-11-10T11:00', '2026-11-17T06:00:00Z', '2026-11-09T11:00:00Z'],
            [$session['starts_at'], $session['local_starts_at'], $session['ends_at'], $session['booking_closes_at']]);
        $this->assertSame(['th' => ['session_name' => 'คอร์สญี่ปุ่นพื้นฐาน 1', 'session_description' => null]], $session['translations']);
        $this->assertSame([1, 2], array_column($data['slots'], 'slot_number'));
        $this->assertSame('2026-11-10T13:00', $data['slots'][0]['local_ends_at']);
        $this->assertSame([['uid' => $this->teacher, 'full_name' => 'Otter Tester']], $data['slots'][0]['staff']);
        $this->assertSame('Main studio', $data['slots'][0]['resources'][0]['resource_name']);
        // the studio is held until it has been cleaned
        $this->seeInDatabase('service_slot_resource', ['starts_at' => '2026-11-10 04:00:00', 'held_until' => '2026-11-10 06:30:00']);
        $this->seeNumRecords(2, 'service_slot_staff', []);

        $this->assertSame($data, $this->body($this->send('v1/client/session', ['public_id' => $session['public_id']]))['data']);
        $list = $this->body($this->send('v1/client/sessions', ['business_slug' => 'otter-school', 'period' => 'all']))['data']['sessions'];
        $this->assertSame([[$session['public_id'], 2, 'O']], array_map(static fn (array $s) => [$s['public_id'], $s['slot_count'], $s['session_status']], $list));
        $this->seeInDatabase('log_activity', ['table_involved' => 'service_slot_staff']);
    }

    public function testEditingMovesCancelsAndAddsSlots(): void
    {
        $publicId = $this->course([['2026-11-10T11:00', '2026-11-10T13:00'], ['2026-11-17T11:00', '2026-11-17T13:00']]);

        // slot 1 moves an hour later; slot 2 is left out, so cancelled; a new one is added as slot 3
        $response = $this->send('v1/client/session/edit', ['public_id' => $publicId, 'session' => ['capacity' => 10], 'slots' => [
            ['slot_number' => 1, 'starts_at' => '2026-11-10T12:00', 'ends_at' => '2026-11-10T14:00', 'staff' => [$this->teacher], 'resources' => [$this->studio]],
            ['starts_at' => '2026-11-24T11:00', 'ends_at' => '2026-11-24T13:00', 'staff' => [$this->teacher], 'resources' => [$this->studio]],
        ]]);
        $response->assertStatus(200);
        $data = $this->body($response)['data'];
        $this->assertSame(10, $data['session']['capacity']);
        $this->assertSame([[1, 'S', '2026-11-10T12:00'], [2, 'X', '2026-11-17T11:00'], [3, 'S', '2026-11-24T11:00']],
            array_map(static fn (array $s) => [$s['slot_number'], $s['slot_status'], $s['local_starts_at']], $data['slots']));
        $this->assertSame([], $data['slots'][1]['staff']); // a cancelled slot holds nothing
        $this->assertSame('2026-11-24T06:00:00Z', $data['session']['ends_at']);
        $this->seeInDatabase('service_slot_staff', ['starts_at' => '2026-11-10 05:00:00', 'ends_at' => '2026-11-10 07:00:00']); // moved with its slot
        $this->seeNumRecords(2, 'service_slot_staff', []);

        // a field left out keeps its value, and the slots stay as they are
        $this->send('v1/client/session/edit', ['public_id' => $publicId, 'session' => ['session_name' => 'Course 1, renamed']])->assertStatus(200);
        $this->seeInDatabase('service_session', ['public_id' => $publicId, 'session_name' => 'Course 1, renamed', 'capacity' => 10]);
        $this->seeNumRecords(2, 'service_slot_staff', []);

        // cancelling the session releases everything, and it cannot change after
        $this->send('v1/client/session/edit', ['public_id' => $publicId, 'session' => ['session_status' => 'X']])->assertStatus(200);
        $this->seeNumRecords(0, 'service_slot_staff', []);
        $this->seeNumRecords(0, 'service_slot_resource', []);
        $this->send('v1/client/session/edit', ['public_id' => $publicId, 'session' => ['session_status' => 'O']])->assertStatus(409);
    }

    public function testNobodyAndNothingIsHeldTwiceAtOnce(): void
    {
        $this->course([['2026-11-14T10:00', '2026-11-14T12:00']]);

        // the instructor teaches 10-12; the studio is cleaned until 12:30
        $response = $this->createDraft([['2026-11-14T11:00', '2026-11-14T13:00', [$this->teacher], []]]);
        $response->assertStatus(409);
        $this->assertStringContainsString('Japanese Basic Course 1', $this->body($response)['errors']['slots.0.staff']);
        $response = $this->createDraft([['2026-11-14T12:10', '2026-11-14T13:00', [], [$this->studio]]]);
        $response->assertStatus(409);
        $this->assertArrayHasKey('slots.0.resources', $this->body($response)['errors']);
        $this->seeNumRecords(1, 'service_session', []); // nothing written

        $this->createDraft([['2026-11-14T12:30', '2026-11-14T14:00', [$this->teacher], [$this->studio]]])->assertStatus(200);
    }

    public function testTheFieldsAreChecked(): void
    {
        $appointment = $this->variant($this->serviceId, 'A', null);
        [$productId] = $this->artifactRow($this->businessId, 'P');
        $product     = $this->variant($productId, null, null);
        [, $online]  = $this->store($this->businessId, 'Online');
        $this->createClient('nobody@example.com', $this->businessId, 'E'); // may not teach it
        $slot = static fn (array $over = []): array => $over + ['starts_at' => '2026-11-14T10:00', 'ends_at' => '2026-11-14T12:00'];
        $base = ['variant_public_id' => $this->groupVariant, 'store_public_id' => $this->storePublicId, 'session_name' => 'Cooking'];

        foreach ([
            [['variant_public_id' => $appointment] + $base, [], 'variant_public_id'],
            [['variant_public_id' => $product] + $base, [], 'variant_public_id'],
            [['store_public_id' => $online] + $base, [], 'store_public_id'],
            [['session_name' => ''] + $base, [], 'session_name'],
            [['capacity' => 0] + $base, [], 'capacity'],
            [['session_status' => 'Z'] + $base, [], 'session_status'],
            [['booking_closes_at' => '2026-02-30T10:00'] + $base, [], 'booking_closes_at'],
            [['session_status' => 'O'] + $base, [], 'slots'], // open with no slot
            [$base, [$slot(['ends_at' => '2026-11-14T09:00'])], 'slots.0.ends_at'],
            [$base, [$slot(['ends_at' => '2026-11-15T11:00'])], 'slots.0.ends_at'], // over 24 hours
            [$base, [$slot(), $slot(['starts_at' => '2026-11-14T11:00', 'ends_at' => '2026-11-14T13:00'])], 'slots.1.starts_at'],
            [$base, [$slot(['staff' => ['nobodyexamplecom']])], 'slots.0.staff'],
            [$base, [$slot(['resources' => ['nope']])], 'slots.0.resources'],
            [$base, [$slot(['slot_number' => 1])], 'slots.0.slot_number'],
            [['session_status' => 'O'] + $base, [$slot(['resources' => [$this->studio]])], 'slots.0.staff'],
            [['session_status' => 'O'] + $base, [$slot(['staff' => [$this->teacher]])], 'slots.0.resources'],
            [['capacity' => 25] + $base, [$slot(['resources' => [$this->studio]])], 'slots.0.resources'], // the studio fits 20
        ] as $n => [$session, $slots, $field]) {
            $response = $this->send('v1/client/session/create', ['business_slug' => 'otter-school', 'session' => $session, 'slots' => $slots]);
            $response->assertStatus(422);
            $this->assertArrayHasKey($field, $this->body($response)['errors'] ?? [], $n . ': ' . $field);
        }
        $this->seeNumRecords(0, 'service_session', []);

        // a draft may hold less than it needs; the variant and the store are fixed
        $response = $this->createDraft([['2026-11-14T10:00', '2026-11-14T12:00', [], []]]);
        $response->assertStatus(200);
        $publicId = $this->body($response)['data']['session']['public_id'];
        $response = $this->send('v1/client/session/edit', ['public_id' => $publicId, 'session' => ['store_public_id' => $online, 'session_status' => 'O']]);
        $this->assertSame(['store_public_id'], array_keys($this->body($response)['errors']));
        $response = $this->send('v1/client/session/edit', ['public_id' => $publicId, 'session' => ['session_status' => 'O']]);
        $this->assertArrayHasKey('slots.0.staff', $this->body($response)['errors']); // opened, it must hold all it needs
    }

    public function testOnlyActiveOwnersAndManagersGetIn(): void
    {
        $publicId = $this->course([['2026-11-14T10:00', '2026-11-14T12:00']]);
        $manager  = $this->createClient('manager@example.com', $this->businessId, 'M');
        $this->send('v1/client/sessions', ['business_slug' => 'otter-school'], $manager)->assertStatus(200);

        $employee = $this->createClient('employee@example.com', $this->businessId, 'E');
        $outsider = $this->createClient('outsider@example.com', null, null);
        foreach ([[$employee, 403], [$outsider, 404]] as [$token, $status]) {
            $this->send('v1/client/sessions', ['business_slug' => 'otter-school'], $token)->assertStatus($status);
            $this->send('v1/client/session', ['public_id' => $publicId], $token)->assertStatus($status);
            $this->send('v1/client/session/options', ['business_slug' => 'otter-school'], $token)->assertStatus($status);
            $this->send('v1/client/session/create', ['business_slug' => 'otter-school', 'session' => []], $token)->assertStatus($status);
            $this->send('v1/client/session/edit', ['public_id' => $publicId, 'session' => ['session_name' => 'X']], $token)->assertStatus($status);
        }
        $this->send('v1/client/session', ['public_id' => 'nope'])->assertStatus(404);
        $this->send('v1/client/session/edit', [])->assertStatus(400);
        $this->seeInDatabase('service_session', ['public_id' => $publicId, 'session_name' => 'Japanese Basic Course 1']);
    }

    /**
     * An open course with the instructor and the studio in every slot
     * @param list<array{string, string}> $times local [starts_at, ends_at]
     * @return string Its public_id
     */
    private function course(array $times): string
    {
        $response = $this->send('v1/client/session/create', [
            'business_slug' => 'otter-school',
            'session'       => ['variant_public_id' => $this->groupVariant, 'store_public_id' => $this->storePublicId, 'session_name' => 'Japanese Basic Course 1', 'session_status' => 'O'],
            'slots'         => array_map(fn (array $t): array => ['starts_at' => $t[0], 'ends_at' => $t[1], 'staff' => [$this->teacher], 'resources' => [$this->studio]], $times),
        ]);
        $response->assertStatus(200);

        return $this->body($response)['data']['session']['public_id'];
    }

    /** @param list<array{string, string, list<string>, list<string>}> $slots [starts_at, ends_at, staff, resources] */
    private function createDraft(array $slots)
    {
        return $this->send('v1/client/session/create', [
            'business_slug' => 'otter-school',
            'session'       => ['variant_public_id' => $this->groupVariant, 'store_public_id' => $this->storePublicId, 'session_name' => 'Thai cooking lesson'],
            'slots'         => array_map(static fn (array $s): array => ['starts_at' => $s[0], 'ends_at' => $s[1], 'staff' => $s[2], 'resources' => $s[3]], $slots),
        ]);
    }

    /** @return string The variant's public_id */
    private function variant(int $artifactId, ?string $bookingType, ?int $capacity): string
    {
        $publicId = $this->fixturePublicId();
        $this->db->table('artifact_variant')->insert(['public_id' => $publicId, 'business_id' => $this->businessId, 'artifact_id' => $artifactId,
            'price_active' => '800.00', 'booking_type' => $bookingType, 'staff_required' => $bookingType === null ? null : 1, 'capacity' => $capacity]);

        return $publicId;
    }

    /** The person of $uid performs the service, and works at the store */
    private function canTeach(string $uid): void
    {
        $clientId = (int) $this->db->table('client_master c')->select('c.id')->join('auth_master a', 'a.id = c.auth_id')->where('a.uid', $uid)->get()->getRow()->id;
        $this->db->table('business_service_client_link')->insert(['business_id' => $this->businessId, 'artifact_id' => $this->serviceId, 'client_id' => $clientId]);
        $this->db->table('business_store_client_link')->insert(['business_id' => $this->businessId, 'store_id' => $this->storeId, 'client_id' => $clientId]);
    }
}
