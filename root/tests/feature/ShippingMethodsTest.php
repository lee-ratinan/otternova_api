<?php

use App\Models\BusinessTypeModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\PlanLimits;
use Config\Services;

/**
 * /client/shipping-methods, /client/shipping-method/create and /client/shipping-method/edit: the active owners and
 * managers of a business list, create and edit the ways its customers can get its products, and what each costs
 *
 * @internal
 */
final class ShippingMethodsTest extends CIUnitTestCase
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

    public function testMethodsAreCreatedAndListedWithTheirRates(): void
    {
        // by weight: 50.00 up to 1 kg, then 15.00 per kg or part of one; free from 1,000.00; brackets sent out of order
        $response = $this->send([
            'business_slug'   => 'otter-cafe-th',
            'shipping_method' => ['method_type' => 'h', 'method_name' => ' Kerry Express ', 'fee_basis' => 'W', 'free_over_amount' => 1000, 'eta_days_min' => 1, 'eta_days_max' => '3'],
            'rates'           => [
                ['range_from' => 1000, 'fee_amount' => '50', 'step_size' => 1000, 'step_fee' => 15],
                ['range_to' => '1000', 'fee_amount' => 50.5],
            ],
            'translations'    => ['th' => ['method_name' => 'เคอรี่ เอ็กซ์เพรส', 'description' => 'ส่งถึงบ้าน'], 'ja' => ['method_name' => '']],
        ]);
        $response->assertStatus(200);
        $kerry = $this->body($response)['data']['public_id'];
        $this->assertMatchesRegularExpression('/^[0-9A-Za-z_-]{12}$/', $kerry);

        // store pickup at Bangkok: no brackets, free
        $pickup = $this->body($this->send([
            'business_slug'   => 'otter-cafe-th',
            'shipping_method' => ['store_public_id' => $this->bangkok, 'method_type' => 'S', 'method_name' => 'Pick up at the store', 'sort_order' => 1],
        ]))['data']['public_id'];

        $response = $this->send(['business_slug' => 'otter-cafe-th'], null, 'v1/client/shipping-methods');
        $response->assertStatus(200);
        $data = $this->body($response)['data'];
        $this->assertSame(['Kerry Express', 'Pick up at the store'], array_column($data['shipping_methods'], 'method_name'));
        [$kerryRow, $pickupRow] = $data['shipping_methods'];
        $this->assertSame([$kerry, null, 'H', 'W', '1000.00', 1, 3, 'Y', 0, ['th' => ['method_name' => 'เคอรี่ เอ็กซ์เพรส', 'description' => 'ส่งถึงบ้าน']]],
            [$kerryRow['public_id'], $kerryRow['store_public_id'], $kerryRow['method_type'], $kerryRow['fee_basis'], $kerryRow['free_over_amount'], $kerryRow['eta_days_min'], $kerryRow['eta_days_max'], $kerryRow['is_active'], $kerryRow['sort_order'], $kerryRow['translations']]);
        $this->assertSame([
            ['range_from' => '0.00', 'range_to' => '1000.00', 'fee_amount' => '50.50', 'step_size' => null, 'step_fee' => null],
            ['range_from' => '1000.00', 'range_to' => null, 'fee_amount' => '50.00', 'step_size' => '1000.00', 'step_fee' => '15.00'],
        ], $kerryRow['rates']);
        $this->assertSame([$pickup, $this->bangkok, 'S', 'P', null, null, []], [$pickupRow['public_id'], $pickupRow['store_public_id'], $pickupRow['method_type'], $pickupRow['fee_basis'], $pickupRow['free_over_amount'], $pickupRow['eta_days_min'], $pickupRow['rates']]);
        $this->assertArrayNotHasKey('id', $kerryRow);
        $this->assertSame(['method_types' => ['H', 'L', 'S'], 'fee_bases' => ['P', 'W'], 'rates_max' => 50], $data['options']);

        // at a store: the business-wide methods and its own
        $atPattaya = $this->body($this->send(['business_slug' => 'otter-cafe-th', 'store_public_id' => $this->pattaya], null, 'v1/client/shipping-methods'))['data']['shipping_methods'];
        $this->assertSame(['Kerry Express'], array_column($atPattaya, 'method_name'));
        $this->send(['business_slug' => 'otter-cafe-th', 'store_public_id' => 'nosuchstore1'], null, 'v1/client/shipping-methods')->assertStatus(404);

        // the method, its two brackets, its translation and the pickup
        $this->assertSame(2, $this->db->table('log_activity')->where('table_involved', 'business_shipping_method')->countAllResults());
        $this->assertSame(2, $this->db->table('log_activity')->where('table_involved', 'business_shipping_rate')->countAllResults());
    }

    public function testFieldsAreChecked(): void
    {
        $errors = $this->errorsOf(['method_type' => 'X', 'method_name' => '', 'fee_basis' => 'Q', 'free_over_amount' => '-1', 'eta_days_min' => 5, 'eta_days_max' => 2, 'is_active' => 'X', 'sort_order' => 256]);
        $this->assertSame(['method_type', 'method_name', 'fee_basis', 'is_active', 'sort_order', 'free_over_amount', 'eta_days_max'], array_keys($errors));

        $errors = $this->errorsOf(['method_type' => 'H', 'method_name' => 'Flash', 'free_over_amount' => '10.555'], null, []);
        $this->assertSame(['free_over_amount' => lang('BusinessShippingMethod.invalidAmount')], $errors);

        $errors = $this->errorsOf(['method_type' => 'H', 'method_name' => 'Flash'], null, ['translations' => ['th' => ['description' => 'ส่งด่วน'], 'fr' => ['method_name' => 'Flash']]]);
        $this->assertSame(['translations.th.method_name' => lang('Translation.nameRequired'), 'translations.fr' => lang('Translation.unknownLanguage')], $errors);

        $this->send(['shipping_method' => ['method_type' => 'H']])->assertStatus(400);
        $this->send(['business_slug' => 'nobody', 'shipping_method' => ['method_type' => 'H']])->assertStatus(404);
        $this->send(['business_slug' => 'otter-cafe-th', 'shipping_method' => ['H']])->assertStatus(422);
    }

    public function testRatesAreChecked(): void
    {
        $flash = ['method_type' => 'H', 'method_name' => 'Flash'];

        $this->assertSame(['rates' => lang('BusinessShippingMethod.invalidRates')], $this->errorsOf($flash, ['range_from' => 0]));
        $this->assertSame(['rates.0' => lang('BusinessShippingMethod.invalidRate')], $this->errorsOf($flash, ['x']));
        $this->assertSame(['rates' => lang('BusinessShippingMethod.tooManyRates', [50])], $this->errorsOf($flash, array_fill(0, 51, ['fee_amount' => 1])));

        $errors = $this->errorsOf($flash, [['range_from' => -1, 'fee_amount' => 'abc'], ['range_from' => 500, 'range_to' => 500], ['range_from' => 600, 'step_size' => 100], ['range_from' => 700, 'range_to' => 800, 'step_size' => 0, 'step_fee' => 5]]);
        $this->assertSame(['rates.0.range_from', 'rates.0.fee_amount', 'rates.1.range_to', 'rates.2.step_fee', 'rates.3.step_size'], array_keys($errors));

        // overlapping, and an open-ended bracket before another
        $errors = $this->errorsOf($flash, [['range_from' => 0, 'range_to' => 600, 'fee_amount' => 60], ['range_from' => 500, 'fee_amount' => 40], ['range_from' => 1000, 'fee_amount' => 0]]);
        $this->assertSame(['rates.1.range_from' => lang('BusinessShippingMethod.rangeOverlap'), 'rates.2.range_from' => lang('BusinessShippingMethod.rangeOverlap')], $errors);

        // grams are whole; a gap between brackets is allowed
        $errors = $this->errorsOf(['fee_basis' => 'W'] + $flash, [['range_to' => '500.5', 'fee_amount' => 30]]);
        $this->assertSame(['rates.0.range_to' => lang('BusinessShippingMethod.invalidGrams')], $errors);
        $this->send(['business_slug' => 'otter-cafe-th', 'shipping_method' => ['fee_basis' => 'W'] + $flash, 'rates' => [['range_to' => 500, 'fee_amount' => 30], ['range_from' => 1000, 'range_to' => 5000, 'fee_amount' => 60]]])->assertStatus(200);
        $this->assertSame(2, $this->db->table('business_shipping_rate')->countAllResults());
    }

    public function testAStoreOfAnotherBusinessIsRefused(): void
    {
        $other = $this->db->table('business_master')->where('id', $this->businessId)->get()->getRowArray();
        unset($other['id'], $other['created_at'], $other['updated_at']);
        $this->db->table('business_master')->insert(['business_name' => 'Other', 'business_slug' => 'other'] + $other);
        $otherId = (int) $this->db->insertID();
        $this->db->table('business_store')->insert(['public_id' => 'otherstore01', 'business_id' => $otherId, 'store_name' => 'Elsewhere', 'store_type' => 'P', 'store_timezone' => 'Asia/Bangkok']);

        $errors = $this->errorsOf(['store_public_id' => 'otherstore01', 'method_type' => 'S', 'method_name' => 'Pickup']);
        $this->assertSame(['store_public_id' => lang('ShippingMethod.storeNotFound')], $errors);
    }

    public function testNamesMayRepeatOnlyAcrossStores(): void
    {
        $pickup = ['method_type' => 'S', 'method_name' => 'Store pickup'];
        $this->send(['business_slug' => 'otter-cafe-th', 'shipping_method' => ['store_public_id' => $this->bangkok] + $pickup])->assertStatus(200);
        $this->send(['business_slug' => 'otter-cafe-th', 'shipping_method' => ['store_public_id' => $this->pattaya] + $pickup])->assertStatus(200);
        $this->assertSame(['method_name' => lang('ShippingMethod.nameTaken')], $this->errorsOf(['store_public_id' => $this->bangkok] + $pickup));
        $this->assertSame(['method_name' => lang('ShippingMethod.nameTaken')], $this->errorsOf($pickup));
    }

    public function testEditKeepsWhatIsLeftOutAndReplacesWhatIsSent(): void
    {
        $publicId = $this->body($this->send([
            'business_slug'   => 'otter-cafe-th',
            'shipping_method' => ['store_public_id' => $this->bangkok, 'method_type' => 'H', 'method_name' => 'Flash', 'free_over_amount' => '999', 'sort_order' => 3],
            'rates'           => [['range_to' => 500, 'fee_amount' => 60], ['range_from' => 500, 'fee_amount' => 40]],
            'translations'    => ['th' => ['method_name' => 'แฟลช'], 'ja' => ['method_name' => 'フラッシュ']],
        ]))['data']['public_id'];
        $logged = fn () => $this->db->table('log_activity')->where('table_involved', 'business_shipping_rate')->countAllResults();

        // only the name changes: the store, the rates and the translations stay
        $this->send(['public_id' => $publicId, 'shipping_method' => ['method_name' => 'Flash Express']], null, 'v1/client/shipping-method/edit')->assertStatus(200);
        $row = $this->rowOf($publicId);
        $this->assertSame(['Flash Express', '999.00', '3', 2, 2], [$row['method_name'], self::decimalOf($row['free_over_amount']), (string) $row['sort_order'], $this->rateCount($row['id']), $this->translationCount($row['id'])]);
        $this->assertNotNull($row['store_id']);

        // the same brackets sent again write nothing
        $this->send(['public_id' => $publicId, 'rates' => [['range_from' => '500.00', 'fee_amount' => '40.00'], ['range_to' => 500, 'fee_amount' => 60]]], null, 'v1/client/shipping-method/edit')->assertStatus(200);
        $this->assertSame(2, $logged());

        // a change of fee_basis needs new brackets
        $response = $this->send(['public_id' => $publicId, 'shipping_method' => ['fee_basis' => 'W']], null, 'v1/client/shipping-method/edit');
        $response->assertStatus(422);
        $this->assertSame(['rates' => lang('BusinessShippingMethod.ratesRequired')], $this->body($response)['errors']);
        $this->assertSame('P', $this->rowOf($publicId)['fee_basis']);

        // to every store, by weight, never free, one bracket; translations replaced
        $this->send(['public_id' => $publicId, 'shipping_method' => ['store_public_id' => null, 'fee_basis' => 'W', 'free_over_amount' => null], 'rates' => [['fee_amount' => 45]], 'translations' => ['th' => ['method_name' => 'แฟลช']]], null, 'v1/client/shipping-method/edit')->assertStatus(200);
        $row = $this->rowOf($publicId);
        $this->assertSame([null, 'W', null, 1, 1], [$row['store_id'], $row['fee_basis'], $row['free_over_amount'], $this->rateCount($row['id']), $this->translationCount($row['id'])]);
        $this->assertSame(5, $logged()); // 2 added, 2 deleted, 1 added

        // no brackets at all: free
        $this->send(['public_id' => $publicId, 'rates' => []], null, 'v1/client/shipping-method/edit')->assertStatus(200);
        $this->assertSame(0, $this->rateCount($row['id']));

        $this->send(['shipping_method' => []], null, 'v1/client/shipping-method/edit')->assertStatus(400);
        $this->send(['public_id' => 'nosuchmethod', 'shipping_method' => []], null, 'v1/client/shipping-method/edit')->assertStatus(404);
    }

    public function testOnlyOwnersAndManagersMayManage(): void
    {
        $publicId = $this->body($this->send(['business_slug' => 'otter-cafe-th', 'shipping_method' => ['method_type' => 'H', 'method_name' => 'Flash']]))['data']['public_id'];

        $manager = $this->createClient('manager@example.com', 'M')['token'];
        $this->send(['public_id' => $publicId, 'shipping_method' => ['sort_order' => 2]], $manager, 'v1/client/shipping-method/edit')->assertStatus(200);

        $employee = $this->createClient('employee@example.com', 'E')['token'];
        $this->send(['business_slug' => 'otter-cafe-th'], $employee, 'v1/client/shipping-methods')->assertStatus(403);
        $this->send(['public_id' => $publicId, 'shipping_method' => ['sort_order' => 1]], $employee, 'v1/client/shipping-method/edit')->assertStatus(403);

        $former = $this->createClient('former@example.com', 'M', 'T')['token'];
        $this->send(['public_id' => $publicId, 'shipping_method' => ['sort_order' => 1]], $former, 'v1/client/shipping-method/edit')->assertStatus(404);
        $this->send(['business_slug' => 'otter-cafe-th'], $this->createClient('stranger@example.com')['token'], 'v1/client/shipping-methods')->assertStatus(404);
        $this->assertSame('2', (string) $this->rowOf($publicId)['sort_order']);
    }

    /**
     * The errors of a create that is refused with 422
     */
    private function errorsOf(array $method, ?array $rates = null, array $extra = []): array
    {
        $body = ['business_slug' => 'otter-cafe-th', 'shipping_method' => $method] + $extra;
        if ($rates !== null) {
            $body['rates'] = $rates;
        }
        $response = $this->send($body);
        $response->assertStatus(422);

        return $this->body($response)['errors'];
    }

    private function rowOf(string $publicId): array
    {
        return $this->db->table('business_shipping_method')->where('public_id', $publicId)->get()->getRowArray();
    }

    private function rateCount(int|string $id): int
    {
        return $this->db->table('business_shipping_rate')->where('shipping_method_id', $id)->countAllResults();
    }

    private function translationCount(int|string $id): int
    {
        return $this->db->table('business_shipping_method_translation')->where('shipping_method_id', $id)->countAllResults();
    }

    /** SQLite gives DECIMAL back as it was stored */
    private static function decimalOf(mixed $value): ?string
    {
        return $value === null ? null : number_format((float) $value, 2, '.', '');
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

    private function send(array $body, ?string $token = null, string $path = 'v1/client/shipping-method/create'): TestResponse
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
