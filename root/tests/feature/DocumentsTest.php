<?php

use App\Libraries\DocumentRenderer;
use App\Libraries\FieldEncryption;
use App\Libraries\ImageProcessor;
use App\Models\BusinessTypeModel;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\URI;
use CodeIgniter\I18n\Time;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\Mock\MockCURLRequest;
use CodeIgniter\Test\TestResponse;
use Config\App;
use Config\Billing;
use Config\Services;

/**
 * /client/documents/* and /staff/documents/*: the invoice and the receipt of a subscription invoice as HTML pages,
 * each frozen when issued and sent the same every time
 *
 * @internal
 */
final class DocumentsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'App'; // the app's own migrations, the schema of the real database

    /** Bangkok 2026-11-10 12:00 */
    private const NOW = '2026-11-10 05:00:00';

    /** A 1x1 PNG */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private string $owner;
    private string $staff;
    private int $businessId;
    private array $departments = [];
    private string $uploads;
    private array $sources = [];
    private ?MockCURLRequest $qr = null;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['jwt', 'authContext'] as $service) {
            Services::resetSingle($service);
        }
        Time::setTestNow(self::NOW, 'UTC');
        Services::injectMock('imageProcessor', new class () extends ImageProcessor {
            public function isGenuineUpload(UploadedFile $file): bool
            {
                return $file->getError() === UPLOAD_ERR_OK && is_file($file->getTempName());
            }
        });
        $this->uploads                          = sys_get_temp_dir() . '/otternova-slips-' . bin2hex(random_bytes(4)) . '/';
        config(Billing::class)->slipUploadPath  = $this->uploads;
        config(Billing::class)->qrGeneratorLink = 'http://qr.test/generator';
        $this->qrAnswers("HTTP/1.1 200 OK\r\nContent-Type: image/png\r\n\r\n" . base64_decode(self::PNG, true));

        foreach (['EXE' => 'Executive Office', 'FIN' => 'Finance', 'ENG' => 'Engineering'] as $code => $name) {
            $this->db->table('otternova_department')->insert(['department_code' => $code, 'department_name' => $name, 'country_code' => 'TH']);
            $this->departments[$code] = (int) $this->db->insertID();
        }
        $this->staff = service('jwt')->issue($this->createStaff('fin@otternova.com', 'FIN'))['access_token'];

        $owner       = $this->createAccount('owner@example.com', 'C');
        $this->db->table('client_master')->insert(['auth_id' => $owner['id']]);
        $this->owner = service('jwt')->issue($owner)['access_token'];
        $types       = new BusinessTypeModel();
        $typeId      = $types->publicIdOf($types->createWithNames(['type_code' => 'cafe'], ['en-US' => 'Café', 'th' => 'คาเฟ่'], (int) $owner['id']));
        $this->send('v1/client/business/create', ['business' => [
            'business_type_public_id' => $typeId,
            'business_name'           => 'OtterCafe',
            'business_slug'           => 'otter-cafe',
            'contact_email_address'   => 'hello@ottercafe.th',
            'country_code'            => 'TH',
            'currency_code'           => 'THB',
        ]])->assertStatus(200);
        $this->businessId = (int) $this->db->table('business_master')->get()->getRow()->id;

        foreach (['free' => 0, 'boost' => 1] as $code => $tier) {
            $this->db->table('plan_master')->insert(['plan_code' => $code, 'plan_tier' => $tier]);
        }
        $boost = (int) $this->db->table('plan_master')->where('plan_code', 'boost')->get()->getRow()->id;
        $this->db->table('plan_version')->insert(['public_id' => 'version0', 'plan_id' => $boost, 'country_code' => 'TH', 'currency_code' => 'THB',
            'billing_cycle' => 'M', 'price' => '299.00', 'effective_from' => '2026-01-01 00:00:00']);
        $this->db->table('payment_method')->insert(['method_code' => 'promptpay', 'country_code' => 'TH', 'currency_code' => 'THB', 'confirmation_mode' => 'M', 'sort_order' => 1]);
    }

    protected function tearDown(): void
    {
        Time::setTestNow();
        Services::resetSingle('curlrequest');
        service('superglobals')->setFilesArray([]);
        foreach (array_merge($this->sources, glob($this->uploads . '*') ?: []) as $file) {
            @unlink($file);
        }
        @rmdir($this->uploads);

        parent::tearDown();
    }

    public function testAnInvoiceIsFrozenWhenMadeAndReadsTheSameAfterItIsPaid(): void
    {
        $invoice = $this->subscribe();
        $this->seeInDatabase('subscription_invoice', ['public_id' => $invoice, 'invoice_no' => 'INV-202611-000001',
            'invoice_version_id' => INVOICE_TH_VERSION, 'receipt_no' => null, 'receipt_version_id' => null]);
        $this->seeInDatabase('billing_document', ['invoice_id' => 1, 'document_type' => 'I', 'html' => null]);

        $response = $this->document('v1/client/documents/invoice/INV-202611-000001');
        $response->assertStatus(200);
        $this->assertSame('text/html; charset=UTF-8', $response->response()->getHeaderLine('Content-Type'));
        $this->assertSame('inline; filename="INV-202611-000001.html"', $response->response()->getHeaderLine('Content-Disposition'));
        $this->assertSame('private, no-store', $response->response()->getHeaderLine('Cache-Control'));
        $this->assertStringContainsString("default-src 'none'", $response->response()->getHeaderLine('Content-Security-Policy'));
        $html = $response->response()->getBody();
        $this->assertStringStartsWith('<!DOCTYPE html>', $html);
        foreach (['ใบแจ้งหนี้ &middot; Invoice', 'INV-202611-000001', 'OtterCafe', '10 พ.ย. 2569 / 10 Nov 2026', '11 พ.ย. 2569 / 11 Nov 2026',
            'แผน Boost - ประเภทรายเดือน / Boost plan - monthly subscription', 'สองร้อยเก้าสิบเก้าบาทถ้วน', 'Two hundred and ninety-nine baht only',
            'data:image/png;base64,' . self::PNG, 'template: th-invoice-01'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        // no status: it would change
        $this->assertStringNotContainsString('Awaiting payment', $html);
        // the QR is POSTed for what is payable, with the invoice as its reference
        $this->assertSame('http://qr.test/generator', $this->qr->curl_options[CURLOPT_URL]);
        parse_str($this->qr->curl_options[CURLOPT_POSTFIELDS], $form);
        $this->assertSame(['INV-202611-000001', '299.00', config(Billing::class)->promptPayBillerId], [$form['ref1'], $form['transactionAmount'], $form['billerId']]);
        $this->seeInDatabase('billing_document', ['invoice_id' => 1, 'document_type' => 'I', 'html_sha256' => hash('sha256', $html)]);

        // no receipt until it is paid
        $this->document('v1/client/documents/receipt/RCT-202611-000001')->assertStatus(404);

        // paid, its business moved, its template edited, the QR generator gone: the invoice reads the same
        $this->activate($invoice);
        $this->db->table('business_master')->where('id', $this->businessId)->update(['business_name' => 'Renamed Cafe']);
        $this->db->table('subscription_invoice')->update(['invoice_version_id' => 'th-invoice-99']);
        config(Billing::class)->qrGeneratorLink = '';
        $this->assertSame($html, $this->document('v1/client/documents/invoice/INV-202611-000001')->response()->getBody());
        $this->assertSame($html, $this->document('v1/staff/documents/invoice/INV-202611-000001', $this->staff)->response()->getBody());
    }

    public function testPayingFreezesTheReceipt(): void
    {
        $invoice = $this->subscribe();
        $this->activate($invoice);
        $this->seeInDatabase('subscription_invoice', ['public_id' => $invoice, 'receipt_no' => 'RCT-202611-000001', 'receipt_version_id' => RECEIPT_TH_VERSION]);
        $this->seeInDatabase('billing_document', ['invoice_id' => 1, 'document_type' => 'R']);
        // the business changes after it was paid: the receipt keeps the name it was paid under
        $this->db->table('business_master')->where('id', $this->businessId)->update(['business_name' => 'Renamed Cafe']);

        $html = $this->document('v1/client/documents/receipt/rct-202611-000001')->response()->getBody();
        foreach (['ใบเสร็จรับเงิน &middot; Receipt', 'RCT-202611-000001', 'INV-202611-000001', 'OtterCafe', 'พร้อมเพย์ / PromptPay', 'THB 299.00',
            'วันที่ชำระ / Paid on: 10 พ.ย. 2569 / 10 Nov 2026', 'รอบการใช้บริการ: 10 พ.ย. 2569 - 10 ธ.ค. 2569', 'template: th-receipt-01'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        $this->assertStringNotContainsString('Renamed Cafe', $html);
        $this->assertSame($html, $this->document('v1/client/documents/receipt/RCT-202611-000001')->response()->getBody());
    }

    public function testADiscountVoidsTheInvoiceAndMakesItAgain(): void
    {
        $invoice = $this->subscribe();
        $first   = $this->document('v1/client/documents/invoice/INV-202611-000001')->response()->getBody();
        $this->db->table('discount_master')->insert(['public_id' => 'discount', 'discount_code' => 'TENOFF', 'discount_type' => 'P', 'discount_value' => '10.00',
            'country_code' => 'TH', 'valid_from' => '2026-11-01 00:00:00', 'expires_at' => '2027-01-01 00:00:00']);

        $response = $this->send('v1/client/subscription/apply-discount', ['business_slug' => 'otter-cafe', 'invoice_public_id' => $invoice, 'discount_code' => 'TENOFF']);
        $response->assertStatus(200);
        $data = json_decode($response->getJSON(), true)['data'];
        $this->assertNotSame($invoice, $data['invoice_public_id']);
        $this->assertSame(['269.10', 'P'], [$data['amount_due'], $data['invoice_status']]);
        $this->seeInDatabase('subscription_invoice', ['public_id' => $invoice, 'invoice_status' => 'V', 'amount_due' => '299.00']);
        $this->seeInDatabase('subscription_invoice', ['public_id' => $data['invoice_public_id'], 'invoice_no' => 'INV-202611-000002',
            'due_at' => '2026-11-11 05:00:00', 'discount_amount' => '29.90']);

        // the voided invoice is still the one that was issued; the new one is less the discount
        $this->assertSame($first, $this->document('v1/client/documents/invoice/INV-202611-000001')->response()->getBody());
        $html = $this->document('v1/client/documents/invoice/INV-202611-000002')->response()->getBody();
        $this->assertStringContainsString('(TENOFF)', $html);
        $this->assertStringContainsString('269.10', $html);
    }

    public function testADocumentIsRenderedWithTheVersionStoredOnIt(): void
    {
        $this->subscribe();
        $this->assertTrue(DocumentRenderer::templateExists('th-invoice-01', 'invoice'));
        $this->assertFalse(DocumentRenderer::templateExists('th-invoice-01', 'receipt'));
        $this->assertFalse(DocumentRenderer::templateExists('../Config/App', 'invoice'));

        $this->db->table('subscription_invoice')->update(['invoice_version_id' => 'th-invoice-99']);
        $this->document('v1/client/documents/invoice/INV-202611-000001')->assertStatus(500);
    }

    public function testAnInvoiceIsNeverSentWithoutItsQr(): void
    {
        $this->subscribe();
        $this->qrAnswers("HTTP/1.1 502 Bad Gateway\r\nContent-Type: text/html\r\n\r\n<h1>down</h1>");
        $this->document('v1/client/documents/invoice/INV-202611-000001')->assertStatus(503);
        $this->seeInDatabase('billing_document', ['invoice_id' => 1, 'document_type' => 'I', 'html' => null]);

        $this->qrAnswers("HTTP/1.1 200 OK\r\nContent-Type: image/png\r\n\r\n" . base64_decode(self::PNG, true));
        $response = $this->document('v1/client/documents/invoice/INV-202611-000001');
        $response->assertStatus(200);
        $this->assertStringContainsString('data:image/png;base64,' . self::PNG, $response->response()->getBody());
    }

    public function testTheFrozenDocumentKeepsTheTaxIdEncrypted(): void
    {
        $this->db->table('business_master')->where('id', $this->businessId)->update(['tax_id' => (new FieldEncryption())->encryptRow('business_master', ['tax_id' => '0105566012345'])['tax_id']]);
        $this->subscribe();
        $html = $this->document('v1/client/documents/invoice/INV-202611-000001')->response()->getBody();
        $this->assertStringContainsString('0105566012345', $html);

        $row = $this->db->table('billing_document')->get()->getRowArray();
        foreach (['content_json', 'html'] as $column) {
            $this->assertStringStartsWith(FieldEncryption::PREFIX, $row[$column]);
            $this->assertStringNotContainsString('0105566012345', $row[$column]);
        }
    }

    public function testTheCustomerIsEscapedAndShownWithItsTaxDetails(): void
    {
        $this->db->table('business_master')->where('id', $this->businessId)->update([
            'legal_name'             => 'บริษัท ออทเทอร์ <script>alert(1)</script> จำกัด',
            'tax_branch_code'        => '00000',
            'contact_address_line_1' => '1 Sukhumvit Rd',
            'contact_city'           => 'Khlong Toei',
            'contact_subdivision_code' => 'TH-10',
            'contact_postal_code'    => '10110',
        ]);
        $this->subscribe();

        $html = $this->document('v1/client/documents/invoice/INV-202611-000001')->response()->getBody();
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('บริษัท ออทเทอร์ &lt;script&gt;alert(1)&lt;/script&gt; จำกัด', $html);
        $this->assertStringContainsString('Khlong Toei กรุงเทพมหานคร 10110', $html);
    }

    public function testOnlyTheOwnersAndFinanceSeeTheDocuments(): void
    {
        $this->subscribe();
        $path = 'documents/invoice/INV-202611-000001';

        $this->withHeaders([])->get('v1/client/' . $path)->assertStatus(401);
        $this->document('v1/client/' . $path, $this->staff)->assertStatus(403);
        $this->document('v1/client/' . $path, $this->createMember('manager@example.com', 'M'))->assertStatus(403);
        $stranger = $this->createAccount('stranger@example.com', 'C');
        $this->db->table('client_master')->insert(['auth_id' => $stranger['id']]);
        $this->document('v1/client/' . $path, service('jwt')->issue($stranger)['access_token'])->assertStatus(404);
        $this->document('v1/client/documents/invoice/INV-209901-999999')->assertStatus(404);

        $this->document('v1/staff/' . $path, $this->owner)->assertStatus(403);
        $this->document('v1/staff/' . $path, service('jwt')->issue($this->createStaff('eng@otternova.com', 'ENG'))['access_token'])->assertStatus(403);
        $this->document('v1/staff/' . $path, $this->staff)->assertStatus(200);
    }

    private function qrAnswers(string $output): void
    {
        $this->qr = (new MockCURLRequest(config(App::class), new URI(), new Response(config(App::class))))->setOutput($output);
        Services::injectMock('curlrequest', $this->qr);
    }

    private function document(string $path, ?string $token = null): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . ($token ?? $this->owner)])->get($path);
    }

    /**
     * Subscribe to boost monthly; the first invoice's public_id
     */
    private function subscribe(): string
    {
        $response = $this->send('v1/client/subscription/subscribe', ['business_slug' => 'otter-cafe', 'plan_code' => 'boost', 'billing_cycle' => 'M']);
        $response->assertStatus(200);
        // SQLite keeps the column default as the text CURRENT_TIMESTAMP; MySQL would have the time
        $this->db->table('subscription_invoice')->update(['created_at' => self::NOW]);

        return json_decode($response->getJSON(), true)['data']['invoice_public_id'];
    }

    /**
     * Pay the invoice by slip and have the staff approve it
     */
    private function activate(string $invoice): void
    {
        $source = tempnam(sys_get_temp_dir(), 'slip');
        file_put_contents($source, base64_decode(self::PNG, true));
        $this->sources[] = $source;
        service('superglobals')->setFilesArray(['slip' => ['name' => 'slip.png', 'type' => 'image/png', 'tmp_name' => $source, 'error' => UPLOAD_ERR_OK, 'size' => filesize($source)]]);
        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $this->owner])
            ->post('v1/client/subscription/pay', ['business_slug' => 'otter-cafe', 'invoice_public_id' => $invoice, 'method_code' => 'promptpay']);
        $response->assertStatus(200);
        $payment = json_decode($response->getJSON(), true)['data']['payment_public_id'];
        $this->send('v1/staff/subscriptions/payment/approve', ['public_id' => $payment], $this->staff)->assertStatus(200);
    }

    private function createMember(string $email, string $role): string
    {
        $account = $this->createAccount($email, 'C');
        $this->db->table('client_master')->insert(['auth_id' => $account['id']]);
        $this->db->table('business_client_link')->insert(['business_id' => $this->businessId, 'client_id' => (int) $this->db->insertID(), 'client_role' => $role,
            'client_status' => 'A', 'start_date' => '2026-01-01']);

        return service('jwt')->issue($account)['access_token'];
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

    private function send(string $path, array $body, ?string $token = null): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . ($token ?? $this->owner)])
            ->withBodyFormat('json')
            ->post($path, $body);
    }
}
