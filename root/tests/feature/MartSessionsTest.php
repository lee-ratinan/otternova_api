<?php

use CodeIgniter\I18n\Time;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\Mock\MockCache;
use Config\Services;
use Tests\Support\ArtifactFixturesTrait;

/**
 * /mart/sessions: the sessions of a group service a customer may book, filtered by store, person and start date
 *
 * @internal
 */
final class MartSessionsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use ArtifactFixturesTrait;

    protected $namespace = 'App';

    private int $businessId;
    private int $storeId;
    private string $storePublicId;
    private int $serviceId;
    private string $variant;
    private int $variantId;
    /** @var array<string, array{int, string}> name => [client_id, public_id] */
    private array $people = [];

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
        $this->createClient('owner@example.com', $this->businessId, 'O');
        [$this->storeId, $this->storePublicId] = $this->store($this->businessId, 'Bangkok');
        $this->db->table('business_store')->where('id', $this->storeId)->update(['store_type' => 'P', 'store_address_line_1' => '1 Silom Rd', 'store_city' => 'Bangkok']);

        // a Thai cooking class for up to 10
        [$this->serviceId] = $this->artifactRow($this->businessId, 'S');
        $this->variant     = $this->fixturePublicId();
        $this->db->table('artifact_variant')->insert(['public_id' => $this->variant, 'business_id' => $this->businessId, 'artifact_id' => $this->serviceId,
            'price_active' => '1500.00', 'duration_minutes' => 180, 'booking_type' => 'G', 'capacity' => 10, 'staff_required' => 1]);
        $this->variantId = (int) $this->db->insertID();

        foreach (['Somsak', 'Anong'] as $name) {
            $email = strtolower($name) . '@example.com';
            $this->createClient($email, $this->businessId, 'E');
            $row = $this->db->table('client_master c')->select('c.id, a.public_id')->join('auth_master a', 'a.id = c.auth_id')
                ->where('a.email_address', $email)->get()->getRow();
            $this->db->table('client_master')->where('id', $row->id)->update(['display_name' => $name]);
            $this->people[$name] = [(int) $row->id, $row->public_id];
        }
    }

    protected function tearDown(): void
    {
        Time::setTestNow();
        parent::tearDown();
    }

    public function testOnlyAGroupVariantIsSearched(): void
    {
        $this->get('v1/mart/sessions?business_slug=otter-spa&variant=nope')->assertStatus(404);
        $this->db->table('artifact_variant')->where('id', $this->variantId)->update(['booking_type' => 'A', 'capacity' => null]);
        $this->get('v1/mart/sessions?business_slug=otter-spa&variant=' . $this->variant)->assertStatus(404);
        $this->db->table('artifact_variant')->where('id', $this->variantId)->update(['booking_type' => 'G', 'capacity' => 10, 'variant_status' => 'I']);
        $this->get('v1/mart/sessions?business_slug=otter-spa&variant=' . $this->variant)->assertStatus(404);
    }

    public function testOnlyBookableSessionsAreOffered(): void
    {
        $open = $this->session('Friday class', [['2026-11-20 02:00:00', '2026-11-20 05:00:00', ['Somsak']]], ['seats_booked' => 3]);
        $this->session('Draft', [['2026-11-20 02:00:00', '2026-11-20 05:00:00', []]], ['session_status' => 'D']);
        $this->session('Closed', [['2026-11-20 02:00:00', '2026-11-20 05:00:00', []]], ['session_status' => 'C']);
        $this->session('Full', [['2026-11-20 02:00:00', '2026-11-20 05:00:00', []]], ['seats_booked' => 10]);
        $this->session('Started', [['2026-11-16 02:00:00', '2026-11-16 05:00:00', []]]);
        $this->session('Booking closed', [['2026-11-20 02:00:00', '2026-11-20 05:00:00', []]], ['booking_closes_at' => '2026-11-15 12:00:00']);
        $this->session('No slots', []);

        $data = $this->sessions();
        $this->assertSame([$open], array_column($data['sessions'], 'public_id'));
        $session = $data['sessions'][0];
        $this->assertSame('Friday class', $session['name']);
        $this->assertSame($this->storePublicId, $session['store_public_id']);
        $this->assertSame('2026-11-20T09:00:00+07:00', $session['starts_at']);
        $this->assertSame('2026-11-20T12:00:00+07:00', $session['ends_at']);
        // with no booking_closes_at, booking closes as it starts
        $this->assertSame('2026-11-20T09:00:00+07:00', $session['booking_closes_at']);
        $this->assertSame([10, 3, 7], [$session['capacity'], $session['seats_booked'], $session['seats_available']]);
        $this->assertSame([['slot_number' => 1, 'starts_at' => '2026-11-20T09:00:00+07:00', 'ends_at' => '2026-11-20T12:00:00+07:00',
            'staff' => [$this->people['Somsak'][1]]]], $session['slots']);

        $this->assertSame(['Bangkok'], array_column($data['stores'], 'name'));
        $this->assertSame('1 Silom Rd', $data['stores'][0]['address']['line_1']);
        $this->assertSame([['public_id' => $this->people['Somsak'][1], 'name' => 'Somsak', 'restrictions' => null]], $data['staff']);
        $this->assertSame(['public_id' => $this->variant, 'label' => '', 'duration_minutes' => 180, 'capacity' => 10],
            array_diff_key($data['service']['variant'], ['price' => true]));
        $this->assertStringNotContainsString('somsakexamplecom', json_encode($data));
    }

    public function testASessionOfSeveralDaysIsFoundByItsStart(): void
    {
        // two Wednesdays, booking open until the Tuesday before
        $course = $this->session('Course', [
            ['2026-11-18 02:00:00', '2026-11-18 05:00:00', ['Anong']],
            ['2026-11-25 02:00:00', '2026-11-25 05:00:00', ['Anong', 'Somsak']],
        ], ['booking_closes_at' => '2026-11-17 10:00:00']);
        $friday = $this->session('Friday class', [['2026-11-20 02:00:00', '2026-11-20 05:00:00', ['Somsak']]]);

        $data = $this->sessions();
        $this->assertSame([$course, $friday], array_column($data['sessions'], 'public_id'));
        $this->assertSame('2026-11-25T12:00:00+07:00', $data['sessions'][0]['ends_at']);
        $this->assertSame('2026-11-17T17:00:00+07:00', $data['sessions'][0]['booking_closes_at']);
        $this->assertSame([1, 2], array_column($data['sessions'][0]['slots'], 'slot_number'));
        // by name
        $this->assertSame([$this->people['Anong'][1], $this->people['Somsak'][1]], $data['sessions'][0]['staff']);

        $this->assertSame([$course], array_column($this->sessions('&date_from=2026-11-18&date_to=2026-11-18')['sessions'], 'public_id'));
        // not found by a later day of it
        $this->assertSame([$friday], array_column($this->sessions('&date_from=2026-11-19')['sessions'], 'public_id'));
        $this->assertSame([$course], array_column($this->sessions('&date_to=2026-11-19')['sessions'], 'public_id'));

        $this->assertArrayHasKey('date_from', $this->errors('&date_from=2026-11-31'));
        $this->assertArrayHasKey('date_to', $this->errors('&date_from=2026-11-20&date_to=2026-11-19'));
    }

    public function testSessionsAreFilteredByStoreAndPerson(): void
    {
        [$asokId, $asok] = $this->store($this->businessId, 'Asok');
        $this->db->table('business_store')->where('id', $asokId)->update(['store_type' => 'P']);
        $this->db->table('business_store_translation')->insert(['store_id' => $asokId, 'language_code' => 'th', 'store_name' => 'อโศก']);
        $silom = $this->session('Silom class', [['2026-11-20 02:00:00', '2026-11-20 05:00:00', ['Somsak']]]);
        $asokClass = $this->session('Asok class', [['2026-11-21 02:00:00', '2026-11-21 05:00:00', ['Anong']]], ['store_id' => $asokId]);

        $data = $this->sessions();
        $this->assertSame(['Asok', 'Bangkok'], array_column($data['stores'], 'name'));
        $this->assertSame(['Anong', 'Somsak'], array_column($data['staff'], 'name'));

        $data = $this->sessions('&store=' . $asok);
        $this->assertSame([$asokClass], array_column($data['sessions'], 'public_id'));
        // the store's people only; every store still listed
        $this->assertSame(['Anong'], array_column($data['staff'], 'name'));
        $this->assertCount(2, $data['stores']);

        $this->assertSame([$silom], array_column($this->sessions('&staff=' . $this->people['Somsak'][1])['sessions'], 'public_id'));
        $this->assertArrayHasKey('staff', $this->errors('&store=' . $asok . '&staff=' . $this->people['Somsak'][1]));
        $this->assertArrayHasKey('store', $this->errors('&store=nostore12345'));

        // by the name in the answer's language
        $this->assertSame(['Bangkok', 'อโศก'], array_column($this->sessions('&lang=th')['stores'], 'name'));
    }

    public function testASessionIsNamedInTheAnswersLanguage(): void
    {
        $id = $this->session('Cooking', [['2026-11-20 02:00:00', '2026-11-20 05:00:00', []]]);
        $sessionId = (int) $this->db->table('service_session')->select('id')->where('public_id', $id)->get()->getRow()->id;
        $this->db->table('service_session_translation')->insert(['session_id' => $sessionId, 'language_code' => 'th', 'session_name' => 'ทำอาหาร']);

        $this->assertSame('ทำอาหาร', $this->sessions('&lang=th')['sessions'][0]['name']);
        $this->assertSame('Cooking', $this->sessions('&lang=en-US')['sessions'][0]['name']);
    }

    /**
     * An open session of the class at the store, with its scheduled slots and whom each holds
     * @param list<array{0: string, 1: string, 2: list<string>}> $slots [starts_at, ends_at (UTC), people's names]
     * @return string Its public_id
     */
    private function session(string $name, array $slots, array $columns = []): string
    {
        $publicId = $this->fixturePublicId();
        $this->db->table('service_session')->insert($columns + ['public_id' => $publicId, 'business_id' => $this->businessId, 'store_id' => $this->storeId,
            'artifact_id' => $this->serviceId, 'variant_id' => $this->variantId, 'session_name' => $name, 'capacity' => 10, 'session_status' => 'O',
            'starts_at' => $slots[0][0] ?? null, 'ends_at' => $slots === [] ? null : end($slots)[1]]);
        $sessionId = (int) $this->db->insertID();
        foreach ($slots as $i => [$startsAt, $endsAt, $people]) {
            $this->db->table('service_session_slot')->insert(['business_id' => $this->businessId, 'session_id' => $sessionId, 'slot_number' => $i + 1,
                'starts_at' => $startsAt, 'ends_at' => $endsAt]);
            $slotId = (int) $this->db->insertID();
            foreach ($people as $person) {
                $this->db->table('service_slot_staff')->insert(['business_id' => $this->businessId, 'slot_id' => $slotId, 'client_id' => $this->people[$person][0],
                    'starts_at' => $startsAt, 'ends_at' => $endsAt]);
            }
        }

        return $publicId;
    }

    /** @return array The answer's data */
    private function sessions(string $query = ''): array
    {
        $response = $this->get('v1/mart/sessions?business_slug=otter-spa&variant=' . $this->variant . $query);
        $response->assertStatus(200);

        return $this->body($response)['data'];
    }

    /** @return array The 422 answer's errors */
    private function errors(string $query): array
    {
        $response = $this->get('v1/mart/sessions?business_slug=otter-spa&variant=' . $this->variant . $query);
        $response->assertStatus(422);

        return $this->body($response)['errors'];
    }
}
