<?php

use App\Models\BusinessTypeModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\Services;

/**
 * /client/discounts, /client/discount/create and /client/discount/edit: the active owners and managers of a business
 * list, create and edit the discount codes its customers can use on the mart
 *
 * @internal
 */
final class DiscountsTest extends CIUnitTestCase
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
    }

    public function testDiscountsAreCreatedAndListed(): void
    {
        $response = $this->send(['business_slug' => 'otter-cafe-th', 'discount' => [
            'discount_code' => ' welcome10 ', 'description' => 'Welcome', 'discount_type' => 'p', 'discount_value' => '10',
            'valid_from' => '2026-12-01T00:00', 'expires_at' => '2026-12-31 17:00:00',
        ]]);
        $response->assertStatus(200);
        $welcome = $this->body($response)['data']['public_id'];
        $this->assertMatchesRegularExpression('/^[0-9A-Za-z_-]{12}$/', $welcome);

        $this->send(['business_slug' => 'otter-cafe-th', 'discount' => [
            'discount_code' => 'SPA-100', 'discount_type' => 'F', 'discount_value' => 100.5, 'applies_to' => 's',
            'min_subtotal' => '500', 'max_redemptions' => 20, 'is_active' => 'N',
        ]])->assertStatus(200);

        $response = $this->send(['business_slug' => 'otter-cafe-th'], null, 'v1/client/discounts');
        $response->assertStatus(200);
        $data = $this->body($response)['data'];
        $this->assertSame('THB', $data['currency_code']);
        $this->assertSame(['SPA-100', 'WELCOME10'], array_column($data['discounts'], 'discount_code'));
        [$spa, $welcomeRow] = $data['discounts'];
        $this->assertSame([$welcome, 'Welcome', 'P', '10.00', 'A', null, null, 0, '2026-12-01 00:00:00', '2026-12-31 17:00:00', 'Y'], [
            $welcomeRow['public_id'], $welcomeRow['description'], $welcomeRow['discount_type'], $welcomeRow['discount_value'], $welcomeRow['applies_to'],
            $welcomeRow['min_subtotal'], $welcomeRow['max_redemptions'], $welcomeRow['redemption_count'], $welcomeRow['valid_from'], $welcomeRow['expires_at'], $welcomeRow['is_active']]);
        $this->assertSame([null, 'F', '100.50', 'S', '500.00', 20, null, 'N'], [
            $spa['description'], $spa['discount_type'], $spa['discount_value'], $spa['applies_to'], $spa['min_subtotal'], $spa['max_redemptions'], $spa['expires_at'], $spa['is_active']]);
        $this->assertArrayNotHasKey('id', $spa);
        $this->assertSame(2, $this->db->table('log_activity')->where('table_involved', 'business_discount')->countAllResults());
    }

    public function testFieldsAreChecked(): void
    {
        $errors = $this->errorsOf(['discount_code' => 'a b', 'discount_type' => 'X', 'discount_value' => '0', 'applies_to' => 'Z', 'is_active' => 'X']);
        $this->assertSame(['discount_type', 'applies_to', 'is_active', 'discount_code', 'discount_value'], array_keys($errors));

        $errors = $this->errorsOf(['discount_code' => 'BIG', 'discount_type' => 'P', 'discount_value' => '100.01']);
        $this->assertSame(['discount_value' => lang('BusinessDiscount.percentTooHigh')], $errors);
        $this->send(['business_slug' => 'otter-cafe-th', 'discount' => ['discount_code' => 'FREE', 'discount_type' => 'P', 'discount_value' => '100']])->assertStatus(200);

        $errors = $this->errorsOf(['discount_code' => 'AMT', 'discount_type' => 'F', 'discount_value' => '1.234', 'min_subtotal' => '-5', 'max_redemptions' => 0]);
        $this->assertSame(['max_redemptions', 'discount_value', 'min_subtotal'], array_keys($errors));

        $errors = $this->errorsOf(['discount_code' => 'DATES', 'discount_type' => 'F', 'discount_value' => '5', 'valid_from' => '2026-02-30 00:00', 'expires_at' => 'soon']);
        $this->assertSame(['valid_from', 'expires_at'], array_keys($errors));
        $errors = $this->errorsOf(['discount_code' => 'DATES', 'discount_type' => 'F', 'discount_value' => '5', 'valid_from' => '2026-12-02 00:00', 'expires_at' => '2026-12-01 00:00']);
        $this->assertSame(['expires_at' => lang('BusinessDiscount.expiresBeforeValid')], $errors);

        // codes are told apart without regard to case
        $errors = $this->errorsOf(['discount_code' => 'free', 'discount_type' => 'F', 'discount_value' => '5']);
        $this->assertSame(['discount_code' => lang('Discount.codeTaken')], $errors);

        $this->send(['discount' => ['discount_code' => 'X']])->assertStatus(400);
        $this->send(['business_slug' => 'nobody', 'discount' => ['discount_code' => 'X']])->assertStatus(404);
        $this->send(['business_slug' => 'otter-cafe-th', 'discount' => ['X']])->assertStatus(422);
    }

    public function testEditKeepsWhatIsLeftOutAndTheRedemptions(): void
    {
        $publicId = $this->body($this->send(['business_slug' => 'otter-cafe-th', 'discount' => [
            'discount_code' => 'TEN', 'discount_type' => 'F', 'discount_value' => '10', 'min_subtotal' => '50', 'max_redemptions' => 10,
        ]]))['data']['public_id'];
        $this->db->table('business_discount')->where('public_id', $publicId)->update(['redemption_count' => 4]);

        $this->send(['public_id' => $publicId, 'discount' => ['description' => 'Ten off', 'redemption_count' => 0]], null, 'v1/client/discount/edit')->assertStatus(200);
        $row = $this->rowOf($publicId);
        $this->assertSame(['TEN', 'Ten off', 'F', '50', '10', '4'], [$row['discount_code'], $row['description'], $row['discount_type'],
            (string) (float) $row['min_subtotal'], (string) $row['max_redemptions'], (string) $row['redemption_count']]);

        $response = $this->send(['public_id' => $publicId, 'discount' => ['max_redemptions' => 3]], null, 'v1/client/discount/edit');
        $response->assertStatus(422);
        $this->assertSame(['max_redemptions'], array_keys($this->body($response)['errors']));

        // no limit, no minimum
        $this->send(['public_id' => $publicId, 'discount' => ['max_redemptions' => null, 'min_subtotal' => '']], null, 'v1/client/discount/edit')->assertStatus(200);
        $row = $this->rowOf($publicId);
        $this->assertSame([null, null], [$row['max_redemptions'], $row['min_subtotal']]);

        // only what changed is logged: the create and two edits
        $this->send(['public_id' => $publicId, 'discount' => ['discount_value' => '10.00']], null, 'v1/client/discount/edit')->assertStatus(200);
        $this->assertSame(3, $this->db->table('log_activity')->where('table_involved', 'business_discount')->countAllResults());

        $this->send(['public_id' => 'nosuchdisc01', 'discount' => []], null, 'v1/client/discount/edit')->assertStatus(404);
        $this->send(['discount' => []], null, 'v1/client/discount/edit')->assertStatus(400);
    }

    public function testOnlyOwnersAndManagersMayManage(): void
    {
        $publicId = $this->body($this->send(['business_slug' => 'otter-cafe-th', 'discount' => ['discount_code' => 'TEN', 'discount_type' => 'F', 'discount_value' => '10']]))['data']['public_id'];

        $manager = $this->createClient('manager@example.com', 'M')['token'];
        $this->send(['public_id' => $publicId, 'discount' => ['description' => 'By the manager']], $manager, 'v1/client/discount/edit')->assertStatus(200);

        $employee = $this->createClient('employee@example.com', 'E')['token'];
        $this->send(['business_slug' => 'otter-cafe-th'], $employee, 'v1/client/discounts')->assertStatus(403);
        $this->send(['public_id' => $publicId, 'discount' => ['description' => 'x']], $employee, 'v1/client/discount/edit')->assertStatus(403);

        $former = $this->createClient('former@example.com', 'M', 'T')['token'];
        $this->send(['public_id' => $publicId, 'discount' => ['description' => 'x']], $former, 'v1/client/discount/edit')->assertStatus(404);
        $this->send(['business_slug' => 'otter-cafe-th'], $this->createClient('stranger@example.com')['token'], 'v1/client/discounts')->assertStatus(404);
        $this->assertSame('By the manager', $this->rowOf($publicId)['description']);
    }

    /**
     * The errors of a create that is refused with 422
     */
    private function errorsOf(array $discount): array
    {
        $response = $this->send(['business_slug' => 'otter-cafe-th', 'discount' => $discount]);
        $response->assertStatus(422);

        return $this->body($response)['errors'];
    }

    private function rowOf(string $publicId): array
    {
        return $this->db->table('business_discount')->where('public_id', $publicId)->get()->getRowArray();
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

    private function send(array $body, ?string $token = null, string $path = 'v1/client/discount/create'): TestResponse
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
