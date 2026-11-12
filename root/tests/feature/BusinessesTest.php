<?php

use App\Libraries\FieldEncryption;
use App\Models\BusinessMasterModel;
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

    public function testAudienceDefaultsToTheGeneralPublicAndCanBeSet(): void
    {
        $this->send(['business' => $this->business()])->assertStatus(200);
        $row = $this->db->table('business_master')->where('business_slug', 'otter-cafe-sg')->get()->getRowArray();
        $this->assertSame(['GEN', 'GEN', 'N'], [$row['lgbt_audience'], $row['naturist_audience'], $row['age_restricted']]);

        $this->send(['business' => $this->business(['business_name' => 'Badger Spa', 'business_slug' => 'badger-spa-sg',
            'lgbt_audience' => 'gay', 'naturist_audience' => 'NUD', 'age_restricted' => 'y'])])->assertStatus(200);
        $row = $this->db->table('business_master')->where('business_slug', 'badger-spa-sg')->get()->getRowArray();
        $this->assertSame(['GAY', 'NUD', 'Y'], [$row['lgbt_audience'], $row['naturist_audience'], $row['age_restricted']]);

        $response = $this->send(['business' => $this->business(['business_slug' => 'otter-x', 'business_name' => 'X', 'lgbt_audience' => 'ALL', 'naturist_audience' => 'GEN', 'age_restricted' => 'Maybe'])]);
        $response->assertStatus(422);
        $this->assertSame(['lgbt_audience', 'age_restricted'], array_keys($this->body($response)['errors']));

        // edited, left out keeps its value; the session business carries them
        $this->send(['business_slug' => 'badger-spa-sg', 'business' => ['lgbt_audience' => 'FNDLY']], null, 'v1/client/business/edit')->assertStatus(200);
        $business = $this->body($this->send(['business_slug' => 'badger-spa-sg'], null, 'v1/client/business/switch'))['data']['business']['business'];
        $this->assertSame(['FNDLY', 'NUD', 'Y'], [$business['lgbt_audience'], $business['naturist_audience'], $business['age_restricted']]);
        $this->send(['business_slug' => 'badger-spa-sg', 'business' => ['age_restricted' => '']], null, 'v1/client/business/edit')->assertStatus(422);
    }

    public function testTipAndServiceChargeDefaultToNoneAndCanBeSet(): void
    {
        $columns = ['tip_policy', 'tip_value', 'tip_unit', 'tip_value_kind', 'service_charge_value', 'service_charge_unit'];
        $charges = fn (string $slug): array => array_values(BusinessMasterModel::withDecimals(array_intersect_key(
            $this->db->table('business_master')->where('business_slug', $slug)->get()->getRowArray(),
            array_flip($columns)
        )));

        $this->send(['business' => $this->business()])->assertStatus(200);
        $this->assertSame(['D', '0.00', 'P', 'S', '0.00', 'P'], $charges('otter-cafe-sg'));

        // numbers or strings, codes in any case
        $this->send(['business' => $this->business(['business_name' => 'Badger Spa', 'business_slug' => 'badger-spa-sg',
            'tip_policy' => 'm', 'tip_value' => 10, 'tip_unit' => 'p', 'tip_value_kind' => 'M',
            'service_charge_value' => '12.5', 'service_charge_unit' => 'F'])])->assertStatus(200);
        $this->assertSame(['M', '10.00', 'P', 'M', '12.50', 'F'], $charges('badger-spa-sg'));

        $refused = function (array $fields, array $expected): void {
            $response = $this->send(['business' => $this->business(['business_slug' => 'otter-x', 'business_name' => 'X'] + $fields)]);
            $response->assertStatus(422);
            $this->assertSame($expected, array_keys($this->body($response)['errors']));
        };
        $refused(['tip_policy' => 'YES', 'tip_value' => '-1', 'tip_unit' => 'X', 'tip_value_kind' => 'Q', 'service_charge_value' => 'ten', 'service_charge_unit' => ''],
            ['tip_policy', 'tip_value', 'tip_unit', 'tip_value_kind', 'service_charge_value']); // the unit left empty is P
        $refused(['tip_value' => '1.234'], ['tip_value']);
        $refused(['tip_policy' => 'O', 'tip_value' => '100.01', 'service_charge_value' => '101'], ['tip_value', 'service_charge_value']);
        $refused(['tip_value' => '5'], ['tip_value']); // disabled: nothing to suggest
        $refused(['tip_policy' => 'M', 'tip_value_kind' => 'M'], ['tip_value']); // required: a minimum above 0
        $refused(['tip_policy' => 'M', 'tip_value' => '5'], ['tip_value_kind']); // required: the value is the minimum
        $refused(['tip_policy' => 'OFF', 'tip_value' => '5', 'tip_value_kind' => 'M'], ['tip_value_kind']);

        // a fixed amount is not capped at 100; off-system may still suggest one
        $this->send(['business' => $this->business(['business_slug' => 'otter-y', 'business_name' => 'Y', 'tip_policy' => 'OFF',
            'tip_value' => '500', 'tip_unit' => 'F', 'service_charge_value' => '500', 'service_charge_unit' => 'F'])])->assertStatus(200);
        $this->assertSame(['OFF', '500.00', 'F', 'S', '500.00', 'F'], $charges('otter-y'));
    }

    public function testTipAndServiceChargeAreEditedAndGoOutWithTheSessionBusiness(): void
    {
        $this->send(['business' => $this->business(['tip_policy' => 'O', 'tip_value' => '15'])])->assertStatus(200);

        // left out keeps its value; checked against what is stored
        $this->send(['business_slug' => 'otter-cafe-sg', 'business' => ['service_charge_value' => 10]], null, 'v1/client/business/edit')->assertStatus(200);
        $this->send(['business_slug' => 'otter-cafe-sg', 'business' => ['tip_policy' => 'D']], null, 'v1/client/business/edit')->assertStatus(422);
        $this->send(['business_slug' => 'otter-cafe-sg', 'business' => ['tip_value' => '']], null, 'v1/client/business/edit')->assertStatus(422);

        $business = $this->body($this->send(['business_slug' => 'otter-cafe-sg'], null, 'v1/client/business/switch'))['data']['business']['business'];
        $this->assertSame(['O', '15.00', 'P', 'S', '10.00', 'P'], [$business['tip_policy'], $business['tip_value'], $business['tip_unit'],
            $business['tip_value_kind'], $business['service_charge_value'], $business['service_charge_unit']]);

        // an edit that changes nothing writes and logs nothing, whatever the driver gives the decimals back as
        $logs = $this->db->table('log_activity')->countAllResults();
        $this->send(['business_slug' => 'otter-cafe-sg', 'business' => ['tip_value' => '15.0', 'service_charge_value' => 10]], null, 'v1/client/business/edit')->assertStatus(200);
        $this->assertSame($logs, $this->db->table('log_activity')->countAllResults());
    }

    public function testBookingWindowDefaultsAndIsChecked(): void
    {
        $window = fn (string $slug): array => array_map('strval', array_values(array_intersect_key(
            $this->db->table('business_master')->where('business_slug', $slug)->get()->getRowArray(),
            array_flip(['booking_horizon_days', 'booking_notice_unit', 'booking_notice_value', 'booking_notice_time'])
        )));

        // left out: 3 days ahead, an hour's notice
        $this->send(['business' => $this->business()])->assertStatus(200);
        $this->assertSame(['3', 'M', '60', ''], $window('otter-cafe-sg'));

        // no same-day booking; the time is kept to the minute
        $this->send(['business' => $this->business(['business_name' => 'Badger Spa', 'business_slug' => 'badger-spa-sg',
            'booking_horizon_days' => 30, 'booking_notice_unit' => 'd', 'booking_notice_value' => 1, 'booking_notice_time' => '23:59:30'])])->assertStatus(200);
        $this->assertSame(['30', 'D', '1', '23:59:00'], $window('badger-spa-sg'));

        $refused = function (array $fields, array $expected): void {
            $response = $this->send(['business' => $this->business(['business_slug' => 'otter-x', 'business_name' => 'X'] + $fields)]);
            $response->assertStatus(422);
            $this->assertSame($expected, array_keys($this->body($response)['errors']));
        };
        $refused(['booking_horizon_days' => 0], ['booking_horizon_days']);
        $refused(['booking_horizon_days' => 366], ['booking_horizon_days']);
        $refused(['booking_notice_unit' => 'H'], ['booking_notice_unit']);
        $refused(['booking_notice_value' => -5], ['booking_notice_value']);
        $refused(['booking_notice_unit' => 'D', 'booking_notice_value' => 1], ['booking_notice_time']); // D needs a time
        $refused(['booking_notice_time' => '18:00'], ['booking_notice_time']); // M takes none
        $refused(['booking_notice_unit' => 'D', 'booking_notice_value' => 1, 'booking_notice_time' => '24:00'], ['booking_notice_time']);
        $refused(['booking_notice_value' => 3 * 1440 + 1], ['booking_notice_value']); // longer than the 3 days ahead
        $refused(['booking_notice_unit' => 'D', 'booking_notice_value' => 4, 'booking_notice_time' => '12:00'], ['booking_notice_value']);

        // edited, and out with the session business; an edit that changes nothing logs nothing
        $edit = fn (array $fields) => $this->send(['business_slug' => 'otter-cafe-sg', 'business' => $fields], null, 'v1/client/business/edit');
        $edit(['booking_notice_unit' => 'D', 'booking_notice_value' => 1, 'booking_notice_time' => '18:00'])->assertStatus(200);
        $edit(['booking_notice_unit' => 'M'])->assertStatus(422); // the time stays, so it must go too
        $logs = $this->db->table('log_activity')->countAllResults();
        $edit(['booking_horizon_days' => '3', 'booking_notice_time' => '18:00'])->assertStatus(200);
        $this->assertSame($logs, $this->db->table('log_activity')->countAllResults());
        $business = $this->body($this->send(['business_slug' => 'otter-cafe-sg'], null, 'v1/client/business/switch'))['data']['business']['business'];
        $this->assertSame(['3', 'D', '1', '18:00:00'], array_map('strval', [$business['booking_horizon_days'], $business['booking_notice_unit'],
            $business['booking_notice_value'], $business['booking_notice_time']]));
    }

    public function testCancellationPolicyDefaultsAndIsChecked(): void
    {
        $columns = ['cancel_notice_unit', 'cancel_notice_value', 'cancel_notice_time', 'late_cancel_allowed', 'late_cancel_fee_value',
            'late_cancel_fee_unit', 'no_show_fee_value', 'no_show_fee_unit'];
        $policy  = fn (string $slug): array => array_map('strval', array_values(BusinessMasterModel::withDecimals(array_intersect_key(
            $this->db->table('business_master')->where('business_slug', $slug)->get()->getRowArray(), array_flip($columns)))));

        // left out: free until 24 hours before, no fee
        $this->send(['business' => $this->business()])->assertStatus(200);
        $this->assertSame(['M', '1440', '', 'Y', '0.00', 'P', '0.00', 'P'], $policy('otter-cafe-sg'));

        // by 6pm the day before; half after, non-refundable for a no-show; codes in any case
        $this->send(['business' => $this->business(['business_name' => 'Badger Spa', 'business_slug' => 'badger-spa-sg',
            'cancel_notice_unit' => 'd', 'cancel_notice_value' => 1, 'cancel_notice_time' => '18:00', 'late_cancel_allowed' => 'n',
            'late_cancel_fee_value' => 50, 'no_show_fee_value' => '100', 'no_show_fee_unit' => 'p'])])->assertStatus(200);
        $this->assertSame(['D', '1', '18:00:00', 'N', '50.00', 'P', '100.00', 'P'], $policy('badger-spa-sg'));

        $refused = function (array $fields, array $expected): void {
            $response = $this->send(['business' => $this->business(['business_slug' => 'otter-x', 'business_name' => 'X'] + $fields)]);
            $response->assertStatus(422);
            $this->assertSame($expected, array_keys($this->body($response)['errors']));
        };
        $refused(['cancel_notice_unit' => 'H', 'late_cancel_allowed' => 'maybe', 'late_cancel_fee_unit' => 'X', 'no_show_fee_value' => '-1'],
            ['cancel_notice_unit', 'late_cancel_allowed', 'late_cancel_fee_unit', 'no_show_fee_value']);
        $refused(['cancel_notice_unit' => 'D', 'cancel_notice_time' => '12:00'], ['cancel_notice_value']); // 1440 days is past the most days
        $refused(['cancel_notice_unit' => 'D', 'cancel_notice_value' => 1], ['cancel_notice_time']);
        $refused(['cancel_notice_time' => '18:00'], ['cancel_notice_time']); // minutes take no time
        $refused(['late_cancel_fee_value' => '100.01', 'no_show_fee_value' => '1.234'], ['no_show_fee_value']); // the rules first
        $refused(['late_cancel_fee_value' => '100.01'], ['late_cancel_fee_value']);

        // a fixed fee is not capped at 100; out with the session business, its fees as "0.00"
        $this->send(['business_slug' => 'otter-cafe-sg', 'business' => ['no_show_fee_value' => 500, 'no_show_fee_unit' => 'F']], null, 'v1/client/business/edit')->assertStatus(200);
        $business = $this->body($this->send(['business_slug' => 'otter-cafe-sg'], null, 'v1/client/business/switch'))['data']['business']['business'];
        $this->assertSame(['1440', '0.00', '500.00', 'F'], array_map('strval', [$business['cancel_notice_value'], $business['late_cancel_fee_value'],
            $business['no_show_fee_value'], $business['no_show_fee_unit']]));
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

    // ---- list, default, switch ----

    public function testClientListsTheBusinessesTheyAreActiveIn(): void
    {
        $this->send(['business' => $this->business()])->assertStatus(200);
        $this->send(['business' => $this->business(['business_name' => 'Badger Bar', 'business_slug' => 'badger-bar-sg'])])->assertStatus(200);
        $theirs = $this->createClient('other@example.com');
        $this->send(['business' => $this->business(['business_name' => 'Not Mine', 'business_slug' => 'not-mine-sg'])], $theirs['token'])->assertStatus(200);
        $this->db->table('business_client_link')->insert(['business_id' => $this->businessId('not-mine-sg'), 'client_id' => $this->clientId,
            'client_role' => 'E', 'client_status' => 'T', 'start_date' => '2026-01-01', 'termination_date' => '2026-02-01']);

        $response = $this->send([], null, 'v1/client/businesses');
        $response->assertStatus(200);
        $this->assertSame([
            ['business_slug' => 'otter-cafe-sg', 'business_name' => 'OtterCafe', 'country_code' => 'SG', 'client_role' => 'O', 'is_default_business' => 'Y'],
            ['business_slug' => 'badger-bar-sg', 'business_name' => 'Badger Bar', 'country_code' => 'SG', 'client_role' => 'O', 'is_default_business' => 'N'],
        ], $this->body($response)['data']['businesses']);
    }

    public function testMakeDefaultLeavesOneDefaultBusiness(): void
    {
        $this->send(['business' => $this->business()])->assertStatus(200);
        $this->send(['business' => $this->business(['business_name' => 'Badger Bar', 'business_slug' => 'badger-bar-sg'])])->assertStatus(200);
        $other = $this->createClient('other@example.com');
        $this->send(['business' => $this->business(['business_name' => 'Not Mine', 'business_slug' => 'not-mine-sg'])], $other['token'])->assertStatus(200);

        $response = $this->send(['business_slug' => 'badger-bar-sg'], null, 'v1/client/business/make-default');
        $response->assertStatus(200);
        $this->assertSame(lang('Business.defaultChanged'), $this->body($response)['message']);

        $defaults = $this->db->table('business_client_link')->where('client_id', $this->clientId)->get()->getResultArray();
        $this->assertSame([$this->businessId('otter-cafe-sg') => 'N', $this->businessId('badger-bar-sg') => 'Y'],
            array_map('strval', array_column($defaults, 'is_default_business', 'business_id')));
        // another client's default is theirs
        $this->assertSame('Y', $this->db->table('business_client_link')->where('client_id', $other['client_id'])->get()->getRow()->is_default_business);
        $this->assertSame(2, $this->db->table('log_activity')->like('activity_summary', 'Changed default business', 'after')->countAllResults());

        $this->send(['business_slug' => 'not-mine-sg'], null, 'v1/client/business/make-default')->assertStatus(404);
        $this->send([], null, 'v1/client/business/make-default')->assertStatus(400);
    }

    public function testSwitchGivesTheBusinessAsSignInDoes(): void
    {
        $this->send(['business' => $this->business()])->assertStatus(200);
        $this->send(['business' => $this->business(['business_name' => 'Badger Bar', 'business_slug' => 'badger-bar-sg', 'tax_id' => '201912345A'])])->assertStatus(200);
        $this->db->table('business_client_link')->where('business_id', $this->businessId('badger-bar-sg'))->update(['client_role' => 'M']);

        $response = $this->send(['business_slug' => 'badger-bar-sg'], null, 'v1/client/business/switch');
        $response->assertStatus(200);
        $business = $this->body($response)['data']['business'];
        // tax_id beside the business, decrypted, to its owners and managers only
        $this->assertSame('201912345A', $this->body($response)['data']['tax_id']);
        $this->assertArrayNotHasKey('tax_id', $business['business']);
        $this->assertSame(['M', 'A', 'N'], [$business['client_role'], $business['client_status'], $business['is_default_business']]);
        $this->assertSame(['Badger Bar', 'badger-bar-sg', $this->typeId], [$business['business']['business_name'], $business['business']['business_slug'], $business['business']['business_type_public_id']]);
        $this->assertArrayNotHasKey('id', $business['business']);
        $this->assertSame([], $this->body($response)['data']['translations']);

        $this->db->table('business_client_link')->where('business_id', $this->businessId('badger-bar-sg'))->update(['client_role' => 'O']);
        $this->send(['business_slug' => 'badger-bar-sg', 'business' => [], 'translations' => ['th' => 'แบดเจอร์บาร์']], null, 'v1/client/business/edit')->assertStatus(200);
        $response = $this->send(['business_slug' => 'badger-bar-sg'], null, 'v1/client/business/switch');
        $this->assertSame(['th' => 'แบดเจอร์บาร์'], $this->body($response)['data']['translations']);

        $this->db->table('business_client_link')->where('business_id', $this->businessId('badger-bar-sg'))->update(['client_role' => 'E']);
        $response = $this->send(['business_slug' => 'badger-bar-sg'], null, 'v1/client/business/switch');
        $response->assertStatus(200);
        $this->assertArrayNotHasKey('tax_id', $this->body($response)['data']);

        $this->db->table('business_client_link')->where('business_id', $this->businessId('badger-bar-sg'))->update(['client_status' => 'T', 'termination_date' => date('Y-m-d')]);
        $this->send(['business_slug' => 'badger-bar-sg'], null, 'v1/client/business/switch')->assertStatus(404);
        $this->send(['business_slug' => 'nobody-sg'], null, 'v1/client/business/switch')->assertStatus(404);
    }

    private function businessId(string $slug): int
    {
        return (int) $this->db->table('business_master')->where('business_slug', $slug)->get()->getRow()->id;
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
