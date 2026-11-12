<?php

use App\Libraries\FieldEncryption;
use App\Models\BusinessClientLinkModel;
use App\Models\BusinessMasterModel;
use App\Models\BusinessTypeModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * BusinessTypeModel, BusinessMasterModel and BusinessClientLinkModel: names, encryption, fixed locale, owners
 *
 * @internal
 */
final class BusinessModelsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace = 'App'; // the app's own migrations, the schema of the real database

    private BusinessTypeModel $types;
    private BusinessMasterModel $businesses;
    private BusinessClientLinkModel $links;

    /** auth_master.id of the account making the changes */
    private int $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->types      = new BusinessTypeModel();
        $this->businesses = new BusinessMasterModel();
        $this->links      = new BusinessClientLinkModel();
        $this->actor      = $this->createAccount('admin@otternova.com', 'S');
    }

    // ---- business types ----

    public function testTypeNeedsANameInEverySupportedLanguage(): void
    {
        $this->assertSame(['th'], BusinessTypeModel::missingLanguages(['en-US' => 'Café', 'th' => ' ']));

        $this->expectException(InvalidArgumentException::class);
        $this->types->createWithNames(['type_code' => 'cafe'], ['en-US' => 'Café'], $this->actor);
    }

    public function testTypeLookupIsInTheAskedLanguageAndLeavesOutInactiveTypes(): void
    {
        $cafe = $this->createType('cafe', 'Café', 'คาเฟ่');
        $this->createType('salon', 'Salon', 'ร้านเสริมสวย');
        $this->types->updateWithNames($this->types->find($cafe), ['type_status' => 'I'], ['en-US' => 'Café', 'th' => 'คาเฟ่'], $this->actor);

        $this->assertSame(['ร้านเสริมสวย'], array_column($this->types->listForLookup('th'), 'type_name'));
        $this->assertSame(['Café', 'Salon'], array_column($this->types->listForLookup('ja', false), 'type_name')); // falls back to en-US
    }

    public function testTypeUpdateRenamesOnlyWhatChangedAndKeepsTheCode(): void
    {
        $id = $this->createType('cafe', 'Café', 'คาเฟ่');
        $this->types->updateWithNames($this->types->find($id), ['type_code' => 'coffee'], ['en-US' => 'Coffee Shop', 'th' => 'คาเฟ่'], $this->actor);

        $this->assertSame('cafe', $this->types->find($id)['type_code']);
        $this->assertSame(['en-US' => 'Coffee Shop', 'th' => 'คาเฟ่'], $this->types->namesOf($id));
        $this->seeNumRecords(1, 'log_activity', ['table_involved' => 'business_type_translation', 'activity_summary' => 'Renamed business type cafe in en-US']);
    }

    public function testTypeListHasEveryTypeWithItsNamesByPublicId(): void
    {
        $cafe = $this->createType('cafe', 'Café', 'คาเฟ่');
        $this->createType('bakery', 'Bakery', 'เบเกอรี่');
        $this->types->updateWithNames($this->types->find($cafe), ['type_status' => 'I'], ['en-US' => 'Café', 'th' => 'คาเฟ่'], $this->actor);

        $rows = $this->types->listWithNames();
        $this->assertSame(['bakery', 'cafe'], array_column($rows, 'type_code')); // inactive ones too
        $this->assertSame([
            'public_id'    => $this->types->publicIdOf($cafe),
            'type_code'    => 'cafe',
            'type_status'  => 'I',
            'translations' => ['en-US' => 'Café', 'th' => 'คาเฟ่'],
        ], $rows[1]);
    }

    // ---- businesses ----

    public function testCreateEncryptsTaxIdAndMakesTheCreatorOwner(): void
    {
        $client = $this->createClient('harry.potter@example.com');
        $id     = $this->createBusiness($client, ['tax_id' => '0105561234567'], ['th' => 'ออทเทอร์คาเฟ่']);

        $stored = $this->db->table('business_master')->where('id', $id)->get()->getRowArray();
        $this->assertStringStartsWith(FieldEncryption::PREFIX, $stored['tax_id']);
        $this->assertSame('0105561234567', $this->businesses->findDecrypted($id)['tax_id']);
        $this->assertSame(BusinessClientLinkModel::ROLE_OWNER, $this->links->activeRole($id, $client));
        $this->assertSame(['th' => 'ออทเทอร์คาเฟ่'], $this->businesses->namesOf($id));

        $logs = $this->db->table('log_activity')->select('activity_detail')->get()->getResultArray();
        $this->assertStringNotContainsString('0105561234567', json_encode($logs));
    }

    public function testAClientsFirstLinkIsAlwaysTheirDefault(): void
    {
        $owner  = $this->createClient('harry.potter@example.com');
        $first  = $this->createBusiness($owner);
        $second = $this->createBusiness($owner, ['business_name' => 'Otter Bakery', 'business_slug' => 'otter-bakery-sg']);
        $this->assertSame('Y', $this->links->findLink($first, $owner)['is_default_business']);
        $this->assertSame('N', $this->links->findLink($second, $owner)['is_default_business']);

        // hired into a business that is already someone else's default: it is theirs too
        $employee = $this->createClient('ron@example.com');
        $this->links->link($first, $employee, BusinessClientLinkModel::ROLE_EMPLOYEE, '2026-01-01', $this->actor);
        $this->assertSame('Y', $this->links->findLink($first, $employee)['is_default_business']);

        // a terminated link still counts as their first
        $this->links->terminate($this->links->findLink($first, $employee), '2026-06-30', $this->actor);
        $this->links->link($second, $employee, BusinessClientLinkModel::ROLE_EMPLOYEE, '2026-07-01', $this->actor);
        $this->assertSame('N', $this->links->findLink($second, $employee)['is_default_business']);
        $own = $this->createBusiness($employee, ['business_name' => 'Ron Cafe', 'business_slug' => 'ron-cafe-sg']);
        $this->assertSame('N', $this->links->findLink($own, $employee)['is_default_business']);
    }

    public function testSignInBusinessIsTheDefaultElseTheFirstActiveOne(): void
    {
        $client = $this->createClient('harry.potter@example.com');
        $this->assertNull($this->links->signInBusinessOf($client));

        $default = $this->createBusiness($client, ['tax_id' => '0105561234567']);
        $this->createBusiness($client, ['business_name' => 'Arctic Otters', 'business_slug' => 'arctic-otters-sg']);
        $this->createBusiness($client, ['business_name' => 'Bay Otters', 'business_slug' => 'bay-otters-sg']);

        $business = $this->links->signInBusinessOf($client);
        $this->assertSame(['Y', 'O', 'A'], [$business['is_default_business'], $business['client_role'], $business['client_status']]);
        $this->assertSame('ottercafe-sg', $business['business']['business_slug']);
        $this->assertSame($this->types->listForLookup('en-US')[0]['public_id'], $business['business']['business_type_public_id']);
        $this->assertArrayNotHasKey('tax_id', $business['business']);
        $this->assertArrayNotHasKey('id', $business['business']);

        // the default terminated: the first business still active
        $co = $this->createClient('ron@example.com');
        $this->links->link($default, $co, BusinessClientLinkModel::ROLE_OWNER, '2026-01-01', $this->actor);
        $this->links->terminate($this->links->findLink($default, $client), date('Y-m-d'), $this->actor);
        $business = $this->links->signInBusinessOf($client);
        $this->assertSame(['N', 'arctic-otters-sg'], [$business['is_default_business'], $business['business']['business_slug']]);
    }

    public function testCountryAndCurrencyAreFixed(): void
    {
        $id = $this->createBusiness($this->createClient('harry.potter@example.com'));

        $this->expectException(InvalidArgumentException::class);
        $this->businesses->updateBusiness($this->businesses->findDecrypted($id), ['currency_code' => 'USD'], $this->actor);
    }

    public function testUpdateReplacesTheNames(): void
    {
        $id = $this->createBusiness($this->createClient('harry.potter@example.com'), [], ['th' => 'ออทเทอร์คาเฟ่', 'ja' => 'オッターカフェ']);
        $this->businesses->updateBusiness($this->businesses->findDecrypted($id), ['contact_city' => 'Singapore'], $this->actor, ['th' => 'ออทเทอร์ คาเฟ่']);

        $this->assertSame(['th' => 'ออทเทอร์ คาเฟ่'], $this->businesses->namesOf($id));
        $this->assertSame('Singapore', $this->businesses->findDecrypted($id)['contact_city']);
    }

    public function testLocaleErrorsCheckTheCurrencyAndSubdivisionAgainstTheCountry(): void
    {
        $this->assertSame([], BusinessMasterModel::localeErrors(['country_code' => 'TH', 'currency_code' => 'THB', 'contact_subdivision_code' => 'TH-10']));
        $this->assertSame(
            ['currency_code', 'contact_subdivision_code'],
            array_keys(BusinessMasterModel::localeErrors(['country_code' => 'SG', 'currency_code' => 'THB', 'contact_subdivision_code' => 'TH-10']))
        );
    }

    public function testTheSameNameMayOpenInAnotherCountryOnly(): void
    {
        $client = $this->createClient('harry.potter@example.com');
        $this->createBusiness($client);

        $this->assertTrue($this->businesses->nameTaken('OtterCafe', 'sg'));
        $this->assertFalse($this->businesses->nameTaken('OtterCafe', 'MY'));
        $this->createBusiness($client, ['business_slug' => 'ottercafe-my', 'country_code' => 'MY', 'currency_code' => 'MYR']);
        $this->seeNumRecords(2, 'business_master', ['business_name' => 'OtterCafe']);
    }

    // ---- links ----

    public function testTheLastActiveOwnerCannotBeTerminatedOrDemoted(): void
    {
        $owner = $this->createClient('harry.potter@example.com');
        $id    = $this->createBusiness($owner);
        $link  = $this->links->findLink($id, $owner);

        $this->assertFalse($this->links->terminate($link, date('Y-m-d'), $this->actor));
        $this->assertFalse($this->links->changeRole($link, BusinessClientLinkModel::ROLE_MANAGER, $this->actor));

        $second = $this->createClient('hermione@example.com');
        $this->assertTrue($this->links->link($id, $second, BusinessClientLinkModel::ROLE_OWNER, date('Y-m-d'), $this->actor));
        $this->assertTrue($this->links->changeRole($link, BusinessClientLinkModel::ROLE_MANAGER, $this->actor));
        $this->assertSame(BusinessClientLinkModel::ROLE_MANAGER, $this->links->activeRole($id, $owner));
    }

    public function testRehireReactivatesTheSameLink(): void
    {
        $id       = $this->createBusiness($this->createClient('harry.potter@example.com'));
        $employee = $this->createClient('ron@example.com');
        $this->assertTrue($this->links->link($id, $employee, BusinessClientLinkModel::ROLE_EMPLOYEE, '2026-01-01', $this->actor));
        $this->assertFalse($this->links->link($id, $employee, BusinessClientLinkModel::ROLE_EMPLOYEE, '2026-01-01', $this->actor));

        $this->assertTrue($this->links->terminate($this->links->findLink($id, $employee), '2026-06-30', $this->actor));
        $this->assertNull($this->links->activeRole($id, $employee));

        $this->assertTrue($this->links->link($id, $employee, BusinessClientLinkModel::ROLE_MANAGER, '2026-11-01', $this->actor));
        $link = $this->links->findLink($id, $employee);
        $this->assertSame(['A', 'M', '2026-11-01', null], [$link['client_status'], $link['client_role'], $link['start_date'], $link['termination_date']]);
        $this->seeNumRecords(1, 'business_client_link', ['client_id' => $employee]);
    }

    public function testTerminationCannotPrecedeTheStart(): void
    {
        $id       = $this->createBusiness($this->createClient('harry.potter@example.com'));
        $employee = $this->createClient('ron@example.com');
        $this->links->link($id, $employee, BusinessClientLinkModel::ROLE_EMPLOYEE, '2026-01-01', $this->actor);

        $this->expectException(InvalidArgumentException::class);
        $this->links->terminate($this->links->findLink($id, $employee), '2025-12-31', $this->actor);
    }

    // ---- helpers ----

    private function createType(string $code, string $english, string $thai): int
    {
        return $this->types->createWithNames(['type_code' => $code], ['en-US' => $english, 'th' => $thai], $this->actor);
    }

    /**
     * @return int client_master.id
     */
    private function createClient(string $email): int
    {
        $this->db->table('client_master')->insert(['auth_id' => $this->createAccount($email, 'C')]);

        return (int) $this->db->insertID();
    }

    /**
     * @return int auth_master.id
     */
    private function createAccount(string $email, string $type): int
    {
        $this->db->table('auth_master')->insert(['public_id' => bin2hex(random_bytes(6)), 
            'uid'            => preg_replace('/[^a-z0-9]/', '', $email),
            'email_address'  => $email,
            'password_hash'  => 'x',
            'full_name'      => 'Otter Tester',
            'account_status' => 'A',
            'account_type'   => $type,
        ]);

        return (int) $this->db->insertID();
    }

    private function createBusiness(int $ownerClientId, array $overrides = [], array $names = []): int
    {
        $type = $this->types->listForLookup('en-US')[0]['id'] ?? $this->createType('cafe', 'Café', 'คาเฟ่');

        return $this->businesses->createWithOwner($overrides + [
            'business_type_id'      => $type,
            'business_name'         => 'OtterCafe',
            'business_slug'         => 'ottercafe-sg',
            'contact_email_address' => 'support@example.com',
            'country_code'          => 'SG',
            'currency_code'         => 'SGD',
        ], $ownerClientId, $this->actor, $names);
    }
}
