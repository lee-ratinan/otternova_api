<?php

use App\Models\BusinessTypeModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\PlanLimits;
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
        // room for the stores the other tests make; testTheStoreCapComesFromThePlan() puts the real cap back
        config(PlanLimits::class)->plans['free'][PlanLimits::STORES] = 10;
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
            'translations'  => ['th' => ['store_name' => ' กรุงเทพฯ ', 'store_address_line_1' => ' 1 ถนนสุขุมวิท ', 'store_city' => 'กรุงเทพมหานคร'], 'ja' => ['store_name' => '']],
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
        $this->assertSame([$publicId, 'P', '1 Sukhumvit Rd', 'TH-10', ['th' => ['store_name' => 'กรุงเทพฯ', 'store_address_line_1' => '1 ถนนสุขุมวิท', 'store_address_line_2' => null, 'store_city' => 'กรุงเทพมหานคร']]],
            [$stores[0]['public_id'], $stores[0]['store_type'], $stores[0]['store_address_line_1'], $stores[0]['store_subdivision_code'], $stores[0]['translations']]);
        $this->assertSame(['O', null, null, []], [$stores[1]['store_type'], $stores[1]['store_address_line_1'], $stores[1]['store_subdivision_code'], $stores[1]['translations']]);
        $this->assertArrayNotHasKey('id', $stores[0]);
    }

    public function testTheTimezoneDefaultsToTheCountrysAndMustBeOneOfIts(): void
    {
        $this->send(['business_slug' => 'otter-cafe-th', 'store' => ['store_name' => 'Bangkok', 'store_type' => 'P']])->assertStatus(200);
        $response = $this->send(['business_slug' => 'otter-cafe-th', 'store' => ['store_name' => 'Tokyo', 'store_type' => 'P', 'store_timezone' => 'Asia/Tokyo']]);
        $response->assertStatus(422);
        $this->assertSame(['store_timezone' => lang('BusinessStore.invalidTimezone')], $this->body($response)['errors']);

        $stores = $this->body($this->send(['business_slug' => 'otter-cafe-th'], null, 'v1/client/stores'))['data']['stores'];
        $this->assertSame(['Asia/Bangkok'], array_column($stores, 'store_timezone'));
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

        $response = $this->send(['business_slug' => 'otter-cafe-th', 'store' => ['store_name' => 'Pattaya', 'store_type' => 'P'], 'translations' => ['fr' => ['store_name' => 'Pattaya']]]);
        $response->assertStatus(422);
        $this->assertSame(lang('Translation.unknownLanguage'), $this->body($response)['errors']['translations.fr']);

        // an address in a language needs the name in it too, and fits the column
        $response = $this->send(['business_slug' => 'otter-cafe-th', 'store' => ['store_name' => 'Pattaya', 'store_type' => 'P'], 'translations' => ['th' => ['store_city' => 'พัทยา']]]);
        $response->assertStatus(422);
        $this->assertSame(lang('Translation.nameRequired'), $this->body($response)['errors']['translations.th.store_name']);
        $response = $this->send(['business_slug' => 'otter-cafe-th', 'store' => ['store_name' => 'Pattaya', 'store_type' => 'P'], 'translations' => ['th' => ['store_name' => 'พัทยา', 'store_city' => str_repeat('ก', 129)]]]);
        $response->assertStatus(422);
        $this->assertSame(['translations.th.store_city'], array_keys($this->body($response)['errors']));
        $this->send(['business_slug' => 'otter-cafe-th', 'store' => ['store_name' => 'Pattaya', 'store_type' => 'P'], 'translations' => ['th' => 'พัทยา']])->assertStatus(422);

        $this->send(['store' => ['store_name' => 'Pattaya', 'store_type' => 'P']])->assertStatus(400);
        $this->send(['business_slug' => 'nobody', 'store' => ['store_name' => 'Pattaya', 'store_type' => 'P']])->assertStatus(404);
    }

    public function testEditKeepsWhatIsLeftOutAndClearsTheAddressOfAnOnlineStore(): void
    {
        $publicId = $this->body($this->send([
            'business_slug' => 'otter-cafe-th',
            'store'         => ['store_name' => 'Pattaya', 'store_type' => 'P', 'store_address_line_1' => '2 Beach Rd', 'store_subdivision_code' => 'TH-20'],
            'translations'  => ['th' => ['store_name' => 'พัทยา'], 'ja' => ['store_name' => 'パタヤ']],
        ]))['data']['public_id'];

        $response = $this->send(['public_id' => $publicId, 'store' => ['store_city' => 'Pattaya'], 'translations' => ['th' => ['store_name' => 'พัทยา', 'store_address_line_1' => '2 ถนนชายหาด', 'store_city' => 'พัทยา']]], null, 'v1/client/store/edit');
        $response->assertStatus(200);
        $row = $this->db->table('business_store')->where('public_id', $publicId)->get()->getRowArray();
        $this->assertSame(['Pattaya', '2 Beach Rd', 'Pattaya', 'TH-20'], [$row['store_name'], $row['store_address_line_1'], $row['store_city'], $row['store_subdivision_code']]);
        $names = $this->db->table('business_store_translation')->where('store_id', $row['id'])->get()->getResultArray();
        $this->assertSame(['th' => 'พัทยา'], array_column($names, 'store_name', 'language_code'));
        $this->assertSame(['2 ถนนชายหาด', 'พัทยา'], [$names[0]['store_address_line_1'], $names[0]['store_city']]);

        $this->send(['public_id' => $publicId, 'store' => ['store_type' => 'O']], null, 'v1/client/store/edit')->assertStatus(200);
        $row = $this->db->table('business_store')->where('public_id', $publicId)->get()->getRowArray();
        $this->assertSame(['O', null, null, null], [$row['store_type'], $row['store_address_line_1'], $row['store_city'], $row['store_subdivision_code']]);
        $names = $this->db->table('business_store_translation')->where('store_id', $row['id'])->get()->getResultArray();
        // translations left out are kept, but an online store loses its translated address too
        $this->assertSame([['th', 'พัทยา', null, null]], array_map(static fn (array $n) => [$n['language_code'], $n['store_name'], $n['store_address_line_1'], $n['store_city']], $names));

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

    public function testTheStoreCapComesFromThePlan(): void
    {
        config(PlanLimits::class)->plans['free'][PlanLimits::STORES] = 1; // the real cap, which setUp() raised
        $store = static fn (string $name): array => ['business_slug' => 'otter-cafe-th', 'store' => ['store_name' => $name, 'store_type' => 'O']];

        $list = fn (): array => $this->body($this->send(['business_slug' => 'otter-cafe-th'], null, 'v1/client/stores'))['data'];

        // free: one store
        $this->assertSame(['free', 1, true], [$list()['plan_code'], $list()['max_stores'], $list()['can_add']]);
        $this->send($store('Online'))->assertStatus(200);
        $this->assertFalse($list()['can_add']);
        $response = $this->send($store('Second'));
        $response->assertStatus(409);
        $this->assertSame(lang('Store.limitReached', [1, 'Free']), $this->body($response)['message']);

        // boost, once its subscription is active: two
        $plan = $this->db->table('plan_master')->where('plan_code', 'boost')->get()->getRow();
        if ($plan === null) { // the seed does not run in the test database
            $this->db->table('plan_master')->insert(['plan_code' => 'boost', 'plan_tier' => 1]);
        }
        $boost = (int) ($plan->id ?? $this->db->insertID());
        $this->db->table('plan_version')->insert(['public_id' => 'ver00001', 'plan_id' => $boost, 'country_code' => 'TH', 'currency_code' => 'THB',
            'billing_cycle' => 'M', 'price' => '299.00', 'effective_from' => '2026-01-01 00:00:00']);
        $this->db->table('subscription_master')->insert(['public_id' => 'sub000000001', 'business_id' => $this->businessId,
            'plan_version_id' => (int) $this->db->insertID(), 'subscription_status' => 'P']);
        $this->send($store('Second'))->assertStatus(409); // pending payment: still free
        $this->db->table('subscription_master')->where('business_id', $this->businessId)->update(['subscription_status' => 'A']);
        $this->assertSame(['boost', 2, true], [$list()['plan_code'], $list()['max_stores'], $list()['can_add']]);
        $this->send($store('Second'))->assertStatus(200);
        $this->assertFalse($list()['can_add']);
        $response = $this->send($store('Third'));
        $response->assertStatus(409);
        $this->assertSame(lang('Store.limitReached', [2, 'Boost']), $this->body($response)['message']);

        // back on free, both stores stay and can still be edited, but none is added
        $this->db->table('subscription_master')->where('business_id', $this->businessId)->update(['subscription_status' => 'S']);
        $publicId = $this->db->table('business_store')->where('store_name', 'Second')->get()->getRow()->public_id;
        $this->send(['public_id' => $publicId, 'store' => ['store_name' => 'Second Online']], null, 'v1/client/store/edit')->assertStatus(200);
        $this->send($store('Third'))->assertStatus(409);
        $this->assertSame(2, $this->db->table('business_store')->where('business_id', $this->businessId)->countAllResults());
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
