<?php

use App\Models\BusinessTypeModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\PlanLimits;
use Config\Services;

/**
 * /client/payment-methods, /client/payment-method/create and /client/payment-method/edit: the active owners and
 * managers of a business list, create and edit the ways its customers can pay, at every store or at one
 *
 * @internal
 */
final class PaymentMethodsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'App'; // the app's own migrations, the schema of the real database

    private string $token;
    private int $businessId;
    private string $bangkok;
    private string $pattaya;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['jwt', 'authContext'] as $service) {
            Services::resetSingle($service);
        }
        config(PlanLimits::class)->plans['free'][PlanLimits::STORES] = 10;
        $this->token = $this->createClient('owner@example.com')['token'];

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
        $this->bangkok    = $this->createStore('Bangkok');
        $this->pattaya    = $this->createStore('Pattaya');
    }

    public function testBusinessWideAndStoreMethodsAreCreatedAndListed(): void
    {
        $response = $this->send([
            'business_slug'  => 'otter-cafe-th',
            'payment_method' => ['method_code' => 'cash', 'handler_code' => 'instruction', 'method_name' => ' Cash ', 'instruction' => 'Bring cash and pay at the counter before the service'],
            'translations'   => ['th' => ['method_name' => 'เงินสด', 'instruction' => 'ชำระเงินสดที่เคาน์เตอร์ก่อนรับบริการ'], 'ja' => ['method_name' => '']],
        ]);
        $response->assertStatus(200);
        $cash = $this->body($response)['data']['public_id'];
        $this->assertMatchesRegularExpression('/^[0-9A-Za-z_-]{12}$/', $cash);

        // a QR needs no instruction; the PromptPay ID is kept without its dashes
        $qr = $this->body($this->send([
            'business_slug'  => 'otter-cafe-th',
            'payment_method' => ['store_public_id' => $this->bangkok, 'method_code' => 'PromptPay', 'handler_code' => 'promptpay_qr', 'method_name' => 'PromptPay QR', 'settings' => ['promptpay_id' => '081-234-5678', 'ignored' => 'x'], 'sort_order' => 1],
        ]))['data']['public_id'];

        $this->send([
            'business_slug'  => 'otter-cafe-th',
            'payment_method' => ['store_public_id' => $this->pattaya, 'method_code' => 'bank_transfer', 'handler_code' => 'instruction', 'method_name' => 'Bank transfer', 'instruction' => 'Transfer to KBank 123-4-56789-0, OtterCafe Co., Ltd.', 'is_active' => 'n'],
        ])->assertStatus(200);

        $response = $this->send(['business_slug' => 'otter-cafe-th'], null, 'v1/client/payment-methods');
        $response->assertStatus(200);
        $data = $this->body($response)['data'];
        $this->assertSame(['Bank transfer', 'Cash', 'PromptPay QR'], array_column($data['payment_methods'], 'method_name')); // by sort_order, then name
        [$transfer, $cashRow, $qrRow] = $data['payment_methods'];
        $this->assertSame([$cash, null, 'cash', 'instruction', 'M', 'Y', 0, ['th' => ['method_name' => 'เงินสด', 'instruction' => 'ชำระเงินสดที่เคาน์เตอร์ก่อนรับบริการ']]],
            [$cashRow['public_id'], $cashRow['store_public_id'], $cashRow['method_code'], $cashRow['handler_code'], $cashRow['confirmation'], $cashRow['is_active'], $cashRow['sort_order'], $cashRow['translations']]);
        $this->assertSame([$qr, $this->bangkok, 'promptpay', null, ['promptpay_id' => '0812345678']],
            [$qrRow['public_id'], $qrRow['store_public_id'], $qrRow['method_code'], $qrRow['instruction'], $qrRow['settings']]);
        $this->assertSame([$this->pattaya, 'N', []], [$transfer['store_public_id'], $transfer['is_active'], $transfer['settings']]);
        $this->assertArrayNotHasKey('id', $cashRow);
        $this->assertArrayNotHasKey('secret_json', $cashRow);
        $this->assertSame(['methods' => ['promptpay'], 'confirmation' => 'M', 'instruction_required' => false, 'settings' => ['promptpay_id']], $data['options']['handlers']['promptpay_qr']);

        // at a store: the business-wide methods and its own
        $atBangkok = $this->body($this->send(['business_slug' => 'otter-cafe-th', 'store_public_id' => $this->bangkok], null, 'v1/client/payment-methods'))['data']['payment_methods'];
        $this->assertSame(['Cash', 'PromptPay QR'], array_column($atBangkok, 'method_name'));
        $this->send(['business_slug' => 'otter-cafe-th', 'store_public_id' => 'nosuchstore1'], null, 'v1/client/payment-methods')->assertStatus(404);

        $this->assertSame('{"promptpay_id":"0812345678"}', $this->db->table('business_payment_method')->where('public_id', $qr)->get()->getRow()->settings_json);
        $this->assertSame(3, $this->db->table('log_activity')->where('table_involved', 'business_payment_method')->countAllResults());
    }

    public function testFieldsAreCheckedAgainstTheConfig(): void
    {
        $errors = $this->errorsOf(['method_code' => 'cheque', 'handler_code' => 'instruction', 'method_name' => '', 'instruction' => 'x']);
        $this->assertSame(['method_name', 'method_code'], array_keys($errors));

        // cash cannot be a PromptPay QR
        $errors = $this->errorsOf(['method_code' => 'cash', 'handler_code' => 'promptpay_qr', 'method_name' => 'Cash', 'settings' => ['promptpay_id' => '0812345678']]);
        $this->assertSame(['handler_code' => lang('BusinessPaymentMethod.handlerMismatch')], $errors);

        $errors = $this->errorsOf(['method_code' => 'cash', 'handler_code' => 'instruction', 'method_name' => 'Cash', 'instruction' => '  ']);
        $this->assertSame(['instruction' => lang('BusinessPaymentMethod.instructionRequired')], $errors);

        foreach ([null, '12345', '1234567890'] as $promptPayId) {
            $errors = $this->errorsOf(['method_code' => 'promptpay', 'handler_code' => 'promptpay_qr', 'method_name' => 'PromptPay', 'settings' => ['promptpay_id' => $promptPayId]]);
            $this->assertSame(['settings.promptpay_id'], array_keys($errors), (string) $promptPayId);
        }
        foreach (['1234567890123', '123456789012345'] as $promptPayId) { // a national or tax ID, an e-wallet ID
            $this->send(['business_slug' => 'otter-cafe-th', 'payment_method' => ['method_code' => 'promptpay', 'handler_code' => 'promptpay_qr', 'method_name' => 'PromptPay ' . $promptPayId, 'settings' => ['promptpay_id' => $promptPayId]]])->assertStatus(200);
        }

        $errors = $this->errorsOf(['method_code' => 'cash', 'handler_code' => 'instruction', 'method_name' => 'Cash', 'instruction' => 'x', 'is_active' => 'X', 'sort_order' => 256, 'settings' => ['a', 'b']]);
        $this->assertSame(['is_active', 'sort_order', 'settings'], array_keys($errors));

        $errors = $this->errorsOf(['method_code' => 'cash', 'handler_code' => 'instruction', 'method_name' => 'Cash', 'instruction' => 'x'], ['th' => ['instruction' => 'จ่ายที่เคาน์เตอร์'], 'fr' => ['method_name' => 'Espèces']]);
        $this->assertSame(['translations.th.method_name' => lang('Translation.nameRequired'), 'translations.fr' => lang('Translation.unknownLanguage')], $errors);

        $this->send(['payment_method' => ['method_code' => 'cash']])->assertStatus(400);
        $this->send(['business_slug' => 'nobody', 'payment_method' => ['method_code' => 'cash']])->assertStatus(404);
        $this->send(['business_slug' => 'otter-cafe-th', 'payment_method' => ['cash']])->assertStatus(422);
    }

    public function testAStoreOfAnotherBusinessIsRefused(): void
    {
        // a copy of the test's business under another slug
        $other = $this->db->table('business_master')->where('id', $this->businessId)->get()->getRowArray();
        unset($other['id'], $other['created_at'], $other['updated_at']);
        $this->db->table('business_master')->insert(['business_name' => 'Other', 'business_slug' => 'other'] + $other);
        $otherId = (int) $this->db->insertID();
        $this->db->table('business_store')->insert(['public_id' => 'otherstore01', 'business_id' => $otherId, 'store_name' => 'Elsewhere', 'store_type' => 'P', 'store_timezone' => 'Asia/Bangkok']);

        $errors = $this->errorsOf(['store_public_id' => 'otherstore01', 'method_code' => 'cash', 'handler_code' => 'instruction', 'method_name' => 'Cash', 'instruction' => 'x']);
        $this->assertSame(['store_public_id' => lang('PaymentMethod.storeNotFound')], $errors);
    }

    public function testNamesMayRepeatOnlyAcrossStores(): void
    {
        $transfer = ['method_code' => 'bank_transfer', 'handler_code' => 'instruction', 'method_name' => 'Bank transfer', 'instruction' => 'Transfer to KBank'];
        $this->send(['business_slug' => 'otter-cafe-th', 'payment_method' => ['store_public_id' => $this->bangkok] + $transfer])->assertStatus(200);
        $this->send(['business_slug' => 'otter-cafe-th', 'payment_method' => ['store_public_id' => $this->pattaya] + $transfer])->assertStatus(200);
        $this->assertSame(['method_name' => lang('PaymentMethod.nameTaken')], $this->errorsOf(['store_public_id' => $this->bangkok] + $transfer));
        // a business-wide one would be shown beside both stores' own
        $this->assertSame(['method_name' => lang('PaymentMethod.nameTaken')], $this->errorsOf($transfer));

        $this->send(['business_slug' => 'otter-cafe-th', 'payment_method' => ['method_code' => 'cash', 'handler_code' => 'instruction', 'method_name' => 'Cash', 'instruction' => 'Pay at the counter']])->assertStatus(200);
        $this->assertSame(['method_name' => lang('PaymentMethod.nameTaken')], $this->errorsOf(['store_public_id' => $this->pattaya, 'method_code' => 'cash', 'handler_code' => 'instruction', 'method_name' => 'Cash', 'instruction' => 'x']));
    }

    public function testEditKeepsWhatIsLeftOut(): void
    {
        $publicId = $this->body($this->send([
            'business_slug'  => 'otter-cafe-th',
            'payment_method' => ['store_public_id' => $this->bangkok, 'method_code' => 'promptpay', 'handler_code' => 'promptpay_qr', 'method_name' => 'PromptPay', 'instruction' => 'Scan, pay and send us the slip', 'settings' => ['promptpay_id' => '0812345678'], 'sort_order' => 3],
            'translations'   => ['th' => ['method_name' => 'พร้อมเพย์'], 'ja' => ['method_name' => 'プロンプトペイ']],
        ]))['data']['public_id'];

        // only the name changes: the store, the settings and the translations stay
        $this->send(['public_id' => $publicId, 'payment_method' => ['method_name' => 'PromptPay QR']], null, 'v1/client/payment-method/edit')->assertStatus(200);
        $row = $this->rowOf($publicId);
        $this->assertSame(['PromptPay QR', 'promptpay_qr', '{"promptpay_id":"0812345678"}', '3', 2], [$row['method_name'], $row['handler_code'], $row['settings_json'], (string) $row['sort_order'], $this->translationCount($row['id'])]);
        $this->assertNotNull($row['store_id']);

        // to plain text at every store: the QR's settings go, an instruction is needed and kept; translations replaced
        $response = $this->send(['public_id' => $publicId, 'payment_method' => ['store_public_id' => null, 'handler_code' => 'instruction'], 'translations' => ['th' => ['method_name' => 'พร้อมเพย์', 'instruction' => 'โอนไปที่ 081-234-5678']]], null, 'v1/client/payment-method/edit');
        $response->assertStatus(200);
        $row = $this->rowOf($publicId);
        $this->assertSame([null, 'instruction', null, 'Scan, pay and send us the slip', 1], [$row['store_id'], $row['handler_code'], $row['settings_json'], $row['instruction'], $this->translationCount($row['id'])]);

        // back to a QR without a PromptPay ID: refused, nothing written
        $response = $this->send(['public_id' => $publicId, 'payment_method' => ['handler_code' => 'promptpay_qr']], null, 'v1/client/payment-method/edit');
        $response->assertStatus(422);
        $this->assertSame('instruction', $this->rowOf($publicId)['handler_code']);

        $this->send(['payment_method' => []], null, 'v1/client/payment-method/edit')->assertStatus(400);
        $this->send(['public_id' => 'nosuchmethod', 'payment_method' => []], null, 'v1/client/payment-method/edit')->assertStatus(404);
    }

    public function testOnlyOwnersAndManagersMayManage(): void
    {
        $publicId = $this->body($this->send(['business_slug' => 'otter-cafe-th', 'payment_method' => ['method_code' => 'cash', 'handler_code' => 'instruction', 'method_name' => 'Cash', 'instruction' => 'x']]))['data']['public_id'];

        $manager = $this->createClient('manager@example.com', 'M')['token'];
        $this->send(['public_id' => $publicId, 'payment_method' => ['sort_order' => 2]], $manager, 'v1/client/payment-method/edit')->assertStatus(200);

        $employee = $this->createClient('employee@example.com', 'E')['token'];
        $this->send(['business_slug' => 'otter-cafe-th'], $employee, 'v1/client/payment-methods')->assertStatus(403);
        $this->send(['public_id' => $publicId, 'payment_method' => ['sort_order' => 1]], $employee, 'v1/client/payment-method/edit')->assertStatus(403);

        $former = $this->createClient('former@example.com', 'M', 'T')['token'];
        $this->send(['public_id' => $publicId, 'payment_method' => ['sort_order' => 1]], $former, 'v1/client/payment-method/edit')->assertStatus(404);
        $this->send(['business_slug' => 'otter-cafe-th'], $this->createClient('stranger@example.com')['token'], 'v1/client/payment-methods')->assertStatus(404);
        $this->assertSame('2', (string) $this->rowOf($publicId)['sort_order']);
    }

    /**
     * The errors of a create that is refused with 422
     */
    private function errorsOf(array $method, ?array $translations = null): array
    {
        $body = ['business_slug' => 'otter-cafe-th', 'payment_method' => $method];
        if ($translations !== null) {
            $body['translations'] = $translations;
        }
        $response = $this->send($body);
        $response->assertStatus(422);

        return $this->body($response)['errors'];
    }

    private function rowOf(string $publicId): array
    {
        return $this->db->table('business_payment_method')->where('public_id', $publicId)->get()->getRowArray();
    }

    private function translationCount(int|string $id): int
    {
        return $this->db->table('business_payment_method_translation')->where('payment_method_id', $id)->countAllResults();
    }

    private function createStore(string $name): string
    {
        $response = $this->send(['business_slug' => 'otter-cafe-th', 'store' => ['store_name' => $name, 'store_type' => 'P']], null, 'v1/client/store/create');
        $response->assertStatus(200);

        return $this->body($response)['data']['public_id'];
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

    private function send(array $body, ?string $token = null, string $path = 'v1/client/payment-method/create'): TestResponse
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
