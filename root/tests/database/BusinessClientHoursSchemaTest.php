<?php

use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * The business_client_hours tables: a shift only at a store the person works at, in their own business; a day off
 * with no store; and business_store_client_link.link_status
 *
 * @internal
 */
final class BusinessClientHoursSchemaTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace = 'App'; // the app's own migrations, the schema of the real database

    private int $businessId;
    private int $clientId;
    private int $storeId;
    private int $otherStoreId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db->table('business_type')->insert(['type_code' => 'spa', 'public_id' => 'ty01']);
        $type = (int) $this->db->insertID();
        foreach (['spa', 'other'] as $slug) {
            $this->db->table('business_master')->insert(['business_type_id' => $type, 'business_name' => $slug, 'business_slug' => $slug,
                'contact_email_address' => $slug . '@example.com', 'country_code' => 'TH', 'currency_code' => 'THB']);
        }
        $this->businessId = 1;
        foreach ([[1, 'store0000001', 'Bangkok'], [1, 'store0000002', 'Pattaya']] as [$business, $publicId, $name]) {
            $this->db->table('business_store')->insert(['public_id' => $publicId, 'business_id' => $business, 'store_name' => $name, 'store_timezone' => 'Asia/Bangkok']);
        }
        $this->storeId      = 1;
        $this->otherStoreId = 2;
        $this->db->table('auth_master')->insert(['uid' => 'u1', 'email_address' => 'u1@example.com', 'password_hash' => 'x', 'password_expiry' => '2027-01-01',
            'full_name' => 'U', 'otp_mode' => 'X', 'account_status' => 'A', 'account_type' => 'C']);
        $this->db->table('client_master')->insert(['auth_id' => $this->db->insertID()]);
        $this->clientId = (int) $this->db->insertID();
        $this->db->table('business_client_link')->insert(['business_id' => 1, 'client_id' => $this->clientId, 'start_date' => '2026-01-01']);
        $this->db->table('business_store_client_link')->insert(['business_id' => 1, 'store_id' => $this->storeId, 'client_id' => $this->clientId]);
    }

    public function testAStoreLinkIsActiveAtFirst(): void
    {
        $this->assertSame('A', $this->db->table('business_store_client_link')->get()->getRow()->link_status);
    }

    public function testAShiftIsOnlyAtAStoreThePersonWorksAt(): void
    {
        $shift = ['business_id' => 1, 'client_id' => $this->clientId, 'day_of_week' => 1, 'start_time' => '09:00:00', 'end_time' => '12:00:00'];
        $this->db->table('business_client_hours')->insert($shift + ['store_id' => $this->storeId]);
        // the same day and start may be there twice: an inactive one and an active one
        $this->db->table('business_client_hours')->insert($shift + ['store_id' => $this->storeId, 'active_status' => 'I']);
        $this->assertSame('A', $this->db->table('business_client_hours')->where('id', 1)->get()->getRow()->active_status);

        $this->assertRefused('business_client_hours', $shift + ['store_id' => $this->otherStoreId]); // not linked to it
        $this->assertRefused('business_client_hours', ['business_id' => 2] + $shift + ['store_id' => $this->storeId]); // another business
    }

    public function testADayOffHasNoStore(): void
    {
        $day = ['business_id' => 1, 'client_id' => $this->clientId, 'exception_date' => '2026-12-25'];
        $this->db->table('business_client_hours_exceptions')->insert($day + ['exception_type' => 'V', 'exception_reason' => 'Family trip']);
        $this->db->table('business_client_hours_exceptions')->insert($day + ['exception_type' => 'O', 'store_id' => $this->storeId,
            'start_time' => '10:00:00', 'end_time' => '14:00:00']);
        $this->assertSame(2, $this->db->table('business_client_hours_exceptions')->countAllResults());

        $this->assertRefused('business_client_hours_exceptions', $day + ['exception_type' => 'O', 'store_id' => $this->otherStoreId,
            'start_time' => '10:00:00', 'end_time' => '14:00:00']);
        $this->assertRefused('business_client_hours_exceptions', ['business_id' => 2] + $day + ['exception_type' => 'S']); // not a person of it
    }

    public function testAStoreLinkWithShiftsCannotBeDeleted(): void
    {
        $this->db->table('business_client_hours')->insert(['business_id' => 1, 'client_id' => $this->clientId, 'store_id' => $this->storeId,
            'day_of_week' => 1, 'start_time' => '09:00:00', 'end_time' => '12:00:00']);
        $this->assertRefused(null, [], fn () => $this->db->table('business_store_client_link')->delete(['id' => 1]));
    }

    private function assertRefused(?string $table, array $row, ?callable $write = null): void
    {
        try {
            $write === null ? $this->db->table($table)->insert($row) : $write();
            $this->assertSame(0, $this->db->affectedRows(), 'Accepted ' . json_encode($row));
        } catch (DatabaseException) {
            $this->addToAssertionCount(1);
        }
    }
}
