<?php

use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * notification_master: one public_id per row, a recipient that exists, and rows that go with their recipient or
 * business
 *
 * @internal
 */
final class NotificationSchemaTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace = 'App'; // the app's own migrations, the schema of the real database

    protected function setUp(): void
    {
        parent::setUp();

        $this->db->table('auth_master')->insert(['uid' => 'u1', 'email_address' => 'a@example.com', 'full_name' => 'A']);
        $this->db->table('business_type')->insert(['type_code' => 'spa', 'public_id' => 'ty01']);
        $this->db->table('business_master')->insert(['business_type_id' => 1, 'business_name' => 'Spa', 'business_slug' => 'spa',
            'contact_email_address' => 'spa@example.com', 'country_code' => 'TH', 'currency_code' => 'THB']);
    }

    public function testANotificationStartsUnread(): void
    {
        $this->db->table('notification_master')->insert(['public_id' => 'notif0000001', 'auth_id' => 1, 'business_id' => 1,
            'event_type' => 'payment.approved', 'message_params' => json_encode(['invoice' => 'INV-1']),
            'target_url' => '/subscriptions', 'subject_table' => 'subscription_invoice', 'subject_id' => 1234]);
        $row = $this->db->table('notification_master')->get()->getRow();
        $this->assertNull($row->read_at);
        $this->assertNotNull($row->created_at);
        $this->assertSame(['invoice' => 'INV-1'], json_decode($row->message_params, true));
    }

    public function testPublicIdAndRecipientAreChecked(): void
    {
        $notification = ['public_id' => 'notif0000001', 'auth_id' => 1, 'event_type' => 'payment.slip_submitted'];
        $this->db->table('notification_master')->insert($notification);

        foreach ([
            $notification, // the same public_id
            ['public_id' => 'notif0000002', 'auth_id' => 99] + $notification, // no such recipient
            ['public_id' => 'notif0000003', 'business_id' => 99] + $notification, // no such business
        ] as $row) {
            try {
                $this->db->table('notification_master')->insert($row);
                $this->fail('Inserted ' . json_encode($row));
            } catch (DatabaseException) {
                // refused
            }
        }
        $this->seeNumRecords(1, 'notification_master', []);
    }

    public function testNotificationsGoWithTheirRecipientOrBusiness(): void
    {
        $this->db->table('auth_master')->insert(['uid' => 'u2', 'email_address' => 'b@example.com', 'full_name' => 'B']);
        $this->db->table('notification_master')->insert(['public_id' => 'notif0000001', 'auth_id' => 1, 'event_type' => 'payment.approved', 'business_id' => 1]);
        $this->db->table('notification_master')->insert(['public_id' => 'notif0000002', 'auth_id' => 2, 'event_type' => 'payment.slip_submitted']);

        $this->db->table('business_master')->where('id', 1)->delete();
        $this->seeNumRecords(1, 'notification_master', []);
        $this->db->table('auth_master')->where('id', 2)->delete();
        $this->seeNumRecords(0, 'notification_master', []);
    }
}
