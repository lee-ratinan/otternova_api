<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\Services;

/**
 * /staff/business-type/create and /staff/business-type/edit: a business type with its name in every language of
 * listSupportedLanguages()
 *
 * @internal
 */
final class BusinessTypesTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'App'; // the app's own migrations, the schema of the real database

    // ---- list ----

    public function testListHasEveryTypeWithItsNames(): void
    {
        $this->send([], null, 'v1/staff/business-types')->assertStatus(200);
        $this->assertSame([], $this->body($this->send([], null, 'v1/staff/business-types'))['data']['rows']);

        $id = $this->body($this->send($this->type()))['data']['public_id'];
        $this->send($this->type(['type_code' => 'bakery', 'type_status' => 'I', 'translations' => ['en-US' => 'Bakery', 'th' => 'เบเกอรี่']]))->assertStatus(200);

        $rows = $this->body($this->send([], null, 'v1/staff/business-types'))['data']['rows'];
        $this->assertSame(['bakery', 'cafe'], array_column($rows, 'type_code'));
        $this->assertSame(['public_id' => $id, 'type_code' => 'cafe', 'type_status' => 'A', 'translations' => ['en-US' => 'Café', 'th' => 'คาเฟ่']], $rows[1]);

        $this->withHeaders([])->post('v1/staff/business-types')->assertStatus(401);
    }

    /** The signed-in staff account calling the endpoint */
    private array $staff;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['jwt', 'authContext'] as $service) {
            Services::resetSingle($service);
        }
        $this->db->table('otternova_department')->insert(['department_code' => 'EXE', 'department_name' => 'Executive Office', 'country_code' => 'TH']);
        $this->staff = $this->createAccount('admin@otternova.com', 'S');
        $this->db->table('staff_master')->insert([
            'auth_id'               => $this->staff['id'],
            'department_id'         => $this->db->insertID(),
            'employee_code'         => 'EMP-1',
            'job_title'             => 'Officer',
            'employment_status'     => 'A',
            'employment_start_date' => '2024-01-01',
            'work_country_code'     => 'TH',
            'legal_first_name'      => 'Otter',
            'legal_last_name'       => 'Tester',
        ]);
        $this->token = service('jwt')->issue($this->staff)['access_token'];
    }

    public function testOnlyStaffGetIn(): void
    {
        $this->withHeaders([])->post('v1/staff/business-type/create')->assertStatus(401);

        $client = $this->createAccount('client@example.com', 'C');
        $this->db->table('client_master')->insert(['auth_id' => $client['id']]);
        $this->send($this->type(), service('jwt')->issue($client)['access_token'])->assertStatus(403);
    }

    public function testTypeIsCreatedWithANameInEverySupportedLanguage(): void
    {
        $response = $this->send($this->type(['translations' => ['en-US' => ' Café ', 'th' => 'คาเฟ่']]));
        $response->assertStatus(200);
        $data = $this->body($response)['data'];
        $this->assertSame('cafe', $data['type_code']);

        $this->assertSame(['public_id', 'type_code'], array_keys($data));
        $row = $this->db->table('business_type')->where('public_id', $data['public_id'])->get()->getRowArray();
        $this->assertSame(['cafe', 'A'], [$row['type_code'], $row['type_status']]);

        $names = $this->db->table('business_type_translation')->where('business_type_id', $row['id'])->get()->getResultArray();
        $this->assertEqualsCanonicalizing(['en-US' => 'Café', 'th' => 'คาเฟ่'], array_column($names, 'type_name', 'language_code'));

        $this->assertSame(3, $this->db->table('log_activity')->where('changed_by_acc', $this->staff['id'])->countAllResults()); // the type and two names
    }

    public function testEverySupportedLanguageIsRequiredAndNoOther(): void
    {
        $response = $this->send($this->type(['translations' => ['en-US' => 'Café', 'th' => ' ', 'ja' => 'カフェ']]));
        $response->assertStatus(422);
        $errors = $this->body($response)['errors'];
        $this->assertSame(lang('Business.translationRequired'), $errors['translations.th']);
        $this->assertSame(lang('Business.unknownLanguage'), $errors['translations.ja']);

        $response = $this->send($this->type(['translations' => []]));
        $response->assertStatus(422);
        $this->assertSame(['translations.en-US', 'translations.th'], array_keys($this->body($response)['errors']));
        $this->assertSame(0, $this->db->table('business_type')->countAllResults());
    }

    public function testCodeAndNamesMustBeNew(): void
    {
        $this->send($this->type())->assertStatus(200);

        $response = $this->send($this->type(['type_code' => 'Coffee Shop', 'type_status' => 'X']));
        $response->assertStatus(422);
        $this->assertSame(['type_code', 'type_status'], array_keys($this->body($response)['errors']));

        $response = $this->send($this->type(['type_code' => 'cafe']));
        $response->assertStatus(422);
        $this->assertArrayHasKey('type_code', $this->body($response)['errors']);

        $response = $this->send($this->type(['type_code' => 'coffee_shop', 'translations' => ['en-US' => 'Café', 'th' => 'ร้านกาแฟ']]));
        $response->assertStatus(422);
        $this->assertSame(['translations.en-US' => lang('Business.typeNameTaken')], $this->body($response)['errors']);

        $this->send($this->type(['type_code' => 'salon', 'type_status' => 'i', 'translations' => ['en-US' => 'Salon', 'th' => 'ร้านเสริมสวย']]))->assertStatus(200);
        $this->assertSame('I', $this->db->table('business_type')->where('type_code', 'salon')->get()->getRow()->type_status);
    }

    // ---- edit ----

    public function testTypeStatusAndNamesAreEdited(): void
    {
        $id = $this->body($this->send($this->type()))['data']['public_id'];

        $response = $this->send(['public_id' => $id, 'type_status' => 'I', 'translations' => ['en-US' => 'Coffee Shop', 'th' => 'คาเฟ่']], null, 'v1/staff/business-type/edit');
        $response->assertStatus(200);
        $this->assertSame(['public_id' => $id, 'type_code' => 'cafe'], $this->body($response)['data']);

        $this->assertSame('I', $this->db->table('business_type')->where('public_id', $id)->get()->getRow()->type_status);
        $typeId = $this->db->table('business_type')->where('public_id', $id)->get()->getRow()->id;
        $names  = $this->db->table('business_type_translation')->where('business_type_id', $typeId)->get()->getResultArray();
        $this->assertEqualsCanonicalizing(['en-US' => 'Coffee Shop', 'th' => 'คาเฟ่'], array_column($names, 'type_name', 'language_code'));

        // the status and one name changed: two more log rows, the unchanged Thai name none
        $this->assertSame(5, $this->db->table('log_activity')->where('changed_by_acc', $this->staff['id'])->countAllResults());

        // left out, the status stays
        $this->send(['public_id' => $id, 'translations' => ['en-US' => 'Coffee Shop', 'th' => 'ร้านกาแฟ']], null, 'v1/staff/business-type/edit')->assertStatus(200);
        $this->assertSame('I', $this->db->table('business_type')->where('public_id', $id)->get()->getRow()->type_status);
    }

    public function testEditKeepsTheCodeAndEveryLanguage(): void
    {
        $id = $this->body($this->send($this->type()))['data']['public_id'];
        $this->send($this->type(['type_code' => 'salon', 'translations' => ['en-US' => 'Salon', 'th' => 'ร้านเสริมสวย']]))->assertStatus(200);

        $response = $this->send(['public_id' => $id, 'type_code' => 'coffee_shop', 'translations' => ['en-US' => 'Café']], null, 'v1/staff/business-type/edit');
        $response->assertStatus(422);
        $this->assertSame(['translations.th' => lang('Business.translationRequired'), 'type_code' => lang('Business.fixedField')], $this->body($response)['errors']);

        $response = $this->send(['public_id' => $id, 'translations' => ['en-US' => 'Salon', 'th' => 'คาเฟ่']], null, 'v1/staff/business-type/edit');
        $response->assertStatus(422);
        $this->assertSame(['translations.en-US' => lang('Business.typeNameTaken')], $this->body($response)['errors']);

        // its own names are not taken, and its own code may be sent back
        $this->send(['public_id' => $id, 'type_code' => 'cafe'] + $this->type(), null, 'v1/staff/business-type/edit')->assertStatus(200);

        $this->send(['translations' => []], null, 'v1/staff/business-type/edit')->assertStatus(400);
        $this->send(['public_id' => 'zzzz'] + $this->type(), null, 'v1/staff/business-type/edit')->assertStatus(404);
        $this->send(['id' => 1] + $this->type(), null, 'v1/staff/business-type/edit')->assertStatus(400); // the old numeric id is not taken
    }

    private function type(array $overrides = []): array
    {
        return $overrides + [
            'type_code'    => 'cafe',
            'translations' => ['en-US' => 'Café', 'th' => 'คาเฟ่'],
        ];
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
        $this->db->table('auth_master')->insert($account);
        $account['id'] = $this->db->insertID();

        return $account;
    }

    private function send(array $body, ?string $token = null, string $path = 'v1/staff/business-type/create'): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . ($token ?? $this->token)])
            ->withBodyFormat('json')
            ->post($path, $body);
    }

    private function body(TestResponse $response): array
    {
        return json_decode($response->getJSON(), true);
    }
}
