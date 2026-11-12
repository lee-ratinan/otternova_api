<?php

use App\Models\AuthAccountModel;
use CodeIgniter\Config\Factories;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestLogger;
use CodeIgniter\Test\TestResponse;
use Config\Services;

/**
 * POST /staff/accounts, the Admin app's account list
 *
 * @internal
 */
final class AccountsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'App'; // the app's own migrations, the schema of the real database

    /** The signed-in staff account calling the endpoint; it is in the list too */
    private array $staff;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['jwt', 'authContext'] as $service) {
            Services::resetSingle($service);
        }

        $this->staff = $this->createStaff('admin@otternova.com', ['full_name' => 'Ada Admin']);
        $this->token = service('jwt')->issue($this->staff)['access_token'];
    }

    // ---- auth ----

    public function testMissingOrInvalidTokenIsRejected(): void
    {
        foreach ([$this->post('v1/staff/accounts'), $this->accounts([], 'not-a-jwt')] as $response) {
            $response->assertStatus(401);
            $this->assertSame('error', json_decode($response->getJSON(), true)['status']);
        }
    }

    public function testClientTokenIsForbidden(): void
    {
        $client = $this->createClient('client@example.com');

        $response = $this->accounts([], service('jwt')->issue($client)['access_token']);

        $response->assertStatus(403);
        $this->assertSame(['status' => 'error', 'message' => lang('Auth.wrongAccountType')], json_decode($response->getJSON(), true));
    }

    public function testRevokedTokenIsRejected(): void
    {
        $this->withHeaders(['Authorization' => 'Bearer ' . $this->token])->post('v1/logout')->assertStatus(200);

        $this->accounts([])->assertStatus(401);
    }

    // ---- paging and order ----

    public function testDefaultsToFirst25ByEmail(): void
    {
        $this->createMany(30); // user00@ ... user29@, plus admin@

        $data = $this->list(null);

        $this->assertSame(31, $data['records_total']);
        $this->assertSame(31, $data['records_filtered']);
        $this->assertCount(25, $data['rows']);
        $emails = array_column($data['rows'], 'email_address');
        $this->assertSame('admin@otternova.com', $emails[0]);
        $this->assertSame('user00@example.com', $emails[1]);
        $this->assertSame('user23@example.com', $emails[24]);
    }

    public function testStartAndLengthPage(): void
    {
        $this->createMany(30);

        $data = $this->list(['start' => 28, 'length' => 10]);

        $this->assertSame(31, $data['records_filtered']);
        $this->assertSame(['user27@example.com', 'user28@example.com', 'user29@example.com'], array_column($data['rows'], 'email_address'));
    }

    public function testOrderIsAppliedInTurnWithIdAsTiebreaker(): void
    {
        $a = $this->createAccount('a@example.com', ['full_name' => 'Same Name', 'account_status' => 'P']);
        $b = $this->createAccount('b@example.com', ['full_name' => 'Same Name', 'account_status' => 'P']);
        $c = $this->createAccount('c@example.com', ['full_name' => 'Same Name', 'account_status' => 'B']);

        $data = $this->list(['order' => [['field' => 'account_status', 'dir' => 'desc'], ['field' => 'full_name', 'dir' => 'ASC']]]);

        // P before B before A (the admin); a and b tie on both keys, so by id
        $this->assertSame([$a['uid'], $b['uid'], $c['uid'], $this->staff['uid']], array_column($data['rows'], 'uid'));
    }

    public function testDescendingOrder(): void
    {
        $this->createMany(3);

        $data = $this->list(['order' => [['field' => 'email_address', 'dir' => 'desc']]]);

        $this->assertSame('user02@example.com', $data['rows'][0]['email_address']);
        $this->assertSame('admin@otternova.com', end($data['rows'])['email_address']);
    }

    public function testInvalidOrderFallsBackToEmailAscending(): void
    {
        $this->createAccount('zed@example.com', ['full_name' => 'Aaron First']);
        $expected = ['admin@otternova.com', 'zed@example.com'];

        foreach ([
            [['field' => 'password_hash', 'dir' => 'asc']],
            [['field' => 'full_name; DROP TABLE auth_master', 'dir' => 'asc']],
            [['field' => 'id', 'dir' => 'desc']],
            [['field' => 'admin_notes']],
            ['full_name'],
            'full_name',
        ] as $order) {
            $data = $this->list(['order' => $order]);
            $this->assertSame($expected, array_column($data['rows'], 'email_address'), json_encode($order));
        }

        // an invalid dir is ascending
        $data = $this->list(['order' => [['field' => 'full_name', 'dir' => 'sideways']]]);
        $this->assertSame(['zed@example.com', 'admin@otternova.com'], array_column($data['rows'], 'email_address'));
    }

    public function testLengthIsClamped(): void
    {
        $this->createMany(510);

        $this->assertCount(500, $this->list(['length' => 1000])['rows']);
        $this->assertCount(1, $this->list(['length' => 0])['rows']);
        $this->assertCount(1, $this->list(['length' => -1])['rows']);
        $this->assertCount(25, $this->list(['length' => 'lots'])['rows']);
        $this->assertCount(10, $this->list(['length' => '10'])['rows']);
        // a negative start is the first page
        $this->assertSame('admin@otternova.com', $this->list(['start' => -5, 'length' => 1])['rows'][0]['email_address']);
    }

    // ---- filters ----

    public function testEachFilterMatchesExactly(): void
    {
        $this->createAccount('email@example.com', ['otp_mode' => 'E', 'account_status' => 'P', 'account_type' => 'C']);
        $this->createAccount('tel@example.com', ['otp_mode' => 'T', 'account_status' => 'B', 'account_type' => 'C']);
        $this->createAccount('none@example.com', ['otp_mode' => 'X', 'account_status' => 'S', 'account_type' => 'S']);
        // the admin: otp_mode X, status A, type S

        $cases = [
            [['otp_mode' => 'E'], ['email@example.com']],
            [['otp_mode' => 'T'], ['tel@example.com']],
            [['otp_mode' => 'X'], ['admin@otternova.com', 'none@example.com']],
            [['account_status' => 'A'], ['admin@otternova.com']],
            [['account_status' => 'P'], ['email@example.com']],
            [['account_status' => 'B'], ['tel@example.com']],
            [['account_status' => 'S'], ['none@example.com']],
            [['account_type' => 'S'], ['admin@otternova.com', 'none@example.com']],
            [['account_type' => 'C'], ['email@example.com', 'tel@example.com']],
            [['otp_mode' => 'X', 'account_type' => 'S', 'account_status' => 'S'], ['none@example.com']],
        ];
        foreach ($cases as [$filters, $expected]) {
            $data = $this->list(['filters' => $filters]);
            $this->assertSame($expected, array_column($data['rows'], 'email_address'), json_encode($filters));
            $this->assertSame(count($expected), $data['records_filtered']);
            $this->assertSame(4, $data['records_total']);
        }
    }

    public function testUnknownFiltersAndValuesAreIgnored(): void
    {
        $this->createAccount('client@example.com', ['account_type' => 'C']);

        foreach ([
            ['account_type' => 'Z'],
            ['account_type' => 's'],
            ['account_type' => ['S']],
            ['admin_notes' => 'internal note'],
            ['1 = 1 OR account_type' => 'S'],
            'account_type=S',
        ] as $filters) {
            $this->assertSame(2, $this->list(['filters' => $filters])['records_filtered'], json_encode($filters));
        }
    }

    // ---- search ----

    public function testSearchLooksInEmailTelephoneAndName(): void
    {
        $this->createAccount('harry.potter@hogwarts.edu', ['full_name' => 'Harry Potter', 'telephone_number' => '+447911123456']);
        $this->createAccount('ron@hogwarts.edu', ['full_name' => 'Ronald Weasley', 'telephone_number' => '+447700900111']);
        $this->createAccount('ginny@burrow.com', ['full_name' => 'Ginevra Weasley', 'password_expiry' => '2031-07-31']);
        $this->createAccount('o_brien!@example.com', ['full_name' => "Miles O'Brien 100%"]);

        $cases = [
            ['HOGWARTS', ['harry.potter@hogwarts.edu', 'ron@hogwarts.edu']], // email, any case
            ['+44791', ['harry.potter@hogwarts.edu']],                       // telephone
            [7911, ['harry.potter@hogwarts.edu']],                           // as a JSON number
            ['weasley', ['ginny@burrow.com', 'ron@hogwarts.edu']],           // full name
            ['  harry  ', ['harry.potter@hogwarts.edu']],                    // trimmed
            ['2031', []],                                                    // password_expiry is not searched
            ['internal', []],                                                // nor admin_notes
            ['%', ['o_brien!@example.com']],                                 // wildcards and ! are literal
            ['_', ['o_brien!@example.com']],
            ['!', ['o_brien!@example.com']],
            ['ry%', []],
            ["o'brien", ['o_brien!@example.com']],                           // quotes
        ];
        foreach ($cases as [$search, $expected]) {
            $data = $this->list(['search' => $search]);
            $this->assertSame($expected, array_column($data['rows'], 'email_address'), "search '{$search}'");
            $this->assertSame(count($expected), $data['records_filtered']);
            $this->assertSame(5, $data['records_total']);
        }

        // empty or not a string: no search
        $this->assertSame(5, $this->list(['search' => '   '])['records_filtered']);
        $this->assertSame(5, $this->list(['search' => ['harry']])['records_filtered']);
    }

    public function testSearchAndsWithFilters(): void
    {
        $this->createAccount('harry@hogwarts.edu', ['full_name' => 'Harry Potter', 'account_type' => 'C']);
        $this->createAccount('hermione@hogwarts.edu', ['full_name' => 'Hermione Granger', 'account_type' => 'S']);

        $data = $this->list(['search' => 'hogwarts', 'filters' => ['account_type' => 'C']]);

        $this->assertSame(['harry@hogwarts.edu'], array_column($data['rows'], 'email_address'));
        $this->assertSame(1, $data['records_filtered']);
        $this->assertSame(3, $data['records_total']);
    }

    // ---- output ----

    public function testRowsHoldOnlyTheListFieldsAsRawValues(): void
    {
        $account = $this->createAccount('harry.potter@hogwarts.edu', [
            'full_name'        => 'Harry Potter',
            'telephone_number' => '+447911123456',
            'password_expiry'  => '2027-11-15',
            'otp_mode'         => 'E',
            'account_type'     => 'C',
        ]);
        $hash = $this->db->table('auth_master')->where('id', $account['id'])->get()->getRowArray()['password_hash'];

        $response = $this->accounts(['search' => 'harry']);
        $data     = $this->json($response);

        $this->assertSame([[
            'uid'              => 'harrypotterhogwartsedu',
            'email_address'    => 'harry.potter@hogwarts.edu',
            'telephone_number' => '+447911123456',
            'password_expiry'  => '2027-11-15',
            'full_name'        => 'Harry Potter',
            'otp_mode'         => 'E',
            'account_status'   => 'A',
            'account_type'     => 'C',
        ]], $data['rows']);
        $this->assertNull($this->list(['search' => 'admin@'])['rows'][0]['telephone_number']);

        $body = $response->getJSON();
        $this->assertStringNotContainsString('password_hash', $body);
        $this->assertStringNotContainsString('admin_notes', $body);
        $this->assertStringNotContainsString('internal note', $body);
        $this->assertStringNotContainsString(json_encode($hash), $body);
        $this->assertStringNotContainsString('"id"', $body);
    }

    // ---- errors ----

    public function testMalformedBodyIsUnprocessable(): void
    {
        foreach (['{not json', '"a string"', '[1, 2]', '42'] as $body) {
            $response = $this->withHeaders(['Authorization' => 'Bearer ' . $this->token, 'Content-Type' => 'application/json'])
                ->withBody($body)
                ->post('v1/staff/accounts');

            $response->assertStatus(422);
            $this->assertSame(['status' => 'error', 'message' => lang('Accounts.invalidRequest')], json_decode($response->getJSON(), true), $body);
        }
    }

    public function testDatabaseFailureIsA500AndLogged(): void
    {
        Factories::injectMock('models', AuthAccountModel::class, new class () extends AuthAccountModel {
            public function listAccounts(int $start, int $length, string $search, array $filters, array $order): array
            {
                throw new DatabaseException('no such table: auth_master_secret_detail');
            }
        });

        try {
            $response = $this->accounts([]);
        } finally {
            Factories::reset('models');
        }

        $response->assertStatus(500);
        $this->assertSame(['status' => 'error', 'message' => lang('Accounts.listFailed')], json_decode($response->getJSON(), true));
        $this->assertStringNotContainsString('auth_master_secret_detail', $response->getJSON());
        $this->assertTrue($this->logged('error', 'v1/staff/accounts: no such table: auth_master_secret_detail'));
    }

    // ---- helpers ----

    private function createAccount(string $email, array $overrides = []): array
    {
        $account = $overrides + [
            'uid'              => preg_replace('/[^a-z0-9]/', '', $email),
            'email_address'    => $email,
            'telephone_number' => null,
            'password_hash'    => '$2y$12$dOj6FCfe3Bh1RnqoU9Mb1uV2cbYYofQOtT3A1bJE8H2HP2cXIiOV.',
            'password_expiry'  => '2027-01-01',
            'full_name'        => 'Otter Tester',
            'otp_mode'         => 'X',
            'account_status'   => 'A',
            'account_type'     => 'C',
            'admin_notes'      => 'internal note',
        ];
        $this->db->table('auth_master')->insert($account + ['public_id' => bin2hex(random_bytes(6))]);
        $account['id'] = $this->db->insertID();

        return $account;
    }

    private function createStaff(string $email, array $overrides = []): array
    {
        $account = $this->createAccount($email, $overrides + ['account_type' => 'S']);
        if ($this->db->table('otternova_department')->countAllResults() === 0) {
            $this->db->table('otternova_department')->insert(['department_code' => 'ENG', 'department_name' => 'Engineering', 'country_code' => 'TH']);
        }
        $this->db->table('staff_master')->insert([
            'auth_id'               => $account['id'],
            'department_id'         => 1,
            'employee_code'         => 'EMP-' . $account['id'],
            'job_title'             => 'Engineer',
            'employment_status'     => 'A',
            'employment_start_date' => '2024-01-01',
            'work_country_code'     => 'TH',
            'legal_first_name'      => 'Otter',
            'legal_last_name'       => 'Tester',
        ]);

        return $account;
    }

    private function createClient(string $email, array $overrides = []): array
    {
        $account = $this->createAccount($email, $overrides + ['account_type' => 'C']);
        $this->db->table('client_master')->insert(['auth_id' => $account['id']]);

        return $account;
    }

    /**
     * user00@example.com, user01@example.com, ... in one insert
     */
    private function createMany(int $count): void
    {
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $email  = sprintf('user%02d@example.com', $i);
            $rows[] = [
                'public_id'      => bin2hex(random_bytes(6)),
                'uid'            => preg_replace('/[^a-z0-9]/', '', $email),
                'email_address'  => $email,
                'full_name'      => 'User ' . $i,
                'account_status' => 'A',
                'account_type'   => 'C',
            ];
        }
        $this->db->table('auth_master')->insertBatch($rows);
    }

    /**
     * @param array|null $body JSON body; null sends none
     */
    private function accounts(?array $body, ?string $token = null): TestResponse
    {
        $request = $this->withHeaders(['Authorization' => 'Bearer ' . ($token ?? $this->token)]);
        if ($body !== null) {
            $request = $request->withBodyFormat('json');
        }

        return $request->post('v1/staff/accounts', $body ?? []);
    }

    /**
     * The data of a 200 list response
     */
    private function list(?array $body): array
    {
        return $this->json($this->accounts($body));
    }

    private function json(TestResponse $response): array
    {
        $response->assertStatus(200);
        $body = json_decode($response->getJSON(), true);
        $this->assertSame('success', $body['status']);
        $this->assertSame(['records_total', 'records_filtered', 'rows'], array_keys($body['data']));

        return $body['data'];
    }

    private function logged(string $level, string $message): bool
    {
        foreach ((new ReflectionProperty(TestLogger::class, 'op_logs'))->getValue() as $log) {
            if ($log['level'] === $level && $log['message'] === $message) {
                return true;
            }
        }

        return false;
    }
}
