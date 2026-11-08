<?php

use App\Models\BusinessTypeModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\Services;

/**
 * /client/colleagues and /client/colleague: anyone active in a business lists the people active in it and sees
 * their public profiles, and nothing more of their accounts
 *
 * @internal
 */
final class ColleaguesTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'App'; // the app's own migrations, the schema of the real database

    private string $token;
    private int $businessId;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['jwt', 'authContext'] as $service) {
            Services::resetSingle($service);
        }
        $owner       = $this->createClient('owner@example.com', 'Olive Owner');
        $this->token = $owner['token'];

        $staff  = $this->createAccount('admin@otternova.com', 'S', 'Admin');
        $types  = new BusinessTypeModel();
        $typeId = $types->publicIdOf($types->createWithNames(['type_code' => 'cafe'], ['en-US' => 'Café', 'th' => 'คาเฟ่'], (int) $staff['id']));
        $this->send([
            'business' => [
                'business_type_public_id' => $typeId,
                'business_name'           => 'OtterCafe',
                'business_slug'           => 'otter-cafe-th',
                'contact_email_address'   => 'hello@ottercafe.th',
                'country_code'            => 'TH',
                'currency_code'           => 'THB',
            ],
        ], null, 'v1/client/business/create')->assertStatus(200);
        $this->businessId = (int) $this->db->table('business_master')->get()->getRow()->id;
    }

    public function testAnEmployeeListsOnlyThePeopleActiveInTheBusiness(): void
    {
        $emma = $this->createClient('emma@example.com', 'Emma Employee', 'E', 'A');
        $this->createClient('fred@example.com', 'Fred Former', 'E', 'T');
        $this->createClient('stranger@example.com', 'Stranger');

        $response = $this->send(['business_slug' => 'otter-cafe-th'], $emma['token']);
        $response->assertStatus(200);
        $rows = $this->body($response)['data']['colleagues'];
        $this->assertSame(['Emma Employee', 'Olive Owner'], array_column($rows, 'full_name'));
        $this->assertSame(['E', 'O'], array_column($rows, 'client_role'));
        $this->assertSame(['uid', 'full_name', 'display_name', 'avatar_path', 'status_emoji', 'status_message', 'client_role', 'start_date'],
            array_keys($rows[0]));
    }

    public function testShowGivesOnlyThePublicProfile(): void
    {
        $emma = $this->createClient('emma@example.com', 'Emma Employee', 'E', 'A');
        $this->db->table('client_master')->where('id', $emma['client_id'])->update([
            'display_name' => 'Em', 'bio' => 'Barista', 'gender' => 'F', 'legal_first_name' => 'Emma', 'internal_notes' => 'secret',
        ]);
        $this->db->table('business_client_link')->where('client_id', $emma['client_id'])->update(['owner_notes' => 'Keys holder']);
        $manager = $this->createClient('manager@example.com', 'Manager', 'M', 'A');

        $response = $this->send(['business_slug' => 'otter-cafe-th', 'uid' => $emma['uid']], $manager['token'], 'v1/client/colleague');
        $response->assertStatus(200);
        $data = $this->body($response)['data'];
        $this->assertSame(['uid', 'email_address', 'telephone_number', 'full_name', 'avatar_path'], array_keys($data['account']));
        $this->assertSame(['display_name', 'status_message', 'status_emoji', 'gender', 'date_of_birth', 'nationality_code', 'preferred_language', 'bio'],
            array_keys($data['profile']));
        $this->assertSame(['Em', 'Barista', 'F'], [$data['profile']['display_name'], $data['profile']['bio'], $data['profile']['gender']]);
        $this->assertSame(['client_role' => 'E', 'start_date' => '2026-01-01'], $data['link']);
        $this->assertFalse($data['is_self']);

        $self = $this->body($this->send(['business_slug' => 'otter-cafe-th', 'uid' => $emma['uid']], $emma['token'], 'v1/client/colleague'))['data'];
        $this->assertTrue($self['is_self']);
    }

    public function testOnlyThePeopleActiveInTheBusinessGetInAndAreShown(): void
    {
        $emma     = $this->createClient('emma@example.com', 'Emma Employee', 'E', 'A');
        $fred     = $this->createClient('fred@example.com', 'Fred Former', 'E', 'T');
        $stranger = $this->createClient('stranger@example.com', 'Stranger');

        $this->withHeaders([])->post('v1/client/colleagues')->assertStatus(401);
        foreach ([$fred, $stranger] as $outsider) {
            $this->send(['business_slug' => 'otter-cafe-th'], $outsider['token'])->assertStatus(404);
            $this->send(['business_slug' => 'otter-cafe-th', 'uid' => $emma['uid']], $outsider['token'], 'v1/client/colleague')->assertStatus(404);
        }

        // a former employee, someone never linked, a staff account
        foreach ([$fred['uid'], $stranger['uid'], 'adminotternovacom', 'nobody'] as $uid) {
            $this->send(['business_slug' => 'otter-cafe-th', 'uid' => $uid], $emma['token'], 'v1/client/colleague')->assertStatus(404);
        }
        $this->send(['business_slug' => 'otter-cafe-th'], $emma['token'], 'v1/client/colleague')->assertStatus(400);
        $this->send(['uid' => $emma['uid']], $emma['token'], 'v1/client/colleague')->assertStatus(400);
    }

    private function createAccount(string $email, string $type, string $name): array
    {
        $account = [
            'uid'             => preg_replace('/[^a-z0-9]/', '', $email),
            'email_address'   => $email,
            'password_hash'   => password_hash('correct horse battery staple', PASSWORD_DEFAULT),
            'password_expiry' => '2027-01-01',
            'full_name'       => $name,
            'otp_mode'        => 'X',
            'account_status'  => 'A',
            'account_type'    => $type,
        ];
        $this->db->table('auth_master')->insert($account);
        $account['id'] = $this->db->insertID();

        return $account;
    }

    private function createClient(string $email, string $name, ?string $role = null, string $status = 'A'): array
    {
        $account = $this->createAccount($email, 'C', $name);
        $this->db->table('client_master')->insert(['auth_id' => $account['id']]);
        $clientId = (int) $this->db->insertID();
        if ($role !== null) {
            $this->db->table('business_client_link')->insert(['business_id' => $this->businessId, 'client_id' => $clientId, 'client_role' => $role, 'client_status' => $status,
                'start_date' => '2026-01-01', 'termination_date' => $status === 'T' ? '2026-02-01' : null]);
        }

        return ['client_id' => $clientId, 'uid' => $account['uid'], 'token' => service('jwt')->issue($account)['access_token']];
    }

    private function send(array $body, ?string $token = null, string $path = 'v1/client/colleagues'): TestResponse
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
