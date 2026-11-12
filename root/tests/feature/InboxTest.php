<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\Services;

/**
 * /staff/inbox/*: the contact form's messages, for any staff member
 *
 * @internal
 */
final class InboxTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'App'; // the app's own migrations, the schema of the real database

    private array $staff;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['jwt', 'authContext'] as $service) {
            Services::resetSingle($service);
        }
        $this->db->table('otternova_department')->insert(['department_code' => 'SUP', 'department_name' => 'Support', 'country_code' => 'TH']);
        $this->staff = $this->createStaff('support@otternova.com', (int) $this->db->insertID());
        $this->token = service('jwt')->issue($this->staff)['access_token'];
    }

    public function testOnlyStaffGetIn(): void
    {
        $this->withHeaders([])->post('v1/staff/inbox/messages')->assertStatus(401);

        $client = $this->createAccount('client@example.com', 'C');
        $this->send('v1/staff/inbox/messages', [], service('jwt')->issue($client)['access_token'])->assertStatus(403);

        $this->send('v1/staff/inbox/messages', [])->assertStatus(200);
    }

    public function testListsNewestFirstWithAnExcerpt(): void
    {
        $this->message('Ann', 'ann@example.com', 'Pricing', str_repeat('Hello there ', 20));
        $this->message('Bob', 'bob@example.com', 'Refund', "Line one\n\nLine two", 'S');

        $data = $this->data([]);
        $this->assertSame([2, 2], [$data['records_total'], $data['records_filtered']]);
        $this->assertSame(['Refund', 'Pricing'], array_column($data['rows'], 'subject'));
        $this->assertSame('Line one Line two', $data['rows'][0]['excerpt']);
        $this->assertStringEndsWith('…', $data['rows'][1]['excerpt']);
        $this->assertArrayNotHasKey('message', $data['rows'][0]);
        $this->assertArrayNotHasKey('ip_address', $data['rows'][0]);
    }

    public function testFiltersByStatusAndSearches(): void
    {
        $this->message('Ann', 'ann@example.com', 'Pricing', 'Hi');
        $this->message('Bob', 'bob@example.com', 'Refund', 'Hi', 'S');
        $this->message('Cat', 'cat@example.com', 'Refund again', 'Hi');

        $this->assertSame(['Refund again', 'Pricing'], $this->subjects(['filters' => ['status' => 'n']]));
        $this->assertSame(['Refund'], $this->subjects(['filters' => ['status' => 'S']]));
        $this->assertSame(['Refund again', 'Refund'], $this->subjects(['search' => 'refund']));
        $this->assertSame(['Pricing'], $this->subjects(['search' => 'ann@']));
        $data = $this->data(['filters' => ['status' => 'N'], 'search' => 'Cat']);
        $this->assertSame([2, 1], [$data['records_total'], $data['records_filtered']]);

        $this->send('v1/staff/inbox/messages', ['filters' => ['status' => 'Z']])->assertStatus(422);
    }

    public function testOpeningANewMessageMarksItRead(): void
    {
        $id = $this->message('Ann', 'ann@example.com', 'Pricing', 'Hello');

        $response = $this->send('v1/staff/inbox/message', ['id' => $id]);
        $response->assertStatus(200);
        $message = $this->body($response)['data']['message'];
        $this->assertSame('Hello', $message['message']);
        $this->assertSame('R', $message['status']);
        $this->assertSame('Otter Tester', $message['handled_by_name']);
        $this->assertSame('203.0.113.9', $message['ip_address']);
        $this->assertSame(1, $this->db->table('log_activity')->where('table_involved', 'contact_message')->where('table_id_updated', $id)->countAllResults());

        // opened again: nothing more to log
        $this->send('v1/staff/inbox/message', ['id' => $id])->assertStatus(200);
        $this->assertSame(1, $this->db->table('log_activity')->where('table_involved', 'contact_message')->where('table_id_updated', $id)->countAllResults());

        $this->send('v1/staff/inbox/message', ['id' => $id + 1])->assertStatus(404);
        $this->send('v1/staff/inbox/message', ['id' => 'x'])->assertStatus(404);
    }

    public function testMarksAMessage(): void
    {
        $id = $this->message('Ann', 'ann@example.com', 'Pricing', 'Hello');

        $done = $this->send('v1/staff/inbox/message/status', ['id' => $id, 'status' => 'D']);
        $done->assertStatus(200);
        $this->assertSame('D', $this->body($done)['data']['message']['status']);
        $row = $this->db->table('contact_message')->where('id', $id)->get()->getRowArray();
        $this->assertSame($this->staff['id'], (int) $row['handled_by']);
        $this->assertNotNull($row['handled_at']);

        // new again forgets who handled it
        $this->send('v1/staff/inbox/message/status', ['id' => $id, 'status' => 'N'])->assertStatus(200);
        $row = $this->db->table('contact_message')->where('id', $id)->get()->getRowArray();
        $this->assertSame('N', $row['status']);
        $this->assertNull($row['handled_by']);
        $this->assertNull($row['handled_at']);

        $this->send('v1/staff/inbox/message/status', ['id' => $id, 'status' => 'X'])->assertStatus(422);
        $this->send('v1/staff/inbox/message/status', ['id' => $id + 1, 'status' => 'D'])->assertStatus(404);
    }

    // ---- helpers ----

    private function message(string $name, string $email, string $subject, string $text, string $status = 'N'): int
    {
        $this->db->table('contact_message')->insert([
            'name'          => $name,
            'email_address' => $email,
            'subject'       => $subject,
            'message'       => $text,
            'locale'        => 'en-US',
            'status'        => $status,
            'spam_reason'   => $status === 'S' ? 'links' : null,
            'ip_address'    => '203.0.113.9',
            'user_agent'    => 'PHPUnit',
        ]);

        return (int) $this->db->insertID();
    }

    private function createAccount(string $email, string $type): array
    {
        $account = [
            'uid'             => preg_replace('/[^a-z0-9]/', '', $email),
            'email_address'   => $email,
            'password_hash'   => password_hash('correct horse battery staple', PASSWORD_DEFAULT),
            'password_expiry' => '2027-01-01',
            'full_name'       => 'Otter Tester',
            'otp_mode'        => 'X',
            'account_status'  => 'A',
            'account_type'    => $type,
        ];
        $this->db->table('auth_master')->insert($account + ['public_id' => bin2hex(random_bytes(6))]);
        $account['id'] = (int) $this->db->insertID();

        return $account;
    }

    private function createStaff(string $email, int $departmentId): array
    {
        $account = $this->createAccount($email, 'S');
        $this->db->table('staff_master')->insert([
            'auth_id'               => $account['id'],
            'department_id'         => $departmentId,
            'employee_code'         => 'EMP-' . $account['id'],
            'job_title'             => 'Officer',
            'employment_status'     => 'A',
            'employment_start_date' => '2024-01-01',
            'work_country_code'     => 'TH',
            'legal_first_name'      => 'Otter',
            'legal_last_name'       => 'Tester',
        ]);

        return $account;
    }

    private function send(string $path, array $body, ?string $token = null): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . ($token ?? $this->token)])
            ->withBodyFormat('json')
            ->post($path, $body);
    }

    private function body(TestResponse $response): array
    {
        return json_decode($response->getJSON(), true);
    }

    private function data(array $body): array
    {
        $response = $this->send('v1/staff/inbox/messages', $body);
        $response->assertStatus(200);

        return $this->body($response)['data'];
    }

    private function subjects(array $body): array
    {
        return array_column($this->data($body)['rows'], 'subject');
    }
}
