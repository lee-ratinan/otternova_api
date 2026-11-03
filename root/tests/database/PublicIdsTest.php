<?php

use App\Database\Migrations\AddPublicIds;
use App\Models\BusinessTypeModel;
use App\Models\CustomerMasterModel;
use App\Models\OtternovaLedgerAccountModel;
use App\Models\OtternovaLedgerCategoryModel;
use App\Models\OtternovaLedgerCounterpartyModel;
use App\Models\OtternovaLedgerMasterModel;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * public_id (PublicIdTrait and the AddPublicIds migration): generated on every create, unique within its table
 *
 * @internal
 */
final class PublicIdsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace = 'App'; // the app's own migrations, the schema of the real database

    private const NANO_ID = '/^[0-9A-Za-z_-]+$/';

    /** auth_master.id of the staff member making the changes */
    private int $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db->table('auth_master')->insert([
            'uid'            => 'admin',
            'email_address'  => 'admin@otternova.com',
            'password_hash'  => 'x',
            'full_name'      => 'Otter Tester',
            'account_status' => 'A',
            'account_type'   => 'S',
        ]);
        $this->actor = (int) $this->db->insertID();
    }

    public function testEveryCreateGetsAPublicIdOfItsLength(): void
    {
        $type     = (new BusinessTypeModel())->createWithNames(['type_code' => 'cafe'], ['en-US' => 'Café', 'th' => 'คาเฟ่'], $this->actor);
        $customer = (new CustomerMasterModel())->insert(['email_address' => 'otter@example.com', 'customer_name' => 'Otter']);
        $from     = (new OtternovaLedgerAccountModel())->createLogged(['account_name' => 'Bank', 'account_type' => 'I', 'currency_code' => 'THB'], $this->actor, 'Created');
        $to       = (new OtternovaLedgerAccountModel())->createLogged(['account_name' => 'Vendor', 'account_type' => 'X', 'currency_code' => 'THB'], $this->actor, 'Created');
        $category = (new OtternovaLedgerCategoryModel())->createLogged(['category_code' => 'RENT', 'category_name' => 'Rent'], $this->actor, 'Created');
        $party    = (new OtternovaLedgerCounterpartyModel())->createLogged(['counterparty_name' => 'Landlord'], $this->actor, 'Created');
        $entry    = (new OtternovaLedgerMasterModel())->createLogged([
            'transaction_date'          => '2026-11-01',
            'transaction_detail'        => 'November rent',
            'from_account_id'           => $from,
            'to_account_id'             => $to,
            'category_id'               => $category,
            'counterparty_id'           => $party,
            'transaction_amount'        => '100.0000',
            'transaction_currency_code' => 'THB',
            'billed_amount'             => '100.0000',
            'billed_currency_code'      => 'THB',
            'created_by'                => $this->actor,
        ], $this->actor, 'Created');

        foreach ([
            'business_type'                 => $type,
            'customer_master'               => $customer,
            'otternova_ledger_account'      => $from,
            'otternova_ledger_category'     => $category,
            'otternova_ledger_counterparty' => $party,
            'otternova_ledger_master'       => $entry,
        ] as $table => $id) {
            $publicId = $this->db->table($table)->where('id', $id)->get()->getRow()->public_id;
            $this->assertSame(AddPublicIds::LENGTHS[$table], strlen($publicId), $table);
            $this->assertMatchesRegularExpression(self::NANO_ID, $publicId, $table);
        }

        // the log has it too, as the row inserted
        $log = $this->db->table('log_activity')->where('table_involved', 'business_type')->get()->getRow();
        $this->assertStringContainsString('public_id', $log->activity_detail);
    }

    public function testAClashingPublicIdIsRegenerated(): void
    {
        $categories = new OtternovaLedgerCategoryModel();
        $categories->createLogged(['category_code' => 'RENT', 'category_name' => 'Rent'], $this->actor, 'Created');
        $taken = $this->db->table('otternova_ledger_category')->get()->getRow()->public_id;

        // another request took the ID between the check and the insert
        $model = new class ($taken) extends OtternovaLedgerCategoryModel {
            public int $calls = 0;

            public function __construct(private string $taken)
            {
                parent::__construct();
            }

            public function newPublicId(): string
            {
                return ++$this->calls === 1 ? $this->taken : parent::newPublicId();
            }
        };
        $id = $model->createLogged(['category_code' => 'FOOD', 'category_name' => 'Food'], $this->actor, 'Created');

        $this->assertSame(2, $model->calls);
        $this->assertNotSame($taken, $this->db->table('otternova_ledger_category')->where('id', $id)->get()->getRow()->public_id);
        $this->assertSame(2, $this->db->table('otternova_ledger_category')->countAllResults());
        // the failed try wrote nothing, not even its log row
        $this->assertSame(2, $this->db->table('log_activity')->where('table_involved', 'otternova_ledger_category')->countAllResults());
    }

    public function testOtherClashesAreNotRetried(): void
    {
        $model = new class () extends BusinessTypeModel {
            public int $calls = 0;

            public function newPublicId(): string
            {
                $this->calls++;

                return parent::newPublicId();
            }
        };
        $model->createWithNames(['type_code' => 'cafe'], ['en-US' => 'Café', 'th' => 'คาเฟ่'], $this->actor);

        try {
            $model->createWithNames(['type_code' => 'cafe'], ['en-US' => 'Coffee', 'th' => 'กาแฟ'], $this->actor);
            $this->fail('A taken type_code must be refused');
        } catch (DatabaseException) {
            $this->assertSame(2, $model->calls);
        }
    }

    public function testPublicIdIsCaseSensitiveAndUnique(): void
    {
        // Model::insert() leaves public_id to the model: it is not an allowed field
        $id = (new CustomerMasterModel())->insert(['public_id' => 'abcdefghijkl', 'email_address' => 'a@example.com', 'customer_name' => 'A']);
        $this->assertNotSame('abcdefghijkl', $this->db->table('customer_master')->where('id', $id)->get()->getRow()->public_id);

        $this->db->table('customer_master')->insert(['public_id' => 'abcdefghijkl', 'email_address' => 'b@example.com', 'customer_name' => 'B']);
        $this->db->table('customer_master')->insert(['public_id' => 'ABCDEFGHIJKL', 'email_address' => 'c@example.com', 'customer_name' => 'C']);
        $this->assertSame(3, $this->db->table('customer_master')->countAllResults());

        $this->expectException(DatabaseException::class);
        $this->db->table('customer_master')->insert(['public_id' => 'abcdefghijkl', 'email_address' => 'd@example.com', 'customer_name' => 'D']);
    }

    public function testMigrationGivesTheRowsAlreadyThereOne(): void
    {
        $migration = new AddPublicIds(Database::forge($this->DBGroup));
        $migration->down();
        foreach (['otter', 'beaver', 'seal'] as $name) {
            $this->db->table('customer_master')->insert(['email_address' => $name . '@example.com', 'customer_name' => $name]);
        }

        $migration->up();
        $publicIds = array_column($this->db->table('customer_master')->get()->getResultArray(), 'public_id');
        $this->assertCount(3, array_unique($publicIds));
        foreach ($publicIds as $publicId) {
            $this->assertSame(12, strlen($publicId));
        }

        $this->expectException(DatabaseException::class);
        $this->db->table('customer_master')->insert(['public_id' => $publicIds[0], 'email_address' => 'x@example.com', 'customer_name' => 'X']);
    }
}
