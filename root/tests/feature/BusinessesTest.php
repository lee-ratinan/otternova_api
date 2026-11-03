<?php

use App\Libraries\FieldEncryption;
use App\Models\BusinessTypeModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\Services;

/**
 * /client/business/create and /client/business/edit: a client creates a business they own, with its names in
 * other languages, and its owners edit it
 *
 * @internal
 */
final class BusinessesTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'App'; // the app's own migrations, the schema of the real database

    /** The signed-in client account calling the endpoint */
    private array $client;
    private int $clientId;
    private string $token;

    /** public_id of the business type */
    private string $typeId;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['jwt', 'authContext'] as $service) {
            Services::resetSingle($service);
        }
        $this->client = $this->createAccount('owner@example.com', 'C');
        $this->db->table('client_master')->insert(['auth_id' => $this->client['id']]);
        $this->clientId = (int) $this->db->insertID();
        $this->token    = service('jwt')->issue($this->client)['access_token'];

        $staff        = $this->createAccount('admin@otternova.com', 'S');
        $types        = new BusinessTypeModel();
        $this->typeId = $types->publicIdOf($types->createWithNames(['type_code' => 'cafe'], ['en-US' => 'Café', 'th' => 'คาเฟ่'], (int) $staff['id']));
    }

    public function testOnlyClientsGetIn(): void
    {
        $this->withHeaders([])->post('v1/client/business/create')->assertStatus(401);

        $staff = $this->createAccount('staff@otternova.com', 'S');
        $this->send(['business' => $this->business()], service('jwt')->issue($staff)['access_token'])->assertStatus(403);
    }

    public function testBusinessIsCreatedWithItsOwnerAndTranslations(): void
    {
        $response = $this->send([
            'business'     => $this->business([
                'business_description'   => '  Coffee by the river.  ',
                'business_mood_and_tone' => 'Warm, playful, never pushy.',
                'country_code'           => 'sg',
                'currency_code'          => 'sgd',
                'tax_id'                 => '201912345A',
            ]),
            'translations' => ['th' => ' ออตเตอร์คาเฟ่ ', 'ja' => 'オッターカフェ', 'ko' => ''],
        ]);
        $response->assertStatus(200);
        $this->assertSame(['business_slug' => 'otter-cafe-sg'], $this->body($response)['data']);

        $row = $this->db->table('business_master')->where('business_slug', 'otter-cafe-sg')->get()->getRowArray();
        $this->assertSame('Coffee by the river.', $row['business_description']);
        $this->assertSame('Warm, playful, never pushy.', $row['business_mood_and_tone']);
        $this->assertSame(['SG', 'SGD'], [$row['country_code'], $row['currency_code']]);
        $this->assertNull($row['contact_phone_number']);
        $this->assertNotSame('201912345A', $row['tax_id']);
        $this->assertSame('201912345A', (new FieldEncryption())->decryptRow('business_master', $row)['tax_id']);

        $link = $this->db->table('business_client_link')->where('business_id', $row['id'])->get()->getRowArray();
        $this->assertSame([(string) $this->clientId, 'O', 'A'], [(string) $link['client_id'], $link['client_role'], $link['client_status']]);

        $names = $this->db->table('business_master_translation')->where('business_id', $row['id'])->orderBy('language_code')->get()->getResultArray();
        $this->assertSame(['ja' => 'オッターカフェ', 'th' => 'ออตเตอร์คาเฟ่'], array_column($names, 'business_name', 'language_code'));

        $logs = $this->db->table('log_activity')->where('changed_by_acc', $this->client['id'])->countAllResults();
        $this->assertSame(4, $logs); // the business, the owner link and two names
    }

    public function testLanguagesOutsideListLanguagesAreRefused(): void
    {
        $response = $this->send(['business' => $this->business(), 'translations' => ['fr' => 'Café Loutre', 'th' => ['x']]]);
        $response->assertStatus(422);
        $errors = $this->body($response)['errors'];
        $this->assertSame(lang('Business.unknownLanguage'), $errors['translations.fr']);
        $this->assertSame(lang('Business.invalidTranslation'), $errors['translations.th']);
        $this->assertSame(0, $this->db->table('business_master')->countAllResults());
    }

    public function testFieldsAreChecked(): void
    {
        $response = $this->send(['business' => $this->business(['business_slug' => 'Otter Cafe', 'contact_email_address' => 'nope', 'country_code' => 'FR'])]);
        $response->assertStatus(422);
        $this->assertSame(['business_slug', 'contact_email_address', 'country_code'], array_keys($this->body($response)['errors']));

        $response = $this->send(['business' => $this->business(['currency_code' => 'THB'])]);
        $response->assertStatus(422);
        $this->assertSame(['currency_code' => lang('BusinessMaster.invalidCurrency')], $this->body($response)['errors']);

        $this->send(['business' => $this->business()])->assertStatus(200);
        $response = $this->send(['business' => $this->business(['business_slug' => 'otter-cafe-2'])]);
        $response->assertStatus(422);
        $this->assertSame(['business_name' => lang('Business.nameTaken')], $this->body($response)['errors']);

        $this->send(['business' => 'otter', 'translations' => []])->assertStatus(422);
        $this->send(['business' => $this->business(), 'translations' => ['Otter']])->assertStatus(422);
    }

    // ---- edit ----

    public function testOwnerEditsTheBusinessAndItsNames(): void
    {
        $this->send(['business' => $this->business(['tax_id' => '201912345A']), 'translations' => ['th' => 'ออตเตอร์คาเฟ่', 'ja' => 'オッターカフェ']])->assertStatus(200);
        $id = (int) $this->db->table('business_master')->get()->getRow()->id;

        $response = $this->send([
            'business_slug' => 'otter-cafe-sg',
            'business'      => ['business_slug' => 'otter-cafe', 'business_description' => 'Coffee by the river.', 'contact_phone_number' => '+6561234567'],
            'translations'  => ['th' => 'ออตเตอร์คาเฟ่', 'ko' => '오터카페'],
        ], null, 'v1/client/business/edit');
        $response->assertStatus(200);
        $this->assertSame(['business_slug' => 'otter-cafe'], $this->body($response)['data']);

        $row = $this->db->table('business_master')->where('id', $id)->get()->getRowArray();
        $this->assertSame(['otter-cafe', 'Coffee by the river.', '+6561234567', 'OtterCafe'], [$row['business_slug'], $row['business_description'], $row['contact_phone_number'], $row['business_name']]);
        $this->assertSame('201912345A', (new FieldEncryption())->decryptRow('business_master', $row)['tax_id']); // left out, kept

        $names = $this->db->table('business_master_translation')->where('business_id', $id)->get()->getResultArray();
        $this->assertEqualsCanonicalizing(['ko' => '오터카페', 'th' => 'ออตเตอร์คาเฟ่'], array_column($names, 'business_name', 'language_code'));

        // only what changed is logged: the three columns, then the Korean name added and the Japanese one removed
        $logs = $this->db->table('log_activity')->where('changed_by_acc', $this->client['id'])->orderBy('id', 'DESC')->limit(3)->get()->getResultArray();
        $this->assertSame(['business_master_translation', 'business_master_translation', 'business_master'], array_column($logs, 'table_involved'));
        $this->assertSame(['business_slug', 'business_description', 'contact_phone_number'], array_keys(json_decode($logs[2]['activity_detail'], true)['changes']));

        // without "translations" the names stay
        $this->send(['business_slug' => 'otter-cafe', 'business' => ['business_name' => 'Otter Café']], null, 'v1/client/business/edit')->assertStatus(200);
        $this->assertSame(2, $this->db->table('business_master_translation')->where('business_id', $id)->countAllResults());
        $this->assertSame('Otter Café', $this->db->table('business_master')->where('id', $id)->get()->getRow()->business_name);
    }

    public function testBusinessTypeIsTakenByItsPublicId(): void
    {
        $response = $this->send(['business' => $this->business(['business_type_public_id' => 'zzzz'])]);
        $response->assertStatus(422);
        $this->assertSame(['business_type_public_id' => lang('Business.typeNotFound')], $this->body($response)['errors']);

        // the internal id is not taken
        $typeId   = $this->db->table('business_type')->get()->getRow()->id;
        $business = $this->business(['business_type_id' => $typeId]);
        unset($business['business_type_public_id']);
        $response = $this->send(['business' => $business]);
        $response->assertStatus(422);
        $this->assertSame(['business_type_public_id'], array_keys($this->body($response)['errors']));

        // left out of an edit, the type stays; given, it changes
        $this->send(['business' => $this->business()])->assertStatus(200);
        $staff  = $this->db->table('auth_master')->where('account_type', 'S')->get()->getRow()->id;
        $types  = new BusinessTypeModel();
        $salon  = $types->publicIdOf($types->createWithNames(['type_code' => 'salon'], ['en-US' => 'Salon', 'th' => 'ร้านเสริมสวย'], (int) $staff));
        $this->send(['business_slug' => 'otter-cafe-sg', 'business' => ['contact_city' => 'Singapore']], null, 'v1/client/business/edit')->assertStatus(200);
        $this->assertSame((string) $typeId, (string) $this->db->table('business_master')->get()->getRow()->business_type_id);
        $this->send(['business_slug' => 'otter-cafe-sg', 'business' => ['business_type_public_id' => $salon]], null, 'v1/client/business/edit')->assertStatus(200);
        $this->assertSame((string) $types->idOfPublicId($salon), (string) $this->db->table('business_master')->get()->getRow()->business_type_id);
    }

    public function testCountryAndCurrencyAreFixed(): void
    {
        $this->send(['business' => $this->business()])->assertStatus(200);

        $response = $this->send(['business_slug' => 'otter-cafe-sg', 'business' => ['country_code' => 'th', 'currency_code' => 'USD']], null, 'v1/client/business/edit');
        $response->assertStatus(422);
        $this->assertSame(['country_code' => lang('Business.fixedField'), 'currency_code' => lang('Business.fixedField')], $this->body($response)['errors']);

        // the same values again are no change
        $this->send(['business_slug' => 'otter-cafe-sg', 'business' => ['country_code' => 'sg', 'currency_code' => 'SGD']], null, 'v1/client/business/edit')->assertStatus(200);
    }

    public function testEditKeepsTheChecksOfCreate(): void
    {
        $this->send(['business' => $this->business()])->assertStatus(200);
        $this->send(['business' => $this->business(['business_name' => 'OtterSpa', 'business_slug' => 'otter-spa-sg'])])->assertStatus(200);

        $response = $this->send(['business_slug' => 'otter-spa-sg', 'business' => ['business_name' => 'OtterCafe', 'business_slug' => 'otter-cafe-sg']], null, 'v1/client/business/edit');
        $response->assertStatus(422);
        $errors = $this->body($response)['errors'];
        $this->assertArrayHasKey('business_slug', $errors);

        $response = $this->send(['business_slug' => 'otter-spa-sg', 'business' => ['business_name' => 'OtterCafe'], 'translations' => ['fr' => 'x']], null, 'v1/client/business/edit');
        $response->assertStatus(422);
        $this->assertSame(['translations.fr'], array_keys($this->body($response)['errors']));

        $response = $this->send(['business_slug' => 'otter-spa-sg', 'business' => ['business_name' => 'OtterCafe']], null, 'v1/client/business/edit');
        $response->assertStatus(422);
        $this->assertSame(['business_name' => lang('Business.nameTaken')], $this->body($response)['errors']);

        // renaming to its own name is not taken
        $this->send(['business_slug' => 'otter-spa-sg', 'business' => ['business_name' => 'OtterSpa', 'business_slug' => 'otter-spa-sg']], null, 'v1/client/business/edit')->assertStatus(200);

        $this->send(['business' => []], null, 'v1/client/business/edit')->assertStatus(400);
        $this->send(['business_slug' => 'otter-spa-sg', 'translations' => ['x']], null, 'v1/client/business/edit')->assertStatus(422);
    }

    public function testOnlyActiveOwnersEdit(): void
    {
        $this->send(['business' => $this->business()])->assertStatus(200);
        $id   = (int) $this->db->table('business_master')->get()->getRow()->id;
        $edit = ['business_slug' => 'otter-cafe-sg', 'business' => ['business_description' => 'Hacked']];

        $this->send(['business_slug' => 'nobody-sg', 'business' => []], null, 'v1/client/business/edit')->assertStatus(404);

        $stranger = $this->createClient('stranger@example.com');
        $this->send($edit, $stranger['token'], 'v1/client/business/edit')->assertStatus(404);

        $manager = $this->createClient('manager@example.com');
        $this->db->table('business_client_link')->insert(['business_id' => $id, 'client_id' => $manager['client_id'], 'client_role' => 'M', 'client_status' => 'A', 'start_date' => '2026-01-01']);
        $response = $this->send($edit, $manager['token'], 'v1/client/business/edit');
        $response->assertStatus(403);
        $this->assertSame(lang('Business.ownerOnly'), $this->body($response)['message']);

        $former = $this->createClient('former@example.com');
        $this->db->table('business_client_link')->insert(['business_id' => $id, 'client_id' => $former['client_id'], 'client_role' => 'O', 'client_status' => 'T', 'start_date' => '2026-01-01', 'termination_date' => '2026-02-01']);
        $this->send($edit, $former['token'], 'v1/client/business/edit')->assertStatus(404);

        $this->assertNull($this->db->table('business_master')->where('id', $id)->get()->getRow()->business_description);
    }

    private function business(array $overrides = []): array
    {
        return $overrides + [
            'business_type_public_id' => $this->typeId,
            'business_name'         => 'OtterCafe',
            'business_slug'         => 'otter-cafe-sg',
            'contact_email_address' => 'hello@ottercafe.sg',
            'country_code'          => 'SG',
            'currency_code'         => 'SGD',
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

    /**
     * @return array{client_id: int, token: string}
     */
    private function createClient(string $email): array
    {
        $account = $this->createAccount($email, 'C');
        $this->db->table('client_master')->insert(['auth_id' => $account['id']]);

        return ['client_id' => (int) $this->db->insertID(), 'token' => service('jwt')->issue($account)['access_token']];
    }

    private function send(array $body, ?string $token = null, string $path = 'v1/client/business/create'): TestResponse
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
