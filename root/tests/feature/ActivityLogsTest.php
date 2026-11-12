<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\Services;

/**
 * /staff/logs/*: the activity log, for the Executive Office (EXE) only
 *
 * @internal
 */
final class ActivityLogsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'App'; // the app's own migrations, the schema of the real database

    /** otternova_department.id by code */
    private array $departments = [];

    /** The signed-in EXE staff account calling the endpoints */
    private array $staff;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['jwt', 'authContext'] as $service) {
            Services::resetSingle($service);
        }
        foreach (['EXE' => 'Executive Office', 'FIN' => 'Finance'] as $code => $name) {
            $this->db->table('otternova_department')->insert(['department_code' => $code, 'department_name' => $name, 'country_code' => 'TH']);
            $this->departments[$code] = (int) $this->db->insertID();
        }
        $this->staff = $this->createStaff('exec@otternova.com', 'EXE');
        $this->token = service('jwt')->issue($this->staff)['access_token'];
    }

    public function testOnlyTheExecutiveOfficeGetsIn(): void
    {
        $this->withHeaders([])->post('v1/staff/logs/activities')->assertStatus(401);

        $finance = $this->createStaff('fin@otternova.com', 'FIN');
        $this->send('v1/staff/logs/activities', [], service('jwt')->issue($finance)['access_token'])->assertStatus(403);
        $this->send('v1/staff/logs/tables', [], service('jwt')->issue($finance)['access_token'])->assertStatus(403);

        $this->send('v1/staff/logs/activities', [])->assertStatus(200);
    }

    public function testListsNewestFirstWithTheActor(): void
    {
        $this->log('Created the plan', 'plan_master', 1, ['action' => 'insert', 'new' => ['plan_code' => 'BASIC']], null);
        $this->log('Changed the plan', 'plan_master', 1, ['action' => 'update', 'changes' => ['plan_code' => ['old' => 'BASIC', 'new' => 'PLUS']]], $this->staff['id']);

        $data = $this->data([]);
        $this->assertSame(2, $data['records_total']);
        $this->assertSame(2, $data['records_filtered']);
        $this->assertSame('Changed the plan', $data['rows'][0]['activity_summary']);
        $this->assertSame('account', $data['rows'][0]['actor']);
        $this->assertSame('Otter Tester', $data['rows'][0]['actor_name']);
        $this->assertSame('exec@otternova.com', $data['rows'][0]['actor_email']);
        $this->assertSame(['old' => 'BASIC', 'new' => 'PLUS'], $data['rows'][0]['activity_detail']['changes']['plan_code']);
        $this->assertSame('system', $data['rows'][1]['actor']);
        $this->assertNull($data['rows'][1]['actor_email']);
    }

    public function testFiltersByTableAndRecord(): void
    {
        $this->log('Plan 1', 'plan_master', 1, null, null);
        $this->log('Plan 2', 'plan_master', 2, null, null);
        $this->log('Method 1', 'payment_method', 1, null, null);

        $this->assertSame(['Plan 2', 'Plan 1'], $this->summaries(['filters' => ['table_involved' => 'plan_master']]));
        $this->assertSame(['Plan 1'], $this->summaries(['filters' => ['table_involved' => 'plan_master', 'table_id_updated' => '1']]));
        $this->assertSame(['Method 1'], $this->summaries(['filters' => ['table_involved' => 'payment_method', 'table_id_updated' => 1]]));

        $tables = $this->send('v1/staff/logs/tables', []);
        $tables->assertStatus(200);
        $this->assertSame(['payment_method', 'plan_master'], $this->body($tables)['data']['tables']);
    }

    public function testSearchesOnlyWithinOneRecord(): void
    {
        $this->log('Created the plan', 'plan_master', 1, ['action' => 'insert', 'new' => ['plan_code' => 'BASIC']], null);
        $this->log('Changed the plan', 'plan_master', 1, ['action' => 'update', 'changes' => ['plan_code' => ['old' => 'BASIC', 'new' => 'PLUS']]], $this->staff['id']);
        $this->log('Created the plan', 'plan_master', 2, ['action' => 'insert', 'new' => ['plan_code' => 'PLUS']], null);

        // the changes, the summary and the actor
        $one = ['table_involved' => 'plan_master', 'table_id_updated' => 1];
        $this->assertSame(['Changed the plan'], $this->summaries(['filters' => $one, 'search' => 'PLUS']));
        $this->assertSame(['Created the plan'], $this->summaries(['filters' => $one, 'search' => 'created']));
        $this->assertSame(['Changed the plan'], $this->summaries(['filters' => $one, 'search' => 'exec@']));
        $data = $this->data(['filters' => $one, 'search' => 'PLUS']);
        $this->assertSame([2, 1], [$data['records_total'], $data['records_filtered']]);

        // ignored without one record
        $this->assertCount(3, $this->summaries(['search' => 'PLUS']));
        $this->assertCount(3, $this->summaries(['filters' => ['table_involved' => 'plan_master'], 'search' => 'PLUS']));
    }

    public function testFiltersAreChecked(): void
    {
        $this->send('v1/staff/logs/activities', ['filters' => ['table_id_updated' => 1]])->assertStatus(422);
        $this->send('v1/staff/logs/activities', ['filters' => ['table_involved' => 'plan_master', 'table_id_updated' => 'x']])->assertStatus(422);
        $this->send('v1/staff/logs/activities', ['filters' => ['table_involved' => 'plan_master', 'table_id_updated' => 0]])->assertStatus(422);
        $this->send('v1/staff/logs/activities', ['filters' => ['table_involved' => 'plan master; --']])->assertStatus(422);
    }

    // ---- helpers ----

    private function log(string $summary, string $table, int $rowId, ?array $detail, ?int $authId): void
    {
        $this->db->table('log_activity')->insert([
            'activity_summary' => $summary,
            'table_involved'   => $table,
            'table_id_updated' => $rowId,
            'activity_detail'  => $detail === null ? null : json_encode($detail),
            'changed_by_acc'   => $authId,
        ]);
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

    private function createStaff(string $email, string $department): array
    {
        $account = $this->createAccount($email, 'S');
        $this->db->table('staff_master')->insert([
            'auth_id'               => $account['id'],
            'department_id'         => $this->departments[$department],
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
        $response = $this->send('v1/staff/logs/activities', $body);
        $response->assertStatus(200);

        return $this->body($response)['data'];
    }

    private function summaries(array $body): array
    {
        return array_column($this->data($body)['rows'], 'activity_summary');
    }
}
