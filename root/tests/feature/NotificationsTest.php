<?php

use App\Libraries\Notifier;
use CodeIgniter\I18n\Time;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\Notifications;
use Config\Services;

/**
 * /staff/notifications/* and /client/notifications/*: each account sees, reads and deletes its own notifications only;
 * the cleanup command deletes the old read ones
 *
 * @internal
 */
final class NotificationsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'App'; // the app's own migrations, the schema of the real database

    private array $staff;
    private array $client;
    private string $staffToken;
    private string $clientToken;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['jwt', 'authContext'] as $service) {
            Services::resetSingle($service);
        }
        $this->db->table('otternova_department')->insert(['department_code' => 'FIN', 'department_name' => 'Finance', 'country_code' => 'TH']);
        $this->staff = $this->createAccount('fin@otternova.com', 'S');
        $this->db->table('staff_master')->insert(['auth_id' => $this->staff['id'], 'department_id' => 1, 'employee_code' => 'EMP-1', 'job_title' => 'Officer',
            'employment_status' => 'A', 'employment_start_date' => '2024-01-01', 'work_country_code' => 'TH', 'legal_first_name' => 'Otter', 'legal_last_name' => 'Tester']);
        $this->client = $this->createAccount('owner@example.com', 'C');
        $this->db->table('client_master')->insert(['auth_id' => $this->client['id']]);
        $this->staffToken  = service('jwt')->issue($this->staff)['access_token'];
        $this->clientToken = service('jwt')->issue($this->client)['access_token'];
    }

    protected function tearDown(): void
    {
        Time::setTestNow();
        parent::tearDown();
    }

    public function testEachAccountListsReadsAndDeletesItsOwnOnly(): void
    {
        $notifier = new Notifier();
        foreach (['INV-1', 'INV-2', 'INV-3'] as $no) {
            $notifier->toDepartments(['FIN'], Notifier::PAYMENT_SLIP_SUBMITTED, ['business' => 'OtterCafe', 'amount' => 'THB 1.00', 'invoice_no' => $no], '/admin/x');
        }
        $this->assertSame(1, $notifier->toAccount((int) $this->client['id'], Notifier::PAYMENT_APPROVED, ['amount' => 'THB 1.00', 'invoice_no' => 'INV-1'], '/admin/plans'));
        $this->assertSame(0, $notifier->toAccount((int) $this->client['id'], Notifier::PAYMENT_APPROVED, [], null, null, null, (int) $this->client['id']));

        $page = $this->data($this->send('staff', 'list', ['length' => 2]));
        $this->assertSame([3, 3, 3, 2], [$page['records_total'], $page['records_filtered'], $page['unread_count'], count($page['rows'])]);
        $first = $page['rows'][0]['public_id'];
        $own   = $this->data($this->send('client', 'latest'))['rows'][0]['public_id'];

        // another account's notification is not found; the client's token is not let into the staff's routes
        $this->send('staff', 'read', ['public_id' => $own])->assertStatus(404);
        $this->send('staff', 'delete', ['public_id' => $own])->assertStatus(404);
        $this->send('staff', 'read', ['public_id' => 'not-an-id'])->assertStatus(404);
        $this->withHeaders(['Authorization' => 'Bearer ' . $this->clientToken])->withBodyFormat('json')->post('v1/staff/notifications/latest', [])->assertStatus(403);

        $read = $this->data($this->send('staff', 'read', ['public_id' => $first]));
        $this->assertSame([true, '/admin/x', 2], [$read['notification']['is_read'], $read['notification']['target_url'], $read['unread_count']]);
        $this->assertSame(1, $this->data($this->send('staff', 'list', ['filters' => ['status' => 'read']]))['records_filtered']);
        $this->assertSame(2, $this->data($this->send('staff', 'list', ['filters' => ['status' => 'unread']]))['records_filtered']);
        $this->send('staff', 'list', ['filters' => ['status' => 'done']])->assertStatus(422);

        $this->assertSame(1, $this->data($this->send('staff', 'delete-read'))['deleted']);
        $this->assertSame(2, $this->data($this->send('staff', 'read-all'))['marked']);
        $this->send('staff', 'delete', ['public_id' => $this->data($this->send('staff', 'latest'))['rows'][0]['public_id']])->assertStatus(200);
        $this->seeNumRecords(1, 'notification_master', ['auth_id' => $this->staff['id']]);
        $this->assertSame(1, $this->data($this->send('client', 'latest'))['unread_count']); // the client's untouched
    }

    public function testTheMessageIsInTheReadersLanguage(): void
    {
        (new Notifier())->toAccount((int) $this->client['id'], Notifier::PAYMENT_REJECTED, ['invoice_no' => 'INV-1', 'reason' => 'สลิปไม่ชัด'], '/admin/plans');
        $row = $this->db->table('notification_master')->get()->getRowArray();

        service('request')->setLocale('th');
        $th = Notifier::present($row);
        service('request')->setLocale('en-US');
        $en = Notifier::present($row);

        $this->assertSame(['การชำระเงินถูกปฏิเสธ', 'การชำระเงินสำหรับใบแจ้งหนี้ INV-1 ถูกปฏิเสธ: สลิปไม่ชัด กรุณาชำระเงินอีกครั้ง'], [$th['title'], $th['message']]);
        $this->assertSame(['Payment rejected', 'bi-x-circle', 'danger'], [$en['title'], $en['icon'], $en['tone']]);
    }

    public function testUnknownEventsAndFullUrlsAreRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new Notifier())->toAccount((int) $this->client['id'], Notifier::PAYMENT_APPROVED, [], 'https://example.com/');
    }

    public function testTheCleanupDeletesOldReadOnesOnly(): void
    {
        config(Notifications::class)->readRetentionDays = 30;
        foreach ([['old1', '2026-01-01 00:00:00'], ['new1', Time::now('UTC')->toDateTimeString()], ['unread', null]] as [$id, $readAt]) {
            $this->db->table('notification_master')->insert(['public_id' => str_pad($id, 12, '0'), 'auth_id' => $this->client['id'],
                'event_type' => Notifier::PAYMENT_APPROVED, 'read_at' => $readAt, 'created_at' => '2026-01-01 00:00:00']);
        }

        command('notifications:cleanup');

        $this->assertSame(['new100000000', 'unread000000'], array_column($this->db->table('notification_master')->orderBy('public_id')->get()->getResultArray(), 'public_id'));
    }

    private function createAccount(string $email, string $type): array
    {
        $account = ['uid' => preg_replace('/[^a-z0-9]/', '', $email), 'email_address' => $email, 'full_name' => 'Otter Tester',
            'password_hash' => password_hash('correct horse battery staple', PASSWORD_DEFAULT), 'password_expiry' => '2027-01-01',
            'otp_mode' => 'X', 'account_status' => 'A', 'account_type' => $type];
        $this->db->table('auth_master')->insert($account + ['public_id' => bin2hex(random_bytes(6))]);
        $account['id'] = $this->db->insertID();

        return $account;
    }

    private function send(string $app, string $action, array $body = []): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . ($app === 'staff' ? $this->staffToken : $this->clientToken)])
            ->withBodyFormat('json')
            ->post('v1/' . $app . '/notifications/' . $action, $body);
    }

    private function data(TestResponse $response): array
    {
        $response->assertStatus(200);

        return json_decode($response->getJSON(), true)['data'];
    }
}
