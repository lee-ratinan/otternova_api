<?php

use App\Models\BusinessTypeModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\Services;

/**
 * /client/stores, /client/store/create and /client/store/edit: the active owners and managers of a business list,
 * create and edit its stores
 *
 * @internal
 */
final class StoresTest extends CIUnitTestCase
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
        $owner       = $this->createClient('owner@example.com');
        $this->token = $owner['token'];

        $staff  = $this->createAccount('admin@otternova.com', 'S');
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

    public function testPhysicalAndOnlineStoresAreCreatedAndListed(): void
    {
        $response = $this->send([
            'business_slug' => 'otter-cafe-th',
            'store'         => ['store_name' => 'Bangkok', 'store_type' => 'p', 'store_phone_number' => ' +6621234567 ', 'store_address_line_1' => '1 Sukhumvit Rd', 'store_city' => 'Bangkok', 'store_subdivision_code' => 'th-10', 'store_postal_code' => '10110'],
            'translations'  => ['th' => ' กรุงเทพฯ ', 'ja' => ''],
        ]);
        $response->assertStatus(200);
        $publicId = $this->body($response)['data']['public_id'];
        $this->assertMatchesRegularExpression('/^[0-9A-Za-z_-]{12}$/', $publicId);

        $this->send([
            'business_slug' => 'otter-cafe-th',
            'store'         => ['store_name' => 'Online Store', 'store_type' => 'O', 'store_phone_number' => '021234567', 'store_address_line_1' => 'ignored', 'store_subdivision_code' => 'XX-1'],
        ])->assertStatus(200);

        $response = $this->send(['business_slug' => 'otter-cafe-th'], null, 'v1/client/stores');
        $response->assertStatus(200);
        $stores = $this->body($response)['data']['stores'];
        $this->assertSame(['Bangkok', 'Online Store'], array_column($stores, 'store_name'));
        $this->assertSame(['+6621234567', '021234567'], array_column($stores, 'store_phone_number')); // kept for an online store too
        $this->assertSame([$publicId, 'P', '1 Sukhumvit Rd', 'TH-10', ['th' => 'กรุงเทพฯ']],
            [$stores[0]['public_id'], $stores[0]['store_type'], $stores[0]['store_address_line_1'], $stores[0]['store_subdivision_code'], $stores[0]['translations']]);
        $this->assertSame(['O', null, null, []], [$stores[1]['store_type'], $stores[1]['store_address_line_1'], $stores[1]['store_subdivision_code'], $stores[1]['translations']]);
        $this->assertArrayNotHasKey('id', $stores[0]);
    }

    public function testFieldsAreChecked(): void
    {
        $response = $this->send(['business_slug' => 'otter-cafe-th', 'store' => ['store_name' => '', 'store_type' => 'X']]);
        $response->assertStatus(422);
        $this->assertSame(['store_name', 'store_type'], array_keys($this->body($response)['errors']));

        $response = $this->send(['business_slug' => 'otter-cafe-th', 'store' => ['store_name' => 'Bangkok', 'store_type' => 'P', 'store_phone_number' => str_repeat('1', 33)]]);
        $response->assertStatus(422);
        $this->assertSame(['store_phone_number'], array_keys($this->body($response)['errors']));

        // a subdivision of another country than the business's
        $response = $this->send(['business_slug' => 'otter-cafe-th', 'store' => ['store_name' => 'Bangkok', 'store_type' => 'P', 'store_subdivision_code' => 'SG-01']]);
        $response->assertStatus(422);
        $this->assertSame(['store_subdivision_code' => lang('BusinessStore.invalidSubdivision')], $this->body($response)['errors']);

        $this->send(['business_slug' => 'otter-cafe-th', 'store' => ['store_name' => 'Bangkok', 'store_type' => 'P']])->assertStatus(200);
        $response = $this->send(['business_slug' => 'otter-cafe-th', 'store' => ['store_name' => 'Bangkok', 'store_type' => 'O']]);
        $response->assertStatus(422);
        $this->assertSame(['store_name' => lang('Store.nameTaken')], $this->body($response)['errors']);

        $response = $this->send(['business_slug' => 'otter-cafe-th', 'store' => ['store_name' => 'Pattaya', 'store_type' => 'P'], 'translations' => ['fr' => 'Pattaya']]);
        $response->assertStatus(422);
        $this->assertSame(lang('Store.unknownLanguage'), $this->body($response)['errors']['translations.fr']);

        $this->send(['store' => ['store_name' => 'Pattaya', 'store_type' => 'P']])->assertStatus(400);
        $this->send(['business_slug' => 'nobody', 'store' => ['store_name' => 'Pattaya', 'store_type' => 'P']])->assertStatus(404);
    }

    public function testEditKeepsWhatIsLeftOutAndClearsTheAddressOfAnOnlineStore(): void
    {
        $publicId = $this->body($this->send([
            'business_slug' => 'otter-cafe-th',
            'store'         => ['store_name' => 'Pattaya', 'store_type' => 'P', 'store_address_line_1' => '2 Beach Rd', 'store_subdivision_code' => 'TH-20'],
            'translations'  => ['th' => 'พัทยา', 'ja' => 'パタヤ'],
        ]))['data']['public_id'];

        $response = $this->send(['public_id' => $publicId, 'store' => ['store_city' => 'Pattaya'], 'translations' => ['th' => 'พัทยา']], null, 'v1/client/store/edit');
        $response->assertStatus(200);
        $row = $this->db->table('business_store')->where('public_id', $publicId)->get()->getRowArray();
        $this->assertSame(['Pattaya', '2 Beach Rd', 'Pattaya', 'TH-20'], [$row['store_name'], $row['store_address_line_1'], $row['store_city'], $row['store_subdivision_code']]);
        $names = $this->db->table('business_store_translation')->where('store_id', $row['id'])->get()->getResultArray();
        $this->assertSame(['th' => 'พัทยา'], array_column($names, 'store_name', 'language_code'));

        $this->send(['public_id' => $publicId, 'store' => ['store_type' => 'O']], null, 'v1/client/store/edit')->assertStatus(200);
        $row = $this->db->table('business_store')->where('public_id', $publicId)->get()->getRowArray();
        $this->assertSame(['O', null, null, null], [$row['store_type'], $row['store_address_line_1'], $row['store_city'], $row['store_subdivision_code']]);
        $this->assertSame(1, $this->db->table('business_store_translation')->where('store_id', $row['id'])->countAllResults()); // names left out, kept

        $this->send(['public_id' => 'nope', 'store' => []], null, 'v1/client/store/edit')->assertStatus(404);
        $this->send(['store' => []], null, 'v1/client/store/edit')->assertStatus(400);
    }

    public function testOnlyActiveOwnersAndManagersGetIn(): void
    {
        $publicId = $this->body($this->send(['business_slug' => 'otter-cafe-th', 'store' => ['store_name' => 'Bangkok', 'store_type' => 'P']]))['data']['public_id'];
        $list     = ['business_slug' => 'otter-cafe-th'];
        $create   = ['business_slug' => 'otter-cafe-th', 'store' => ['store_name' => 'Chiang Mai', 'store_type' => 'P']];
        $edit     = ['public_id' => $publicId, 'store' => ['store_city' => 'Hacked']];

        $this->withHeaders([])->post('v1/client/stores')->assertStatus(401);

        $manager = $this->createClient('manager@example.com', 'M', 'A');
        $this->send($list, $manager['token'], 'v1/client/stores')->assertStatus(200);
        $this->send($create, $manager['token'])->assertStatus(200);
        $this->send(['public_id' => $publicId, 'store' => ['store_city' => 'Bangkok']], $manager['token'], 'v1/client/store/edit')->assertStatus(200);

        $employee = $this->createClient('employee@example.com', 'E', 'A');
        foreach ([[$list, 'v1/client/stores'], [$create, 'v1/client/store/create'], [$edit, 'v1/client/store/edit']] as [$body, $path]) {
            $response = $this->send($body, $employee['token'], $path);
            $response->assertStatus(403);
            $this->assertSame(lang('Store.managersOnly'), $this->body($response)['message']);
        }

        $former   = $this->createClient('former@example.com', 'O', 'T');
        $stranger = $this->createClient('stranger@example.com');
        foreach ([$former, $stranger] as $outsider) {
            $this->send($list, $outsider['token'], 'v1/client/stores')->assertStatus(404);
            $this->send($create, $outsider['token'])->assertStatus(404);
            $this->send($edit, $outsider['token'], 'v1/client/store/edit')->assertStatus(404);
        }

        $this->assertSame('Bangkok', $this->db->table('business_store')->where('public_id', $publicId)->get()->getRow()->store_city);
        $this->assertSame(2, $this->db->table('business_store')->countAllResults());
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

    /**
     * A client account, linked to the business with $role and $status when given
     * @return array{client_id: int, token: string}
     */
    private function createClient(string $email, ?string $role = null, string $status = 'A'): array
    {
        $account = $this->createAccount($email, 'C');
        $this->db->table('client_master')->insert(['auth_id' => $account['id']]);
        $clientId = (int) $this->db->insertID();
        if ($role !== null) {
            $this->db->table('business_client_link')->insert(['business_id' => $this->businessId, 'client_id' => $clientId, 'client_role' => $role, 'client_status' => $status,
                'start_date' => '2026-01-01', 'termination_date' => $status === 'T' ? '2026-02-01' : null]);
        }

        return ['client_id' => $clientId, 'token' => service('jwt')->issue($account)['access_token']];
    }

    private function send(array $body, ?string $token = null, string $path = 'v1/client/store/create'): TestResponse
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
