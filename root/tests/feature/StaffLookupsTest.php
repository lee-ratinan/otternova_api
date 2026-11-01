<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\Services;

/**
 * POST /staff/departments and /staff/staff-members, the lookups behind the account form's Department and
 * Line Manager dropdowns
 *
 * @internal
 */
final class StaffLookupsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'App'; // the app's own migrations, the schema of the real database

    private const PATHS = ['v1/staff/departments', 'v1/staff/staff-members'];

    /** The signed-in staff account calling the endpoints */
    private array $staff;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['jwt', 'authContext'] as $service) {
            Services::resetSingle($service);
        }

        $this->db->table('otternova_department')->insert(['department_code' => 'OPS', 'department_name' => 'Operations', 'country_code' => 'SG']);
        $this->staff = $this->createStaff('admin@otternova.com', ['full_name' => 'Ada Admin']);
        $this->token = service('jwt')->issue($this->staff)['access_token'];
    }

    // ---- auth ----

    public function testEveryLookupNeedsAStaffToken(): void
    {
        $client = $this->createAccount('client@example.com', ['account_type' => 'C']);
        $this->db->table('client_master')->insert(['auth_id' => $client['id']]);
        $clientToken = service('jwt')->issue($client)['access_token'];

        foreach (self::PATHS as $path) {
            $this->withHeaders([])->post($path)->assertStatus(401);
            $this->send($path, [], 'not-a-jwt')->assertStatus(401);

            $response = $this->send($path, [], $clientToken);
            $response->assertStatus(403);
            $this->assertSame(['status' => 'error', 'message' => lang('Auth.wrongAccountType')], $this->body($response));
        }
    }

    public function testAnEmptyBodyIsAccepted(): void
    {
        foreach (self::PATHS as $path) {
            $this->withHeaders(['Authorization' => 'Bearer ' . $this->token])->post($path)->assertStatus(200);
        }
    }

    // ---- departments ----

    public function testDepartmentsAreOrderedByName(): void
    {
        $this->db->table('otternova_department')->insertBatch([
            ['department_code' => 'ENG', 'department_name' => 'Engineering', 'country_code' => 'TH'],
            ['department_code' => 'FIN', 'department_name' => 'Finance', 'country_code' => 'TH'],
            ['department_code' => 'ACC', 'department_name' => 'Accounting', 'country_code' => 'SG'],
        ]);

        $response = $this->send('v1/staff/departments', []);

        $response->assertStatus(200);
        $this->assertSame(['status' => 'success', 'data' => ['rows' => [
            ['id' => 4, 'department_code' => 'ACC', 'department_name' => 'Accounting', 'country_code' => 'SG'],
            ['id' => 2, 'department_code' => 'ENG', 'department_name' => 'Engineering', 'country_code' => 'TH'],
            ['id' => 3, 'department_code' => 'FIN', 'department_name' => 'Finance', 'country_code' => 'TH'],
            ['id' => 1, 'department_code' => 'OPS', 'department_name' => 'Operations', 'country_code' => 'SG'],
        ]]], $this->body($response));
    }

    // ---- staff members ----

    public function testStaffMembersAreOrderedByNameThenId(): void
    {
        $this->createStaff('zed@otternova.com', ['full_name' => 'Zed']);
        $this->createStaff('fauzi2@otternova.com', ['full_name' => 'Fauzi']);
        $this->createStaff('fauzi1@otternova.com', ['full_name' => 'Fauzi']);

        $rows = $this->rows('v1/staff/staff-members');

        $this->assertSame(['Ada Admin', 'Fauzi', 'Fauzi', 'Zed'], array_column($rows, 'full_name'));
        // the two Fauzis by staff_master.id: fauzi2 was inserted first
        $this->assertSame(['fauzi2otternovacom', 'fauzi1otternovacom'], [$rows[1]['uid'], $rows[2]['uid']]);
        $this->assertLessThan($rows[2]['id'], $rows[1]['id']);
    }

    public function testStaffMemberRowShape(): void
    {
        $fauzi   = $this->createStaff('fauzi@otternova.com', ['full_name' => 'Fauzi'], ['employee_code' => 'ON-0002', 'job_title' => 'Engineer']);
        $staffId = (int) $this->db->table('staff_master')->where('auth_id', $fauzi['id'])->get()->getRow()->id;

        $rows = $this->rows('v1/staff/staff-members');

        $this->assertSame([
            'id'             => $staffId,
            'uid'            => 'fauziotternovacom',
            'full_name'      => 'Fauzi',
            'email_address'  => 'fauzi@otternova.com',
            'employee_code'  => 'ON-0002',
            'job_title'      => 'Engineer',
            'department_id'  => 1,
            'account_status' => 'A',
        ], $rows[1]);
    }

    public function testStaffMembersLeaveOutClientsAndStaffWithoutAProfile(): void
    {
        $this->createAccount('noprofile@otternova.com', ['full_name' => 'No Profile']);
        $client = $this->createAccount('client@example.com', ['full_name' => 'Client', 'account_type' => 'C']);
        $this->db->table('client_master')->insert(['auth_id' => $client['id']]);
        // a staff_master row wrongly attached to a client account is not a staff member either
        $odd = $this->createAccount('odd@example.com', ['full_name' => 'Odd Client', 'account_type' => 'C']);
        $this->insertStaffMaster($odd['id']);

        $rows = $this->rows('v1/staff/staff-members');

        $this->assertSame(['adminotternovacom'], array_column($rows, 'uid'));
    }

    public function testStaffMembersIncludeEveryAccountStatus(): void
    {
        foreach (['P' => 'Pending', 'B' => 'Blocked', 'S' => 'Suspended'] as $status => $name) {
            $this->createStaff(strtolower($name) . '@otternova.com', ['full_name' => $name, 'account_status' => $status]);
        }

        $rows = $this->rows('v1/staff/staff-members');

        $this->assertSame(
            ['Ada Admin' => 'A', 'Blocked' => 'B', 'Pending' => 'P', 'Suspended' => 'S'],
            array_column($rows, 'account_status', 'full_name')
        );
    }

    public function testStaffMembersNeverIncludeSensitiveFields(): void
    {
        $secrets = [
            'admin_notes'        => 'ADMIN-NOTE-SECRET',
            'hr_notes'           => 'HR-NOTE-SECRET',
            'national_id_number' => 'NATID-SECRET',
            'tax_id_number'      => 'TAXID-SECRET',
        ];
        $this->createStaff('secret@otternova.com', ['admin_notes' => $secrets['admin_notes']], [
            'hr_notes'           => $secrets['hr_notes'],
            'national_id_type'   => 'N',
            'national_id_number' => $secrets['national_id_number'],
            'tax_id_number'      => $secrets['tax_id_number'],
        ]);
        $hash = $this->db->table('auth_master')->where('uid', 'secretotternovacom')->get()->getRow()->password_hash;

        $response = $this->send('v1/staff/staff-members', []);

        $response->assertStatus(200);
        $body = $response->getJSON();
        foreach (array_merge(array_keys($secrets), ['password_hash', 'national_id_type', 'auth_id']) as $field) {
            $this->assertStringNotContainsString($field, $body);
        }
        foreach (array_merge(array_values($secrets), [json_encode($hash)]) as $value) {
            $this->assertStringNotContainsString($value, $body);
        }
    }

    // ---- helpers ----

    private function createAccount(string $email, array $overrides = []): array
    {
        $account = $overrides + [
            'uid'              => preg_replace('/[^a-z0-9]/', '', $email),
            'email_address'    => $email,
            'telephone_number' => null,
            'password_hash'    => password_hash('correct horse battery staple', PASSWORD_DEFAULT),
            'password_expiry'  => '2027-01-01',
            'full_name'        => 'Otter Tester',
            'otp_mode'         => 'X',
            'account_status'   => 'A',
            'account_type'     => 'S',
            'admin_notes'      => 'internal note',
        ];
        $this->db->table('auth_master')->insert($account);
        $account['id'] = $this->db->insertID();

        return $account;
    }

    private function createStaff(string $email, array $overrides = [], array $profile = []): array
    {
        $account = $this->createAccount($email, $overrides + ['account_type' => 'S']);
        $this->insertStaffMaster($account['id'], $profile);

        return $account;
    }

    private function insertStaffMaster(int|string $authId, array $profile = []): void
    {
        $this->db->table('staff_master')->insert($profile + [
            'auth_id'               => $authId,
            'department_id'         => 1,
            'employee_code'         => 'EMP-' . $authId,
            'job_title'             => 'Engineer',
            'employment_status'     => 'A',
            'employment_start_date' => '2024-01-01',
            'work_country_code'     => 'TH',
            'legal_first_name'      => 'Otter',
            'legal_last_name'       => 'Tester',
        ]);
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

    /**
     * data.rows of a 200 success response
     */
    private function rows(string $path): array
    {
        $response = $this->send($path, []);
        $response->assertStatus(200);
        $body = $this->body($response);
        $this->assertSame('success', $body['status']);

        return $body['data']['rows'];
    }
}
