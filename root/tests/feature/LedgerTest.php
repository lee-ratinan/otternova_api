<?php

use App\Libraries\ImageProcessor;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\LedgerReceipt;
use Config\Services;

/**
 * /staff/ledger/*: the internal ledger, for the Executive Office (EXE) and Finance (FIN) only
 *
 * @internal
 */
final class LedgerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'App'; // the app's own migrations, the schema of the real database

    /** otternova_department.id by code */
    private array $departments = [];

    /** The signed-in EXE staff account calling the endpoints */
    private array $staff;
    private string $token;

    /** Stands in for writable/uploads/ledger-receipts/ */
    private string $uploads;

    /** Source files made for the test, removed in tearDown */
    private array $sources = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['jwt', 'authContext'] as $service) {
            Services::resetSingle($service);
        }
        // the test files are not real uploads, so is_uploaded_file() would refuse them
        Services::injectMock('imageProcessor', new class () extends ImageProcessor {
            public function isGenuineUpload(UploadedFile $file): bool
            {
                return $file->getError() === UPLOAD_ERR_OK && is_file($file->getTempName());
            }
        });
        $this->uploads                            = sys_get_temp_dir() . '/otternova-receipts-' . bin2hex(random_bytes(4)) . '/';
        config(LedgerReceipt::class)->uploadPath = $this->uploads;

        foreach (['EXE' => 'Executive Office', 'FIN' => 'Finance', 'ENG' => 'Engineering'] as $code => $name) {
            $this->db->table('otternova_department')->insert(['department_code' => $code, 'department_name' => $name, 'country_code' => 'TH']);
            $this->departments[$code] = (int) $this->db->insertID();
        }
        $this->staff = $this->createStaff('exec@otternova.com', 'EXE');
        $this->token = service('jwt')->issue($this->staff)['access_token'];
    }

    protected function tearDown(): void
    {
        service('superglobals')->setFilesArray([]);
        foreach (array_merge($this->sources, glob($this->uploads . '*') ?: []) as $file) {
            @unlink($file);
        }
        @rmdir($this->uploads);

        parent::tearDown();
    }

    // ---- access ----

    public function testOnlyExecutiveOfficeAndFinanceStaffGetIn(): void
    {
        $this->withHeaders([])->post('v1/staff/ledger/accounts')->assertStatus(401);

        $client = $this->createAccount('client@example.com', 'C');
        $this->db->table('client_master')->insert(['auth_id' => $client['id']]);
        $this->send('v1/staff/ledger/accounts', [], service('jwt')->issue($client)['access_token'])->assertStatus(403);

        $engineer = $this->createStaff('eng@otternova.com', 'ENG');
        $response = $this->send('v1/staff/ledger/accounts', [], service('jwt')->issue($engineer)['access_token']);
        $response->assertStatus(403);
        $this->assertSame(['status' => 'error', 'message' => lang('Auth.wrongDepartment')], $this->body($response));
        $this->withHeaders(['Authorization' => 'Bearer ' . service('jwt')->issue($engineer)['access_token']])
            ->get('v1/staff/ledger/receipt/1')->assertStatus(403);

        $finance = $this->createStaff('fin@otternova.com', 'FIN');
        $this->send('v1/staff/ledger/accounts', [], service('jwt')->issue($finance)['access_token'])->assertStatus(200);
        $this->send('v1/staff/ledger/accounts', [])->assertStatus(200);
    }

    // ---- accounts, categories, counterparties ----

    public function testAccountIsCreatedUpdatedAndLogged(): void
    {
        $id = $this->createRecord('account', ['account_name' => 'Company account', 'account_type' => 'I', 'currency_code' => 'thb', 'account_class' => '']);

        $this->assertSame(
            ['id' => $id, 'account_name' => 'Company account', 'account_type' => 'I', 'currency_code' => 'THB', 'account_class' => null],
            $this->rows('v1/staff/ledger/accounts')[0]
        );

        $response = $this->send('v1/staff/ledger/account/update', ['id' => $id, 'account_name' => 'Company account', 'account_type' => 'I', 'currency_code' => 'THB', 'account_class' => 'A']);
        $response->assertStatus(200);
        $this->assertSame('A', $this->rows('v1/staff/ledger/accounts')[0]['account_class']);

        $logs = $this->db->table('log_activity')->where('table_involved', 'otternova_ledger_account')->orderBy('id')->get()->getResultArray();
        $this->assertCount(2, $logs);
        $this->assertSame((string) $this->staff['id'], (string) $logs[1]['changed_by_acc']);
        $this->assertSame(['account_class' => ['old' => null, 'new' => 'A']], json_decode($logs[1]['activity_detail'], true)['changes']);
    }

    public function testAccountFieldsAreChecked(): void
    {
        $this->createRecord('account', ['account_name' => 'Company account', 'account_type' => 'I', 'currency_code' => 'THB']);

        $response = $this->send('v1/staff/ledger/account/update', ['account_name' => 'Company account', 'account_type' => 'Z', 'currency_code' => 'BAHT']);

        $response->assertStatus(422);
        $this->assertSame(['account_name', 'account_type', 'currency_code'], array_keys($this->body($response)['errors']));
    }

    public function testUpdatingAMissingRecordIs404(): void
    {
        $this->send('v1/staff/ledger/category/update', ['id' => 99, 'category_code' => 'INF', 'category_name' => 'Infrastructure'])->assertStatus(404);
        $this->send('v1/staff/ledger/category/update', ['id' => 'abc', 'category_code' => 'INF', 'category_name' => 'Infrastructure'])->assertStatus(422);
    }

    public function testCategoryCodeIsUniqueButKeptOnItsOwnUpdate(): void
    {
        $id = $this->createRecord('category', ['category_code' => 'inf', 'category_name' => 'Infrastructure']);

        $this->send('v1/staff/ledger/category/update', ['category_code' => 'INF', 'category_name' => 'Other'])->assertStatus(422);
        $this->send('v1/staff/ledger/category/update', ['id' => $id, 'category_code' => 'INF', 'category_name' => 'Cloud'])->assertStatus(200);

        $this->assertSame([['id' => $id, 'category_code' => 'INF', 'category_name' => 'Cloud']], $this->rows('v1/staff/ledger/categories'));
    }

    public function testCounterpartyIsCreated(): void
    {
        $id = $this->createRecord('counterparty', ['counterparty_name' => 'Amazon Web Services', 'counterparty_description' => '']);

        $this->assertSame(
            [['id' => $id, 'counterparty_name' => 'Amazon Web Services', 'counterparty_description' => null]],
            $this->rows('v1/staff/ledger/counterparties')
        );
    }

    // ---- entries ----

    public function testEntryIsCreatedAndShownWithItsNames(): void
    {
        $ids      = $this->basics();
        $response = $this->send('v1/staff/ledger/entry/update', $this->entry($ids, ['transaction_amount' => 12.5, 'billed_amount' => '12']));
        $response->assertStatus(200);
        $id = $this->body($response)['data']['id'];

        $entry = $this->body($this->send('v1/staff/ledger/entry', ['id' => $id]))['data']['entry'];

        $this->assertSame('2026-11-01', $entry['transaction_date']);
        $this->assertSame('INV-001', $entry['reference_number']);
        $this->assertSame('INF', $entry['category_code']);
        $this->assertSame('Company account', $entry['from_account_name']);
        $this->assertSame('X', $entry['to_account_type']);
        $this->assertSame('Amazon Web Services', $entry['counterparty_name']);
        $this->assertSame((int) $this->staff['id'], $entry['created_by']);
        $this->assertSame('Otter Tester', $entry['created_by_name']);
        $this->assertFalse($entry['has_receipt']);
        $this->assertArrayNotHasKey('receipt_file_path', $entry);
        $this->assertEqualsWithDelta(12.5, (float) $entry['transaction_amount'], 0.00001);
    }

    public function testEntryWithAnExternalAccountNeedsACounterparty(): void
    {
        $ids      = $this->basics();
        $response = $this->send('v1/staff/ledger/entry/update', $this->entry($ids, ['counterparty_id' => null]));

        $response->assertStatus(422);
        $this->assertSame(['counterparty_id' => lang('Ledger.counterpartyRequired')], $this->body($response)['errors']);

        // between two internal accounts there is no other party
        $personal = $this->createRecord('account', ['account_name' => 'Personal account', 'account_type' => 'I', 'currency_code' => 'THB']);
        $this->send('v1/staff/ledger/entry/update', $this->entry($ids, ['to_account_id' => $personal, 'counterparty_id' => '']))->assertStatus(200);
    }

    public function testEntryFieldsAreChecked(): void
    {
        $ids      = $this->basics();
        $response = $this->send('v1/staff/ledger/entry/update', $this->entry($ids, [
            'transaction_date'   => '2026-02-30',
            'transaction_amount' => '0',
            'billed_amount'      => '1.23456',
            'to_account_id'      => $ids['company'],
            'category_id'        => 999,
        ]));

        $response->assertStatus(422);
        $this->assertEqualsCanonicalizing(
            ['transaction_date', 'transaction_amount', 'billed_amount', 'to_account_id', 'category_id'],
            array_keys($this->body($response)['errors'])
        );
    }

    public function testEntryIsUpdatedKeepingItsCreator(): void
    {
        $ids = $this->basics();
        $id  = $this->body($this->send('v1/staff/ledger/entry/update', $this->entry($ids)))['data']['id'];

        $finance = $this->createStaff('fin@otternova.com', 'FIN');
        $this->send('v1/staff/ledger/entry/update', ['id' => $id] + $this->entry($ids, ['reference_number' => '']), service('jwt')->issue($finance)['access_token'])
            ->assertStatus(200);

        $row = $this->db->table('otternova_ledger_master')->where('id', $id)->get()->getRowArray();
        $this->assertNull($row['reference_number']);
        $this->assertSame((string) $this->staff['id'], (string) $row['created_by']);
    }

    public function testEntriesAreListedNewestFirstAndFiltered(): void
    {
        $ids      = $this->basics();
        $personal = $this->createRecord('account', ['account_name' => 'Personal account', 'account_type' => 'I', 'currency_code' => 'THB']);
        foreach (['2026-10-01', '2026-11-02', '2026-10-15'] as $date) {
            $this->send('v1/staff/ledger/entry/update', $this->entry($ids, ['transaction_date' => $date, 'transaction_detail' => 'Paid on ' . $date]))->assertStatus(200);
        }
        $this->send('v1/staff/ledger/entry/update', $this->entry($ids, ['transaction_date' => '2026-10-20', 'from_account_id' => $personal, 'to_account_id' => $ids['company'], 'counterparty_id' => null]))
            ->assertStatus(200);

        $all = $this->body($this->send('v1/staff/ledger/entries', []))['data'];
        $this->assertSame(4, $all['records_total']);
        $this->assertSame(['2026-11-02', '2026-10-20', '2026-10-15', '2026-10-01'], array_column($all['rows'], 'transaction_date'));

        $october = $this->body($this->send('v1/staff/ledger/entries', ['filters' => ['date_from' => '2026-10-01', 'date_to' => '2026-10-31', 'account_id' => $ids['aws']]]))['data'];
        $this->assertSame(2, $october['records_filtered']);
        $this->assertSame(['2026-10-15', '2026-10-01'], array_column($october['rows'], 'transaction_date'));

        $personalRows = $this->body($this->send('v1/staff/ledger/entries', ['filters' => ['account_id' => $personal, 'date_from' => 'nope']]))['data'];
        $this->assertSame(['2026-10-20'], array_column($personalRows['rows'], 'transaction_date'));

        $searched = $this->body($this->send('v1/staff/ledger/entries', ['search' => '11-02']))['data'];
        $this->assertSame(['2026-11-02'], array_column($searched['rows'], 'transaction_date'));
    }

    // ---- receipts ----

    public function testReceiptIsUploadedServedReplacedAndDeleted(): void
    {
        $ids = $this->basics();
        $id  = $this->body($this->send('v1/staff/ledger/entry/update', $this->entry($ids)))['data']['id'];

        $this->uploadReceipt(['id' => $id], $this->pdf('first'))->assertStatus(200);
        $first = $this->db->table('otternova_ledger_master')->where('id', $id)->get()->getRow()->receipt_file_path;
        $this->assertTrue($this->body($this->send('v1/staff/ledger/entry', ['id' => $id]))['data']['entry']['has_receipt']);

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $this->token])->get('v1/staff/ledger/receipt/' . $id);
        $response->assertStatus(200);
        $this->assertStringStartsWith('application/pdf', $response->response()->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('first', $response->getBody());

        $this->uploadReceipt(['id' => $id], $this->pdf('second'))->assertStatus(200);
        $this->assertFileDoesNotExist($this->uploads . $first);
        $this->assertStringContainsString('second', $this->withHeaders(['Authorization' => 'Bearer ' . $this->token])->get('v1/staff/ledger/receipt/' . $id)->getBody());

        $this->send('v1/staff/ledger/receipt-delete', ['id' => $id])->assertStatus(200);
        $this->assertSame([], glob($this->uploads . '*'));
        $this->withHeaders(['Authorization' => 'Bearer ' . $this->token])->get('v1/staff/ledger/receipt/' . $id)->assertStatus(404);
        $this->send('v1/staff/ledger/receipt-delete', ['id' => $id])->assertStatus(404);
    }

    public function testReceiptMustBeAPdfForAnExistingEntry(): void
    {
        $ids = $this->basics();
        $id  = $this->body($this->send('v1/staff/ledger/entry/update', $this->entry($ids)))['data']['id'];

        $text = tempnam(sys_get_temp_dir(), 'receipt');
        file_put_contents($text, 'just some text');
        $this->sources[] = $text;
        $this->uploadReceipt(['id' => $id], $text)->assertStatus(415);
        $this->uploadReceipt(['id' => $id], null)->assertStatus(422);
        $this->uploadReceipt(['id' => 999], $this->pdf('x'))->assertStatus(404);
        $this->uploadReceipt([], $this->pdf('x'))->assertStatus(400);

        $this->assertNull($this->db->table('otternova_ledger_master')->where('id', $id)->get()->getRow()->receipt_file_path);
    }

    // ---- helpers ----

    /**
     * Two accounts (one external), a category and a counterparty
     * @return array{company: int, aws: int, category: int, counterparty: int}
     */
    private function basics(): array
    {
        return [
            'company'      => $this->createRecord('account', ['account_name' => 'Company account', 'account_type' => 'I', 'currency_code' => 'THB']),
            'aws'          => $this->createRecord('account', ['account_name' => 'AWS', 'account_type' => 'X', 'currency_code' => 'USD']),
            'category'     => $this->createRecord('category', ['category_code' => 'INF', 'category_name' => 'Infrastructure']),
            'counterparty' => $this->createRecord('counterparty', ['counterparty_name' => 'Amazon Web Services']),
        ];
    }

    private function entry(array $ids, array $overrides = []): array
    {
        return $overrides + [
            'transaction_date'          => '2026-11-01',
            'transaction_detail'        => 'AWS bill for October',
            'reference_number'          => 'INV-001',
            'category_id'               => $ids['category'],
            'transaction_currency_code' => 'THB',
            'transaction_amount'        => '3600.50',
            'billed_currency_code'      => 'USD',
            'billed_amount'             => '100',
            'from_account_id'           => $ids['company'],
            'to_account_id'             => $ids['aws'],
            'counterparty_id'           => $ids['counterparty'],
        ];
    }

    private function createRecord(string $kind, array $fields): int
    {
        $response = $this->send('v1/staff/ledger/' . $kind . '/update', $fields);
        $response->assertStatus(200);

        return $this->body($response)['data']['id'];
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

    private function createStaff(string $email, string $department): array
    {
        $account = $this->createAccount($email, 'S');
        $this->db->table('staff_master')->insert([
            'auth_id'               => $account['id'],
            'department_id'         => $this->departments[$department],
            'employee_code'         => 'EMP-' . $account['id'],
            'job_title'             => 'Officer',
            'employment_status'     => 'A',
            'employment_start_date' => '2024-01-01',
            'work_country_code'     => 'TH',
            'legal_first_name'      => 'Otter',
            'legal_last_name'       => 'Tester',
        ]);

        return $account;
    }

    /**
     * A temporary, minimal PDF carrying $marker
     */
    private function pdf(string $marker): string
    {
        $path = tempnam(sys_get_temp_dir(), 'receipt');
        file_put_contents($path, "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n% " . $marker . "\ntrailer << /Root 1 0 R >>\n%%EOF\n");
        $this->sources[] = $path;

        return $path;
    }

    private function uploadReceipt(array $fields, ?string $source): TestResponse
    {
        service('superglobals')->setFilesArray($source === null ? [] : ['receipt' => [
            'name'     => 'receipt.pdf',
            'type'     => 'application/pdf',
            'tmp_name' => $source,
            'error'    => UPLOAD_ERR_OK,
            'size'     => filesize($source),
        ]]);

        return $this->withHeaders(['Authorization' => 'Bearer ' . $this->token])->post('v1/staff/ledger/receipt-upload', $fields);
    }

    private function send(string $path, array $body, ?string $token = null): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . ($token ?? $this->token)])
            ->withBodyFormat('json')
            ->post($path, $body);
    }

    private function body(TestResponse $response): array
    {
        return json_decode($response->getJSON(), true);
    }

    /**
     * data.rows of a 200 success response
     */
    private function rows(string $path): array
    {
        $response = $this->send($path, []);
        $response->assertStatus(200);

        return $this->body($response)['data']['rows'];
    }
}
