<?php

use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * The service session tables: a session at a store and of a variant of its own business's service, its slots and
 * their holds kept in that business, one number per slot, a person or resource once per slot, and a held slot or
 * person never deleted from under a session
 *
 * @internal
 */
final class ServiceSessionSchemaTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace = 'App'; // the app's own migrations, the schema of the real database

    private int $clientId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db->table('business_type')->insert(['type_code' => 'school', 'public_id' => 'ty01']);
        $type = (int) $this->db->insertID();
        // business 1 and 2 each get store, artifact, variant and resource 1 and 2 by the same id
        foreach (['school', 'other'] as $i => $slug) {
            $business = $i + 1;
            $this->db->table('business_master')->insert(['business_type_id' => $type, 'business_name' => $slug, 'business_slug' => $slug,
                'contact_email_address' => $slug . '@example.com', 'country_code' => 'TH', 'currency_code' => 'THB']);
            $this->db->table('business_store')->insert(['public_id' => 'store000000' . $business, 'business_id' => $business, 'store_name' => 'Bangkok', 'store_timezone' => 'Asia/Bangkok']);
            $this->db->table('artifact_master')->insert(['public_id' => 'artif000000' . $business, 'business_id' => $business, 'artifact_name' => 'Muay Thai',
                'artifact_type' => 'S', 'tax_calculation' => 'X']);
            $this->db->table('artifact_variant')->insert(['public_id' => 'varnt000000' . $business, 'business_id' => $business, 'artifact_id' => $business,
                'price_active' => '800.00', 'booking_type' => 'G', 'staff_required' => 1, 'capacity' => 12]);
            $this->db->table('resource_type')->insert(['public_id' => 'rtype000000' . $business, 'business_id' => $business, 'type_name' => 'Studio']);
            $this->db->table('resource_master')->insert(['public_id' => 'resrc000000' . $business, 'business_id' => $business, 'store_id' => $business,
                'resource_type_id' => $business, 'resource_name' => 'Main studio', 'capacity' => 20]);
        }
        $this->db->table('auth_master')->insert(['public_id' => bin2hex(random_bytes(6)), 'uid' => 'u1', 'email_address' => 'u1@example.com', 'password_hash' => 'x', 'password_expiry' => '2027-01-01',
            'full_name' => 'Somsak', 'otp_mode' => 'X', 'account_status' => 'A', 'account_type' => 'C']);
        $this->db->table('client_master')->insert(['auth_id' => $this->db->insertID()]);
        $this->clientId = (int) $this->db->insertID();
        $this->db->table('business_client_link')->insert(['business_id' => 1, 'client_id' => $this->clientId, 'start_date' => '2026-01-01']);
    }

    public function testASessionIsAtAStoreAndOfAVariantOfItsBusiness(): void
    {
        $session = $this->session();
        $this->db->table('service_session')->insert($session);
        $row = $this->db->table('service_session')->get()->getRow();
        $this->assertSame('D', $row->session_status);
        $this->assertSame(0, (int) $row->seats_booked);
        $this->assertNull($row->starts_at);

        $this->assertRefused('service_session', ['public_id' => 'sessn0000002', 'store_id' => 2] + $session); // the other business's store
        $this->assertRefused('service_session', ['public_id' => 'sessn0000003', 'artifact_id' => 2, 'variant_id' => 2] + $session); // the other business's service
        $this->assertRefused('service_session', ['public_id' => 'sessn0000004', 'variant_id' => 2] + $session); // a variant of another artifact
        $this->assertRefused('service_session', ['public_id' => 'sessn0000001'] + $session); // the same public_id
        $this->seeNumRecords(1, 'service_session', []);
    }

    public function testSlotsAreNumberedOncePerSessionInItsBusiness(): void
    {
        $this->db->table('service_session')->insert($this->session());
        $slot = ['business_id' => 1, 'session_id' => 1, 'slot_number' => 1, 'starts_at' => '2026-11-10 11:00:00', 'ends_at' => '2026-11-10 13:00:00'];
        $this->db->table('service_session_slot')->insert($slot);
        $this->db->table('service_session_slot')->insert(['slot_number' => 2, 'starts_at' => '2026-11-17 11:00:00', 'ends_at' => '2026-11-17 13:00:00'] + $slot);
        $this->assertSame('S', $this->db->table('service_session_slot')->where('id', 1)->get()->getRow()->slot_status);

        $this->assertRefused('service_session_slot', $slot); // number 1 again
        $this->assertRefused('service_session_slot', ['business_id' => 2, 'slot_number' => 3] + $slot); // another business
        $this->seeNumRecords(2, 'service_session_slot', []);
    }

    public function testASlotHoldsPeopleAndResourcesOfItsBusinessOnce(): void
    {
        $this->db->table('service_session')->insert($this->session());
        $this->db->table('service_session_slot')->insert(['business_id' => 1, 'session_id' => 1, 'slot_number' => 1,
            'starts_at' => '2026-11-14 03:00:00', 'ends_at' => '2026-11-14 05:00:00']);
        $times = ['starts_at' => '2026-11-14 03:00:00'];

        $staff = ['business_id' => 1, 'slot_id' => 1, 'client_id' => $this->clientId, 'ends_at' => '2026-11-14 05:00:00'] + $times;
        $this->db->table('service_slot_staff')->insert($staff);
        $this->assertRefused('service_slot_staff', $staff); // the same person twice
        $this->assertRefused('service_slot_staff', ['business_id' => 2] + $staff); // not a person of the other business, nor its slot

        $resource = ['business_id' => 1, 'slot_id' => 1, 'resource_id' => 1, 'held_until' => '2026-11-14 05:30:00'] + $times;
        $this->db->table('service_slot_resource')->insert($resource);
        $this->assertRefused('service_slot_resource', $resource); // the same resource twice
        $this->assertRefused('service_slot_resource', ['resource_id' => 2] + $resource); // the other business's studio

        // the person cannot leave the business, nor the slot's session be deleted, while held
        $this->assertDeleteRefused('business_client_link', ['client_id' => $this->clientId]);
        $this->assertDeleteRefused('service_session', ['id' => 1]);
        $this->assertDeleteRefused('resource_master', ['id' => 1]);
    }

    public function testATranslationGoesWithItsSession(): void
    {
        $this->db->table('service_session')->insert($this->session());
        $translation = ['session_id' => 1, 'language_code' => 'en-US', 'session_name' => 'Thai cooking lesson'];
        $this->db->table('service_session_translation')->insert($translation);
        $this->assertRefused('service_session_translation', $translation); // one per language
    }

    private function session(): array
    {
        return ['public_id' => 'sessn0000001', 'business_id' => 1, 'store_id' => 1, 'artifact_id' => 1, 'variant_id' => 1,
            'session_name' => 'Japanese Basic Course 1 starting 10 Nov 2026 with Jiro Sensei', 'capacity' => 12];
    }

    private function assertRefused(string $table, array $row): void
    {
        try {
            $this->db->table($table)->insert($row);
        } catch (DatabaseException) {
            $this->addToAssertionCount(1);

            return;
        }
        $this->fail('Inserted into ' . $table . ': ' . json_encode($row));
    }

    private function assertDeleteRefused(string $table, array $where): void
    {
        try {
            $this->db->table($table)->where($where)->delete();
        } catch (DatabaseException) {
            $this->addToAssertionCount(1);

            return;
        }
        $this->fail('Deleted from ' . $table . ': ' . json_encode($where));
    }
}
