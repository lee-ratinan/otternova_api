<?php

use App\Database\Migrations\EncryptIdentityNumbers;
use App\Libraries\FieldEncryption;
use App\Models\AuthAccountModel;
use CodeIgniter\Config\Factories;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestLogger;
use CodeIgniter\Test\TestResponse;
use Config\Database;
use Config\Services;

/**
 * POST /staff/account, /staff/account/update and /staff/account/delete, the Admin app's account form
 *
 * @internal
 */
final class AccountFormTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'App'; // the app's own migrations, the schema of the real database

    /** The signed-in staff account calling the endpoints */
    private array $staff;
    private string $token;

    /** Every response of the test, checked for password_hash in tearDown() */
    private array $responses = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['jwt', 'authContext'] as $service) {
            Services::resetSingle($service);
        }
        $this->responses = [];

        $this->db->table('otternova_department')->insert(['department_code' => 'ENG', 'department_name' => 'Engineering', 'country_code' => 'TH']);
        $this->staff = $this->createStaff('admin@otternova.com', ['full_name' => 'Ada Admin']);
        $this->token = service('jwt')->issue($this->staff)['access_token'];
    }

    protected function tearDown(): void
    {
        // password_hash never appears in any response, as a key or as a value
        $hashes = $this->db->table('auth_master')->select('password_hash')->get()->getResultArray();
        foreach ($this->responses as $response) {
            $body = $response->getJSON() ?: '';
            $this->assertStringNotContainsString('password_hash', $body);
            foreach (array_filter(array_column($hashes, 'password_hash')) as $hash) {
                $this->assertStringNotContainsString(json_encode($hash), $body);
            }
        }

        parent::tearDown();
    }

    // ---- auth ----

    public function testEveryEndpointNeedsAStaffToken(): void
    {
        $client      = $this->createClient('client@example.com');
        $clientToken = service('jwt')->issue($client)['access_token'];

        foreach (['v1/staff/account', 'v1/staff/account/update', 'v1/staff/account/delete'] as $path) {
            $this->withHeaders([])->post($path)->assertStatus(401);
            $this->send($path, ['uid' => 'adminotternovacom'], 'not-a-jwt')->assertStatus(401);

            $response = $this->send($path, ['uid' => 'adminotternovacom'], $clientToken);
            $response->assertStatus(403);
            $this->assertSame(['status' => 'error', 'message' => lang('Auth.wrongAccountType')], $this->body($response));
        }
    }

    // ---- fetch ----

    public function testFetchStaffAccount(): void
    {
        $staff = $this->createStaff('eikin@otternova.com', [
            'full_name'        => '力川栄欽',
            'telephone_number' => '+6597754577',
            'password_expiry'  => '2027-11-15',
            'admin_notes'      => 'He is the CEO!',
        ], ['job_title' => 'CEO', 'avatar_path' => 'avatars/eikin.png']);

        $data = $this->data($this->fetch('eikinotternovacom'));

        $this->assertSame([
            'uid'              => 'eikinotternovacom',
            'email_address'    => 'eikin@otternova.com',
            'telephone_number' => '+6597754577',
            'password_expiry'  => '2027-11-15',
            'full_name'        => '力川栄欽',
            'otp_mode'         => 'X',
            'account_status'   => 'A',
            'account_type'     => 'S',
            'admin_notes'      => 'He is the CEO!',
        ], $data['account']);
        $this->assertSame(AuthAccountModel::FORM_PROFILES['S']['fields'], array_keys($data['profile']));
        $this->assertSame('CEO', $data['profile']['job_title']);
        $this->assertSame('EMP-' . $staff['id'], $data['profile']['employee_code']);
        foreach (['id', 'auth_id', 'avatar_path', 'created_at', 'updated_at'] as $hidden) {
            $this->assertArrayNotHasKey($hidden, $data['profile']);
        }
    }

    public function testFetchClientAccount(): void
    {
        $this->createClient('client@example.com', [], ['display_name' => 'Otter Fan', 'marketing_opt_in' => 1]);

        $data = $this->data($this->fetch('clientexamplecom'));

        $this->assertSame('C', $data['account']['account_type']);
        $this->assertSame(AuthAccountModel::FORM_PROFILES['C']['fields'], array_keys($data['profile']));
        $this->assertSame('Otter Fan', $data['profile']['display_name']);
    }

    public function testFetchAccountWithoutProfileGivesEmptyObject(): void
    {
        $this->createAccount('noprofile@example.com', ['account_type' => 'C']);

        $response = $this->fetch('noprofileexamplecom');

        $response->assertStatus(200);
        $this->assertEquals(new stdClass(), json_decode($response->getJSON())->data->profile);
    }

    public function testFetchUnknownUidIsNotFound(): void
    {
        $response = $this->fetch('nobodyexamplecom');

        $response->assertStatus(404);
        $this->assertSame(['status' => 'error', 'message' => 'Account not found.'], $this->body($response));
    }

    // ---- create ----

    public function testCreateStaffAccount(): void
    {
        $response = $this->save(null, $this->newAccount([
            'telephone_number' => '+65 9775-4577',
            'admin_notes'      => '',
            // none of these may be set by the caller
            'id'               => 999,
            'uid'              => 'chosenuid',
            'password_hash'    => 'not-a-hash',
            'created_at'       => '2000-01-01 00:00:00',
        ]), $this->newProfile([
            'work_country_code' => 'sg',
            'legal_middle_name' => '',
            'timezone'          => 'Asia/Singapore',
            'auth_id'           => $this->staff['id'],
            'avatar_path'       => 'evil.png',
            'id'                => 1,
        ]));

        $response->assertStatus(200);
        $this->assertSame(['status' => 'success', 'message' => 'Account created.', 'data' => ['uid' => 'eikinleeotternovacom']], $this->body($response));

        $account = $this->db->table('auth_master')->where('uid', 'eikinleeotternovacom')->get()->getRowArray();
        $this->assertNotSame(999, (int) $account['id']);
        $this->assertSame('Eikin.Lee@otternova.com', $account['email_address']);
        $this->assertSame('+6597754577', $account['telephone_number']);
        $this->assertNull($account['admin_notes']);
        $this->assertSame('S', $account['account_type']);
        $this->assertNotSame('not-a-hash', $account['password_hash']);
        $this->assertSame(PASSWORD_DEFAULT, password_get_info($account['password_hash'])['algo']);
        $this->assertNotSame('2000-01-01 00:00:00', $account['created_at']);

        $profile = $this->db->table('staff_master')->where('auth_id', $account['id'])->get()->getRowArray();
        $this->assertSame('EMP-NEW', $profile['employee_code']);
        $this->assertSame('SG', $profile['work_country_code']);
        $this->assertNull($profile['legal_middle_name']);
        $this->assertNull($profile['avatar_path']);
        $this->assertSame('F', $profile['employment_type']); // the column default
        $this->seeNumRecords(1, 'staff_master', ['auth_id' => $this->staff['id']]);
    }

    public function testCreateGeneratesUidFromEmail(): void
    {
        $response = $this->save(null, $this->newAccount(['email_address' => 'Mary-Jane.O+Work@Otter-Nova.com']), $this->newProfile());

        $this->assertSame('maryjaneoworkotternovacom', $this->data($response)['uid']);
        $this->seeInDatabase('auth_master', ['uid' => 'maryjaneoworkotternovacom', 'email_address' => 'Mary-Jane.O+Work@Otter-Nova.com']);
    }

    public function testCreateRejectsDuplicateEmailOrUid(): void
    {
        $this->createAccount('taken@otternova.com');

        // the same address, and a different address with the same uid
        foreach (['taken@otternova.com', 'ta.ken@otternova.com'] as $email) {
            $response = $this->save(null, $this->newAccount(['email_address' => $email]), $this->newProfile());

            $this->assertSame(['account.email_address'], array_keys($this->errors($response)), $email);
        }
        $this->seeNumRecords(2, 'auth_master', []);
    }

    public function testCreateOnlyAllowsStaff(): void
    {
        foreach (['C', null, 's'] as $type) {
            $account = $this->newAccount();
            if ($type === null) {
                unset($account['account_type']);
            } else {
                $account['account_type'] = $type;
            }

            $response = $this->save(null, $account, ['display_name' => 'Client']);

            $this->assertSame(['account.account_type' => lang('Accounts.staffOnly')], $this->errors($response), (string) $type);
        }
        $this->seeNumRecords(1, 'auth_master', []);
    }

    public function testCreateRollsBackWhenTheProfileInsertFails(): void
    {
        $table = $this->db->prefixTable('staff_master');
        $this->db->query("CREATE TRIGGER fail_staff_insert BEFORE INSERT ON {$table} BEGIN SELECT RAISE(ABORT, 'staff insert failed'); END");

        $response = $this->save(null, $this->newAccount(), $this->newProfile());

        $response->assertStatus(500);
        $this->assertSame(['status' => 'error', 'message' => lang('Accounts.saveFailed')], $this->body($response));
        $this->dontSeeInDatabase('auth_master', ['uid' => 'eikinleeotternovacom']);
        $this->seeNumRecords(1, 'auth_master', []);
        $this->assertTrue($this->logged('error', 'staff insert failed'));
    }

    public function testCreateValidatesEveryField(): void
    {
        $response = $this->save(null, $this->newAccount([
            'full_name'        => '',
            'telephone_number' => '12345',
            'password_expiry'  => '15/11/2027',
            'otp_mode'         => 'Z',
        ]), $this->newProfile([
            'department_id'         => 99,
            'manager_staff_id'      => 99,
            'job_title'             => '',
            'employment_start_date' => '2025-02-30',
            'work_country_code'     => 'ZZ',
            'nationality_code'      => 'THA',
            'timezone'              => 'Mars/Olympus',
            'work_email_address'    => 'not-an-email',
            'gender'                => 'Q',
        ]));

        $response->assertStatus(422);
        $this->assertSame('Please correct the highlighted fields.', $this->body($response)['message']);
        $errors = $this->errors($response);
        foreach ([
            'account.full_name', 'account.telephone_number', 'account.password_expiry', 'account.otp_mode',
            'profile.department_id', 'profile.manager_staff_id', 'profile.job_title', 'profile.employment_start_date',
            'profile.work_country_code', 'profile.nationality_code', 'profile.timezone', 'profile.work_email_address',
            'profile.gender',
        ] as $key) {
            $this->assertArrayHasKey($key, $errors);
        }
        $this->assertSame(lang('Accounts.invalidTelephone'), $errors['account.telephone_number']);
        $this->assertSame(lang('Accounts.invalidCountry'), $errors['profile.work_country_code']);
        $this->assertStringContainsString(lang('AuthMaster.fields.full_name'), $errors['account.full_name']);
        $this->seeNumRecords(1, 'auth_master', []);
    }

    // ---- update ----

    public function testUpdateIgnoresEmailAndAccountType(): void
    {
        $this->createStaff('eikin@otternova.com');

        $response = $this->save('eikinotternovacom', [
            'email_address'    => 'changed@otternova.com',
            'account_type'     => 'C',
            'full_name'        => 'Eikin Lee',
            'telephone_number' => '0065 9775 4577',
            'password_expiry'  => '',
        ], ['job_title' => 'CTO', 'manager_staff_id' => '1', 'display_name' => 'ignored for staff']);

        $response->assertStatus(200);
        $this->assertSame(['status' => 'success', 'message' => 'Account updated.', 'data' => ['uid' => 'eikinotternovacom']], $this->body($response));
        $account = $this->db->table('auth_master')->where('uid', 'eikinotternovacom')->get()->getRowArray();
        $this->assertSame('eikin@otternova.com', $account['email_address']);
        $this->assertSame('S', $account['account_type']);
        $this->assertSame('Eikin Lee', $account['full_name']);
        $this->assertSame('+6597754577', $account['telephone_number']);
        $this->assertNull($account['password_expiry']);
        $this->seeInDatabase('staff_master', ['auth_id' => $account['id'], 'job_title' => 'CTO', 'manager_staff_id' => 1]);
        $this->seeNumRecords(0, 'client_master', []);
    }

    public function testUpdatePicksProfileTableFromStoredType(): void
    {
        $client = $this->createAccount('client@example.com', ['account_type' => 'C']); // no profile row yet

        $response = $this->save('clientexamplecom', ['account_type' => 'S'], [
            'display_name'     => 'Otter Fan',
            'marketing_opt_in' => true,
            'nationality_code' => 'sg',
            'job_title'        => 'not a client field',
            'department_id'    => 1,
        ]);

        $response->assertStatus(200);
        $this->seeInDatabase('client_master', ['auth_id' => $client['id'], 'display_name' => 'Otter Fan', 'marketing_opt_in' => 1, 'nationality_code' => 'SG']);
        $this->seeNumRecords(0, 'staff_master', ['auth_id' => $client['id']]);
        $this->seeInDatabase('auth_master', ['id' => $client['id'], 'account_type' => 'C']);

        // and the next save updates that row instead of adding one
        $this->save('clientexamplecom', [], ['display_name' => 'Renamed'])->assertStatus(200);
        $this->seeNumRecords(1, 'client_master', ['auth_id' => $client['id']]);
        $this->seeInDatabase('client_master', ['auth_id' => $client['id'], 'display_name' => 'Renamed']);
    }

    public function testUpdateValidationErrorsArePrefixed(): void
    {
        $other = $this->createStaff('other@otternova.com');
        $own   = $this->db->table('staff_master')->where('auth_id', $other['id'])->get()->getRowArray()['id'];

        $response = $this->save('otherotternovacom', ['full_name' => '', 'otp_mode' => 'Q'], [
            'manager_staff_id' => $own,
            'employee_code'    => 'EMP-' . $this->staff['id'], // the admin's
            'legal_last_name'  => '',
        ]);

        $errors = $this->errors($response);
        $this->assertSame(['account.full_name', 'account.otp_mode', 'profile.employee_code', 'profile.manager_staff_id', 'profile.legal_last_name'], array_keys($errors));
        $this->assertSame(lang('Accounts.managerIsSelf'), $errors['profile.manager_staff_id']);
        $this->seeInDatabase('auth_master', ['id' => $other['id'], 'full_name' => 'Otter Tester']);
    }

    public function testUpdateOfUnknownUidIsNotFound(): void
    {
        $response = $this->save('nobodyexamplecom', ['full_name' => 'Nobody'], []);

        $response->assertStatus(404);
        $this->assertSame(['status' => 'error', 'message' => 'Account not found.'], $this->body($response));
        $this->seeNumRecords(1, 'auth_master', []);
    }

    public function testStaffCannotChangeTheirOwnStatus(): void
    {
        foreach (['S', 'B', 'P'] as $status) {
            $response = $this->save('adminotternovacom', ['account_status' => $status], []);

            $this->assertSame(['account.account_status' => lang('Accounts.ownStatus')], $this->errors($response));
        }
        $this->seeInDatabase('auth_master', ['id' => $this->staff['id'], 'account_status' => 'A']);

        // their own other fields, and the same status, are fine
        $this->save('adminotternovacom', ['account_status' => 'A', 'full_name' => 'Ada Lovelace'], [])->assertStatus(200);
        $this->seeInDatabase('auth_master', ['id' => $this->staff['id'], 'full_name' => 'Ada Lovelace']);

        // and another account's status too
        $this->createStaff('other@otternova.com');
        $this->save('otherotternovacom', ['account_status' => 'S'], [])->assertStatus(200);
        $this->seeInDatabase('auth_master', ['uid' => 'otherotternovacom', 'account_status' => 'S']);
    }

    public function testMalformedSaveIsUnprocessable(): void
    {
        $this->send('v1/staff/account/update', ['uid' => 42, 'account' => []])->assertStatus(422);
        $this->withHeaders(['Authorization' => 'Bearer ' . $this->token, 'Content-Type' => 'application/json'])
            ->withBody('{not json')
            ->post('v1/staff/account/update')
            ->assertStatus(422);
    }

    // ---- line manager and department ----

    public function testFetchReturnsManagerAndDepartment(): void
    {
        $boss = $this->createStaff('boss@otternova.com');
        $this->createStaff('fauzi@otternova.com', [], ['manager_staff_id' => $this->staffId($boss)]);

        $profile = $this->data($this->fetch('fauziotternovacom'))['profile'];

        $this->assertEquals($this->staffId($boss), $profile['manager_staff_id']);
        $this->assertEquals(1, $profile['department_id']);
        $this->assertNull($this->data($this->fetch('bossotternovacom'))['profile']['manager_staff_id']);
    }

    public function testUpdateSetsAValidManager(): void
    {
        $boss  = $this->createStaff('boss@otternova.com');
        $fauzi = $this->createStaff('fauzi@otternova.com');

        $this->save('fauziotternovacom', [], ['manager_staff_id' => $this->staffId($boss)])->assertStatus(200);

        $this->seeInDatabase('staff_master', ['auth_id' => $fauzi['id'], 'manager_staff_id' => $this->staffId($boss)]);
    }

    public function testUpdateClearsTheManager(): void
    {
        $boss = $this->createStaff('boss@otternova.com');
        foreach (['blank' => '', 'null' => null] as $name => $empty) {
            $fauzi = $this->createStaff($name . '@otternova.com', [], ['manager_staff_id' => $this->staffId($boss)]);

            $this->save($fauzi['uid'], [], ['manager_staff_id' => $empty])->assertStatus(200);

            $this->seeInDatabase('staff_master', ['auth_id' => $fauzi['id'], 'manager_staff_id' => null]);
        }
    }

    public function testManagerMustBeAnExistingStaffMember(): void
    {
        $this->createStaff('fauzi@otternova.com');
        // a staff_master row on a client account does not count as staff
        $client = $this->createAccount('odd@example.com', ['account_type' => 'C']);
        $this->db->table('staff_master')->insert([
            'auth_id' => $client['id'], 'department_id' => 1, 'employee_code' => 'ODD', 'job_title' => 'Odd',
            'employment_start_date' => '2024-01-01', 'work_country_code' => 'TH', 'legal_first_name' => 'Odd', 'legal_last_name' => 'Client',
        ]);
        $oddId = (int) $this->db->insertID();

        foreach ([999, $oddId] as $managerId) {
            $errors = $this->errors($this->save('fauziotternovacom', [], ['manager_staff_id' => $managerId]));

            $this->assertSame(['profile.manager_staff_id' => lang('Accounts.managerNotFound')], $errors, (string) $managerId);
        }
        // on create too, where only existence is checked
        $errors = $this->errors($this->save(null, $this->newAccount(), $this->newProfile(['manager_staff_id' => 999])));
        $this->assertSame(['profile.manager_staff_id' => lang('Accounts.managerNotFound')], $errors);
    }

    public function testCreateWithAManager(): void
    {
        $this->save(null, $this->newAccount(), $this->newProfile(['manager_staff_id' => $this->staffId($this->staff)]))->assertStatus(200);

        $this->seeInDatabase('staff_master', ['employee_code' => 'EMP-NEW', 'manager_staff_id' => $this->staffId($this->staff)]);
    }

    public function testStaffCannotBeTheirOwnManager(): void
    {
        $fauzi = $this->createStaff('fauzi@otternova.com');

        $errors = $this->errors($this->save('fauziotternovacom', [], ['manager_staff_id' => $this->staffId($fauzi)]));

        $this->assertSame(['profile.manager_staff_id' => lang('Accounts.managerIsSelf')], $errors);
        $this->seeInDatabase('staff_master', ['auth_id' => $fauzi['id'], 'manager_staff_id' => null]);
    }

    public function testManagerCannotCreateAReportingLoop(): void
    {
        $a = $this->createStaff('a@otternova.com');
        $b = $this->createStaff('b@otternova.com');
        $c = $this->createStaff('c@otternova.com');

        // A reports to B; B reporting to A would close the loop
        $this->save('aotternovacom', [], ['manager_staff_id' => $this->staffId($b)])->assertStatus(200);
        $errors = $this->errors($this->save('botternovacom', [], ['manager_staff_id' => $this->staffId($a)]));
        $this->assertSame(['profile.manager_staff_id' => lang('Accounts.managerLoop')], $errors);
        $this->seeInDatabase('staff_master', ['auth_id' => $b['id'], 'manager_staff_id' => null]);

        // and further up: A -> B -> C, so C cannot report to A
        $this->save('botternovacom', [], ['manager_staff_id' => $this->staffId($c)])->assertStatus(200);
        $errors = $this->errors($this->save('cotternovacom', [], ['manager_staff_id' => $this->staffId($a)]));
        $this->assertSame(['profile.manager_staff_id' => lang('Accounts.managerLoop')], $errors);

        // reporting to someone outside the line is fine
        $this->save('cotternovacom', [], ['manager_staff_id' => $this->staffId($this->staff)])->assertStatus(200);
    }

    public function testLoopWalkStopsAtALoopAlreadyInTheData(): void
    {
        $x = $this->createStaff('x@otternova.com');
        $y = $this->createStaff('y@otternova.com');
        $this->createStaff('fauzi@otternova.com');
        // X and Y already report to each other, written straight to the table
        $this->db->table('staff_master')->where('auth_id', $x['id'])->update(['manager_staff_id' => $this->staffId($y)]);
        $this->db->table('staff_master')->where('auth_id', $y['id'])->update(['manager_staff_id' => $this->staffId($x)]);

        $this->save('fauziotternovacom', [], ['manager_staff_id' => $this->staffId($x)])->assertStatus(200);
    }

    public function testDepartmentIsRequiredAndMustExist(): void
    {
        $this->createStaff('fauzi@otternova.com');

        foreach (['', null, 999, 'abc'] as $department) {
            $errors = $this->errors($this->save('fauziotternovacom', [], ['department_id' => $department]));
            $this->assertSame(['profile.department_id'], array_keys($errors), var_export($department, true));
        }
        $errors = $this->errors($this->save('fauziotternovacom', [], ['department_id' => 999]));
        $this->assertSame(lang('Accounts.departmentNotFound'), $errors['profile.department_id']);

        // on create, leaving it out is an error too
        $profile = $this->newProfile();
        unset($profile['department_id']);
        foreach ([$profile, $this->newProfile(['department_id' => 999])] as $profileIn) {
            $errors = $this->errors($this->save(null, $this->newAccount(), $profileIn));
            $this->assertSame(['profile.department_id'], array_keys($errors));
        }
        $this->seeNumRecords(2, 'auth_master', []);
    }

    // ---- delete ----

    public function testDeleteRemovesAccountAndProfileAndEndsItsSessions(): void
    {
        $other      = $this->createStaff('other@otternova.com');
        $otherToken = service('jwt')->issue($other)['access_token'];
        $this->send('v1/staff/accounts', [], $otherToken)->assertStatus(200);

        $response = $this->send('v1/staff/account/delete', ['uid' => 'otherotternovacom']);

        $response->assertStatus(200);
        $this->assertSame(['status' => 'success', 'message' => 'Account deleted.'], $this->body($response));
        $this->dontSeeInDatabase('auth_master', ['id' => $other['id']]);
        $this->dontSeeInDatabase('staff_master', ['auth_id' => $other['id']]);
        $this->send('v1/staff/accounts', [], $otherToken)->assertStatus(401);
    }

    public function testDeleteClientAccount(): void
    {
        $client = $this->createClient('client@example.com');

        $this->send('v1/staff/account/delete', ['uid' => 'clientexamplecom'])->assertStatus(200);

        $this->dontSeeInDatabase('auth_master', ['id' => $client['id']]);
        $this->dontSeeInDatabase('client_master', ['auth_id' => $client['id']]);
    }

    public function testStaffCannotDeleteThemselves(): void
    {
        $response = $this->send('v1/staff/account/delete', ['uid' => 'adminotternovacom']);

        $response->assertStatus(403);
        $this->assertSame(['status' => 'error', 'message' => lang('Accounts.cannotDeleteSelf')], $this->body($response));
        $this->seeInDatabase('auth_master', ['id' => $this->staff['id']]);
    }

    public function testLastActiveStaffCannotBeDeleted(): void
    {
        // the caller must be active staff to get past the filter, so this only happens when the caller is
        // suspended between that check and the delete (by another admin, say)
        $this->createStaff('other@otternova.com');
        Factories::injectMock('models', AuthAccountModel::class, new class () extends AuthAccountModel {
            public function findForSessionByUid(string $uid, string $accountType): ?array
            {
                $account = parent::findForSessionByUid($uid, $accountType);
                $this->db->table($this->table)->where('uid', $uid)->update(['account_status' => self::STATUS_SUSPENDED]);

                return $account;
            }
        });

        try {
            $response = $this->send('v1/staff/account/delete', ['uid' => 'otherotternovacom']);
        } finally {
            Factories::reset('models');
        }

        $response->assertStatus(409);
        $this->assertSame(['status' => 'error', 'message' => lang('Accounts.lastActiveStaff')], $this->body($response));
        $this->seeInDatabase('auth_master', ['uid' => 'otherotternovacom']);
        $this->seeInDatabase('staff_master', ['employee_code' => 'EMP-' . ($this->staff['id'] + 1)]);
    }

    public function testDeleteOfUnknownUidIsNotFound(): void
    {
        $response = $this->send('v1/staff/account/delete', ['uid' => 'nobodyexamplecom']);

        $response->assertStatus(404);
        $this->assertSame(['status' => 'error', 'message' => 'Account not found.'], $this->body($response));
    }

    // ---- log_activity ----

    public function testCreateIsLoggedWithoutSecrets(): void
    {
        $this->save(null, $this->newAccount(), $this->newProfile(['national_id_number' => 'S1234567A']))->assertStatus(200);
        $account = $this->db->table('auth_master')->where('uid', 'eikinleeotternovacom')->get()->getRowArray();
        $profile = $this->db->table('staff_master')->where('auth_id', $account['id'])->get()->getRowArray();

        $logs = $this->activity();
        $this->assertSame([
            ['Created account eikinleeotternovacom', 'auth_master', (int) $account['id']],
            ['Created staff profile of eikinleeotternovacom', 'staff_master', (int) $profile['id']],
        ], array_map(static fn ($log) => [$log['activity_summary'], $log['table_involved'], (int) $log['table_id_updated']], $logs));
        foreach ($logs as $log) {
            $this->assertSame($this->staff['id'], (int) $log['changed_by_acc']);
            $this->assertNull($log['changed_by_cust']);
        }

        $detail = $logs[0]['detail'];
        $this->assertSame('insert', $detail['action']);
        $this->assertSame('Eikin.Lee@otternova.com', $detail['new']['email_address']);
        $this->assertArrayNotHasKey('password_hash', $detail['new']);
        $this->assertStringNotContainsString($account['password_hash'], $logs[0]['activity_detail']);
        $this->assertSame('***', $logs[1]['detail']['new']['national_id_number']);
        $this->assertStringNotContainsString('S1234567A', $logs[1]['activity_detail']);
    }

    public function testUpdateLogsOnlyWhatChanged(): void
    {
        $other = $this->createStaff('other@otternova.com', ['full_name' => 'Old Name', 'telephone_number' => '+6597754577']);
        $own   = $this->db->table('staff_master')->where('auth_id', $other['id'])->get()->getRowArray();

        // the same values as stored: nothing is written or logged
        $this->save('otherotternovacom', ['full_name' => 'Old Name', 'telephone_number' => '+65 9775 4577'], ['job_title' => 'Engineer'])->assertStatus(200);
        $this->assertSame([], $this->activity());

        $this->save('otherotternovacom', ['full_name' => 'New Name', 'otp_mode' => 'X'], ['job_title' => 'CTO', 'tax_id_number' => 'T-99'])->assertStatus(200);

        $logs = $this->activity();
        $this->assertCount(2, $logs);
        $this->assertSame(['Updated account otherotternovacom', 'auth_master', $other['id']], [$logs[0]['activity_summary'], $logs[0]['table_involved'], (int) $logs[0]['table_id_updated']]);
        $this->assertSame(['action' => 'update', 'changes' => ['full_name' => ['old' => 'Old Name', 'new' => 'New Name']]], $logs[0]['detail']);
        $this->assertSame(['Updated staff profile of otherotternovacom', 'staff_master', (int) $own['id']], [$logs[1]['activity_summary'], $logs[1]['table_involved'], (int) $logs[1]['table_id_updated']]);
        $this->assertSame([
            'job_title'     => ['old' => 'Engineer', 'new' => 'CTO'],
            'tax_id_number' => ['old' => '***', 'new' => '***'], // encrypted: the change, never the value
        ], $logs[1]['detail']['changes']);
    }

    public function testUpdateLogsANewProfileRowAsAnInsert(): void
    {
        $client = $this->createAccount('client@example.com', ['account_type' => 'C']);

        $this->save('clientexamplecom', [], ['display_name' => 'Otter Fan'])->assertStatus(200);

        $logs = $this->activity();
        $this->assertCount(1, $logs);
        $this->assertSame('Created client profile of clientexamplecom', $logs[0]['activity_summary']);
        $this->assertSame(['display_name' => 'Otter Fan', 'auth_id' => $client['id']], $logs[0]['detail']['new']);
    }

    public function testDeleteIsLoggedRowByRow(): void
    {
        $other = $this->createStaff('other@otternova.com');
        $own   = $this->db->table('staff_master')->where('auth_id', $other['id'])->get()->getRowArray();
        $this->db->table('auth_otp_challenge')->insert([
            'auth_id' => $other['id'], 'account_type' => 'S', 'token_hash' => str_repeat('a', 64),
            'otp_hash' => password_hash('123456', PASSWORD_DEFAULT), 'attempts' => 0, 'expires_at' => time() + 300,
        ]);

        $this->send('v1/staff/account/delete', ['uid' => 'otherotternovacom'])->assertStatus(200);

        $logs = $this->activity();
        $this->assertSame([
            ['Deleted profile of otherotternovacom', 'staff_master', (int) $own['id'], 'delete'],
            ['Deleted OTP challenge of otherotternovacom', 'auth_otp_challenge', null, 'delete'],
            ['Deleted account otherotternovacom', 'auth_master', $other['id'], 'delete'],
        ], array_map(static fn ($log) => [
            $log['activity_summary'], $log['table_involved'],
            $log['table_involved'] === 'auth_otp_challenge' ? null : (int) $log['table_id_updated'], $log['detail']['action'],
        ], $logs));
        $this->assertSame('other@otternova.com', $logs[2]['detail']['old']['email_address']);
        $this->assertArrayNotHasKey('token_hash', $logs[1]['detail']['old']);
        $this->assertArrayNotHasKey('otp_hash', $logs[1]['detail']['old']);
        foreach ($logs as $log) {
            $this->assertSame($this->staff['id'], (int) $log['changed_by_acc']);
        }
    }

    public function testRefusedOrInvalidChangesAreNotLogged(): void
    {
        $this->save(null, $this->newAccount(['full_name' => '']), $this->newProfile())->assertStatus(422);
        $this->send('v1/staff/account/delete', ['uid' => 'adminotternovacom'])->assertStatus(403);
        $this->save('adminotternovacom', ['account_status' => 'S'], [])->assertStatus(422);

        $this->assertSame([], $this->activity());
    }

    public function testAChangeIsRolledBackWhenItsLogCannotBeWritten(): void
    {
        $table = $this->db->prefixTable('log_activity');
        $this->db->query("CREATE TRIGGER fail_log_insert BEFORE INSERT ON {$table} BEGIN SELECT RAISE(ABORT, 'log insert failed'); END");
        $this->createStaff('other@otternova.com', ['full_name' => 'Old Name']);

        $this->save(null, $this->newAccount(), $this->newProfile())->assertStatus(500);
        $this->save('otherotternovacom', ['full_name' => 'New Name'], [])->assertStatus(500);
        $this->send('v1/staff/account/delete', ['uid' => 'otherotternovacom'])->assertStatus(500);

        $this->dontSeeInDatabase('auth_master', ['uid' => 'eikinleeotternovacom']);
        $this->seeInDatabase('auth_master', ['uid' => 'otherotternovacom', 'full_name' => 'Old Name']);
        $this->seeNumRecords(2, 'staff_master', []);
    }

    // ---- encryption ----

    public function testIdentityNumbersAreStoredEncryptedAndFetchedInPlain(): void
    {
        $this->save(null, $this->newAccount(), $this->newProfile(['national_id_number' => 'S1234567A', 'tax_id_number' => 'T-998877']))->assertStatus(200);

        $row = $this->db->table('staff_master')->where('employee_code', 'EMP-NEW')->get()->getRowArray();
        foreach (['national_id_number' => 'S1234567A', 'tax_id_number' => 'T-998877'] as $field => $plain) {
            $this->assertStringStartsWith(FieldEncryption::PREFIX, $row[$field]);
            $this->assertStringNotContainsString($plain, $row[$field]);
        }
        $this->assertNull($row['legal_middle_name']); // only the identity numbers are encrypted

        $profile = $this->data($this->fetch('eikinleeotternovacom'))['profile'];
        $this->assertSame('S1234567A', $profile['national_id_number']);
        $this->assertSame('T-998877', $profile['tax_id_number']);
    }

    public function testClientNationalIdIsEncryptedOnUpdate(): void
    {
        $client = $this->createClient('client@example.com');

        $this->save('clientexamplecom', [], ['national_id_type' => 'P', 'national_id_number' => 'P99887766'])->assertStatus(200);

        $stored = $this->db->table('client_master')->where('auth_id', $client['id'])->get()->getRowArray()['national_id_number'];
        $this->assertStringStartsWith(FieldEncryption::PREFIX, $stored);
        $this->assertSame('P99887766', $this->data($this->fetch('clientexamplecom'))['profile']['national_id_number']);
    }

    public function testResavingTheSameNumberWritesAndLogsNothing(): void
    {
        $this->save(null, $this->newAccount(), $this->newProfile(['national_id_number' => 'S1234567A']))->assertStatus(200);
        $before = $this->db->table('staff_master')->where('employee_code', 'EMP-NEW')->get()->getRowArray()['national_id_number'];
        $logs   = count($this->activity());

        $this->save('eikinleeotternovacom', [], ['national_id_number' => 'S1234567A'])->assertStatus(200);

        $this->seeInDatabase('staff_master', ['employee_code' => 'EMP-NEW', 'national_id_number' => $before]);
        $this->assertCount($logs, $this->activity());
    }

    public function testChangedNumbersAreLoggedWithoutValues(): void
    {
        $this->save(null, $this->newAccount(), $this->newProfile(['national_id_number' => 'S1234567A', 'tax_id_number' => 'T-1']))->assertStatus(200);
        $this->save('eikinleeotternovacom', [], ['national_id_number' => 'S7654321Z', 'tax_id_number' => ''])->assertStatus(200);
        $stored = $this->db->table('staff_master')->where('employee_code', 'EMP-NEW')->get()->getRowArray();
        $this->assertNull($stored['tax_id_number']);

        $logs = $this->activity();
        $this->assertSame(['national_id_number' => '***', 'tax_id_number' => '***'], array_intersect_key($logs[1]['detail']['new'], array_flip(['national_id_number', 'tax_id_number'])));
        $this->assertSame([
            'national_id_number' => ['old' => '***', 'new' => '***'],
            'tax_id_number'      => ['old' => '***', 'new' => '***'],
        ], end($logs)['detail']['changes']);
        foreach ($logs as $log) {
            foreach (['S1234567A', 'S7654321Z', 'T-1', FieldEncryption::PREFIX] as $secret) {
                $this->assertStringNotContainsString($secret, (string) $log['activity_detail']);
            }
        }
    }

    public function testRowsWrittenBeforeEncryptionAreReadAndThenEncryptedByTheMigration(): void
    {
        $legacy = $this->createStaff('legacy@otternova.com', [], ['national_id_number' => 'OLD-123', 'tax_id_number' => 'OLD-TAX']);
        $this->createClient('client@example.com', [], ['national_id_number' => 'C-OLD']);
        $this->assertSame('OLD-123', $this->data($this->fetch('legacyotternovacom'))['profile']['national_id_number']);

        $migration = new EncryptIdentityNumbers(Database::forge());
        $migration->up();

        $staff = $this->db->table('staff_master')->where('auth_id', $legacy['id'])->get()->getRowArray();
        $this->assertStringStartsWith(FieldEncryption::PREFIX, $staff['national_id_number']);
        $this->assertStringStartsWith(FieldEncryption::PREFIX, $staff['tax_id_number']);
        $this->assertStringStartsWith(FieldEncryption::PREFIX, $this->db->table('client_master')->get()->getRowArray()['national_id_number']);
        $this->assertSame('OLD-TAX', $this->data($this->fetch('legacyotternovacom'))['profile']['tax_id_number']);

        // each converted row is logged as a change by the system, without values
        $logs = array_values(array_filter($this->activity(), static fn ($log) => $log['activity_summary'] === 'Encrypted identity numbers'));
        $this->assertSame(['staff_master', 'client_master'], array_column($logs, 'table_involved'));
        foreach ($logs as $log) {
            $this->assertNull($log['changed_by_acc']);
            $this->assertStringNotContainsString('OLD-', $log['activity_detail']);
        }

        // running it again changes nothing, and down() restores the plaintext
        $migration->up();
        $this->assertCount(2, array_filter($this->activity(), static fn ($log) => $log['activity_summary'] === 'Encrypted identity numbers'));
        $migration->down();
        $this->seeInDatabase('staff_master', ['auth_id' => $legacy['id'], 'national_id_number' => 'OLD-123', 'tax_id_number' => 'OLD-TAX']);
        $this->seeInDatabase('client_master', ['national_id_number' => 'C-OLD']);
        $migration->up();
    }

    public function testMissingKeyOrTamperedValueIsA500AndLogged(): void
    {
        $this->save(null, $this->newAccount(), $this->newProfile(['national_id_number' => 'S1234567A']))->assertStatus(200);
        $config = config('Encryption');
        $key    = $config->key;

        $config->key = '';
        try {
            $responses = [
                $this->fetch('eikinleeotternovacom'),
                $this->save('eikinleeotternovacom', [], ['national_id_number' => 'S0000000B']),
            ];
        } finally {
            $config->key = $key;
        }
        foreach ($responses as $response) {
            $response->assertStatus(500);
            $this->assertSame('error', $this->body($response)['status']);
        }
        $this->assertTrue($this->logged('error', 'encryption.key is not set'));

        // ciphertext changed in the database fails its HMAC check rather than decrypting to something else
        $stored = $this->db->table('staff_master')->where('employee_code', 'EMP-NEW')->get()->getRowArray()['national_id_number'];
        $raw    = base64_decode(substr($stored, strlen(FieldEncryption::PREFIX)), true);
        $raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 1);
        $this->db->table('staff_master')->where('employee_code', 'EMP-NEW')->update(['national_id_number' => FieldEncryption::PREFIX . base64_encode($raw)]);

        try {
            $response = $this->fetch('eikinleeotternovacom');
        } finally {
            // the database reset rolls the migrations back, which would fail on this value too
            $this->db->table('staff_master')->where('employee_code', 'EMP-NEW')->update(['national_id_number' => null]);
        }
        $response->assertStatus(500);
        $this->assertSame(['status' => 'error', 'message' => lang('Accounts.loadFailed')], $this->body($response));
    }

    // ---- helpers ----

    private function newAccount(array $overrides = []): array
    {
        return $overrides + [
            'email_address'    => 'Eikin.Lee@otternova.com',
            'telephone_number' => '+6597754577',
            'password_expiry'  => '2027-11-15',
            'full_name'        => 'Eikin Lee',
            'otp_mode'         => 'E',
            'account_status'   => 'P',
            'account_type'     => 'S',
            'admin_notes'      => 'New CEO',
        ];
    }

    private function newProfile(array $overrides = []): array
    {
        return $overrides + [
            'department_id'         => 1,
            'employee_code'         => 'EMP-NEW',
            'job_title'             => 'CEO',
            'employment_start_date' => '2025-01-01',
            'work_country_code'     => 'SG',
            'legal_first_name'      => 'Eikin',
            'legal_last_name'       => 'Lee',
        ];
    }

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
        $this->db->table('staff_master')->insert($profile + [
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

    /**
     * staff_master.id of a staff account
     */
    private function staffId(array $account): int
    {
        return (int) $this->db->table('staff_master')->where('auth_id', $account['id'])->get()->getRow()->id;
    }

    private function createClient(string $email, array $overrides = [], array $profile = []): array
    {
        $account = $this->createAccount($email, $overrides + ['account_type' => 'C']);
        $this->db->table('client_master')->insert($profile + ['auth_id' => $account['id']]);

        return $account;
    }

    private function send(string $path, array $body, ?string $token = null): TestResponse
    {
        $response = $this->withHeaders(['Authorization' => 'Bearer ' . ($token ?? $this->token)])
            ->withBodyFormat('json')
            ->post($path, $body);
        $this->responses[] = $response;

        return $response;
    }

    private function fetch(string $uid): TestResponse
    {
        return $this->send('v1/staff/account', ['uid' => $uid]);
    }

    private function save(?string $uid, array $account, array $profile): TestResponse
    {
        return $this->send('v1/staff/account/update', ['uid' => $uid, 'account' => $account, 'profile' => $profile]);
    }

    private function body(TestResponse $response): array
    {
        return json_decode($response->getJSON(), true);
    }

    /**
     * The data of a 200 success response
     */
    private function data(TestResponse $response): array
    {
        $response->assertStatus(200);
        $body = $this->body($response);
        $this->assertSame('success', $body['status']);

        return $body['data'];
    }

    /**
     * The field errors of a 422 response
     */
    private function errors(TestResponse $response): array
    {
        $response->assertStatus(422);
        $body = $this->body($response);
        $this->assertSame('error', $body['status']);
        $this->assertSame(lang('Accounts.invalidFields'), $body['message']);

        return $body['errors'];
    }

    /**
     * The log_activity rows, oldest first, with activity_detail decoded as 'detail'
     */
    private function activity(): array
    {
        $rows = $this->db->table('log_activity')->orderBy('id')->get()->getResultArray();

        return array_map(static fn ($row) => $row + ['detail' => json_decode((string) $row['activity_detail'], true)], $rows);
    }

    private function logged(string $level, string $contains): bool
    {
        foreach ((new ReflectionProperty(TestLogger::class, 'op_logs'))->getValue() as $log) {
            if ($log['level'] === $level && str_contains($log['message'], $contains)) {
                return true;
            }
        }

        return false;
    }
}
