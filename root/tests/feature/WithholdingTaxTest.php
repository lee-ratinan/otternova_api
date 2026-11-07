<?php

use App\Libraries\ImageProcessor;
use App\Libraries\Money;
use App\Libraries\SubscriptionBilling;
use App\Models\BusinessTypeModel;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\I18n\Time;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\Billing;
use Config\Services;

/**
 * Withholding tax: a business asks to withhold, the staff approve, its invoices are less the tax, it sends a
 * certificate (50 ทวิ) for each one it pays, and one that does not come blocks a monthly renewal or is billed as a
 * top-up of a yearly one
 *
 * @internal
 */
final class WithholdingTaxTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'App'; // the app's own migrations, the schema of the real database

    /** Bangkok 2026-11-10 12:00 */
    private const NOW = '2026-11-10 05:00:00';

    private string $owner;
    private string $staff;
    private int $businessId;
    private array $departments = [];
    private string $uploads;
    private array $sources = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['jwt', 'authContext'] as $service) {
            Services::resetSingle($service);
        }
        Time::setTestNow(self::NOW, 'UTC');
        // the test files are not real uploads, so is_uploaded_file() would refuse them
        Services::injectMock('imageProcessor', new class () extends ImageProcessor {
            public function isGenuineUpload(UploadedFile $file): bool
            {
                return $file->getError() === UPLOAD_ERR_OK && is_file($file->getTempName());
            }
        });
        $this->uploads   = sys_get_temp_dir() . '/otternova-wht-' . bin2hex(random_bytes(4)) . '/';
        $config          = config(Billing::class);
        $config->slipUploadPath    = $this->uploads;
        $config->whtCertUploadPath = $this->uploads;

        foreach (['EXE' => 'Executive Office', 'FIN' => 'Finance'] as $code => $name) {
            $this->db->table('otternova_department')->insert(['department_code' => $code, 'department_name' => $name, 'country_code' => 'TH']);
            $this->departments[$code] = (int) $this->db->insertID();
        }
        $staff       = $this->createStaff('fin@otternova.com', 'FIN');
        $this->staff = service('jwt')->issue($staff)['access_token'];

        $owner       = $this->createAccount('owner@example.com', 'C');
        $this->db->table('client_master')->insert(['auth_id' => $owner['id']]);
        $this->owner = service('jwt')->issue($owner)['access_token'];
        $types       = new BusinessTypeModel();
        $typeId      = $types->publicIdOf($types->createWithNames(['type_code' => 'cafe'], ['en-US' => 'Café', 'th' => 'คาเฟ่'], (int) $staff['id']));
        $this->send('v1/client/business/create', ['business' => [
            'business_type_public_id' => $typeId,
            'business_name'           => 'OtterCafe',
            'business_slug'           => 'otter-cafe',
            'contact_email_address'   => 'hello@ottercafe.th',
            'country_code'            => 'TH',
            'currency_code'           => 'THB',
            'tax_id'                  => '0105566000001',
        ]])->assertStatus(200);
        $this->businessId = (int) $this->db->table('business_master')->get()->getRow()->id;

        foreach (['free' => 0, 'boost' => 1] as $code => $tier) {
            $this->db->table('plan_master')->insert(['plan_code' => $code, 'plan_tier' => $tier]);
        }
        $boost = (int) $this->db->table('plan_master')->where('plan_code', 'boost')->get()->getRow()->id;
        foreach ([['M', '299.00'], ['Y', '2990.00']] as $i => [$cycle, $price]) {
            $this->db->table('plan_version')->insert(['public_id' => 'version' . $i, 'plan_id' => $boost, 'country_code' => 'TH', 'currency_code' => 'THB',
                'billing_cycle' => $cycle, 'price' => $price, 'effective_from' => '2026-01-01 00:00:00']);
        }
        $this->db->table('payment_method')->insert(['method_code' => 'promptpay', 'country_code' => 'TH', 'currency_code' => 'THB', 'confirmation_mode' => 'M', 'sort_order' => 1]);
        $this->db->table('withholding_tax_rule')->insert(['public_id' => 'thsrv001', 'country_code' => 'TH', 'rule_code' => 'TH_SERVICE', 'rate' => '3.00',
            'min_base_amount' => '1000.00', 'currency_code' => 'THB', 'certificate_required' => 'Y', 'certificate_name' => '50 ทวิ', 'certificate_due_days' => 30,
            'effective_from' => '2025-12-31 17:00:00']);
    }

    protected function tearDown(): void
    {
        Time::setTestNow();
        service('superglobals')->setFilesArray([]);
        foreach (array_merge($this->sources, glob($this->uploads . '*') ?: []) as $file) {
            @unlink($file);
        }
        @rmdir($this->uploads);

        parent::tearDown();
    }

    public function testABusinessAsksAndTheStaffApprove(): void
    {
        $withholding = $this->body($this->send('v1/client/subscription/current', ['business_slug' => 'otter-cafe']))['data']['withholding'];
        $this->assertSame(['N', true], [$withholding['wht_status'], $withholding['has_tax_id']]);
        $this->assertSame(['rule_code' => 'TH_SERVICE', 'rate' => '3.00', 'min_base_amount' => '1000.00', 'currency_code' => 'THB',
            'certificate_required' => 'Y', 'certificate_name' => '50 ทวิ', 'certificate_due_days' => 30], $withholding['rules'][0]);

        $response = $this->send('v1/client/subscription/withholding', ['business_slug' => 'otter-cafe', 'legal_name' => '', 'tax_branch_code' => 'HQ']);
        $response->assertStatus(422);
        $this->assertSame(['legal_name', 'tax_branch_code'], array_keys($this->body($response)['errors']));

        $this->requestWithholding();
        $this->seeInDatabase('business_master', ['id' => $this->businessId, 'wht_status' => 'R', 'wht_rule_code' => 'TH_SERVICE',
            'legal_name' => 'Otter Cafe Co., Ltd.', 'tax_branch_code' => '00000']);
        $this->send('v1/client/subscription/withholding', ['business_slug' => 'otter-cafe', 'legal_name' => 'Otter', 'tax_branch_code' => '00000'])->assertStatus(409);

        // only Executive Office and Finance review it, and they see the tax ID to check
        $this->send('v1/staff/subscriptions/withholding', [])->assertStatus(403);
        $rows = $this->body($this->send('v1/staff/subscriptions/withholding', [], $this->staff))['data']['rows'];
        $this->assertSame(['otter-cafe', '0105566000001', 'R'], [$rows[0]['business_slug'], $rows[0]['tax_id'], $rows[0]['wht_status']]);

        $this->send('v1/staff/subscriptions/withholding/review', ['business_slug' => 'otter-cafe', 'wht_status' => 'X'], $this->staff)->assertStatus(422);
        $this->send('v1/staff/subscriptions/withholding/review', ['business_slug' => 'otter-cafe', 'wht_status' => 'A'], $this->staff)->assertStatus(200);
        $this->seeInDatabase('business_master', ['id' => $this->businessId, 'wht_status' => 'A']);
        $this->send('v1/staff/subscriptions/withholding/review', ['business_slug' => 'otter-cafe', 'wht_status' => 'A'], $this->staff)->assertStatus(409);
    }

    public function testABusinessWithNoTaxIdCannotAsk(): void
    {
        $this->db->table('business_master')->where('id', $this->businessId)->update(['tax_id' => null]);
        $this->send('v1/client/subscription/withholding', ['business_slug' => 'otter-cafe', 'legal_name' => 'Otter', 'tax_branch_code' => '00000'])->assertStatus(422);
    }

    public function testAYearlyInvoiceIsLessTheTaxAndWaitsForItsCertificateOncePaid(): void
    {
        $this->approveWithholding();
        $response = $this->send('v1/client/subscription/subscribe', ['business_slug' => 'otter-cafe', 'plan_code' => 'boost', 'billing_cycle' => 'Y']);
        $data     = $this->body($response)['data'];
        $this->assertSame(['2990.00', '89.70', '2900.30'], [$data['amount_due'], $data['wht_amount'], $data['amount_payable']]);

        $this->activate($data['invoice_public_id']);
        $this->seeInDatabase('payment_transaction', ['amount' => '2900.30']);
        $this->seeInDatabase('subscription_invoice', ['public_id' => $data['invoice_public_id'], 'invoice_status' => 'D', 'wht_rate' => '3.00',
            'wht_cert_status' => 'W', 'wht_cert_due_at' => '2026-12-10 05:00:00']);
        $this->seeInDatabase('subscription_master', ['subscription_status' => 'A']);

        $invoice = $this->body($this->send('v1/client/subscription/current', ['business_slug' => 'otter-cafe']))['data']['invoices'][0];
        $this->assertSame(['S', '89.70', '2900.30', 'W', []], [$invoice['invoice_type'], $invoice['wht_amount'], $invoice['amount_payable'], $invoice['wht_cert_status'], $invoice['certificates']]);
    }

    public function testNothingIsWithheldUnderTheMinimum(): void
    {
        $this->approveWithholding();
        $invoice = $this->subscribe('M');
        $this->seeInDatabase('subscription_invoice', ['public_id' => $invoice, 'wht_amount' => '0.00', 'amount_payable' => '299.00', 'wht_rule_id' => null]);

        $this->activate($invoice);
        $this->seeInDatabase('subscription_invoice', ['public_id' => $invoice, 'invoice_status' => 'D', 'wht_cert_status' => 'N']);
    }

    public function testApprovingAndWithdrawingWorkPendingInvoicesOutAgain(): void
    {
        $invoice = $this->subscribe('Y');
        $this->seeInDatabase('subscription_invoice', ['public_id' => $invoice, 'amount_payable' => '2990.00']);

        $this->approveWithholding();
        $this->seeInDatabase('subscription_invoice', ['public_id' => $invoice, 'wht_amount' => '89.70', 'amount_payable' => '2900.30']);

        $this->send('v1/client/subscription/withholding/withdraw', ['business_slug' => 'otter-cafe'])->assertStatus(200);
        $this->seeInDatabase('subscription_invoice', ['public_id' => $invoice, 'wht_amount' => '0.00', 'amount_payable' => '2990.00', 'wht_rule_id' => null]);
        $this->seeInDatabase('business_master', ['id' => $this->businessId, 'wht_status' => 'N', 'wht_rule_code' => null]);
        $this->send('v1/client/subscription/withholding/withdraw', ['business_slug' => 'otter-cafe'])->assertStatus(409);
    }

    public function testADiscountWorksTheTaxOutAgain(): void
    {
        $this->approveWithholding();
        $this->db->table('discount_master')->insert(['public_id' => 'half0001', 'discount_code' => 'HALF', 'discount_type' => 'P', 'discount_value' => '50.00',
            'country_code' => 'TH', 'valid_from' => '2026-11-01 00:00:00', 'expires_at' => '2027-01-01 00:00:00']);
        $invoice  = $this->subscribe('Y');
        $response = $this->send('v1/client/subscription/apply-discount', ['business_slug' => 'otter-cafe', 'invoice_public_id' => $invoice, 'discount_code' => 'HALF']);
        $data     = $this->body($response)['data'];
        $this->assertSame(['1495.00', '44.85', '1450.15'], [$data['amount_due'], $data['wht_amount'], $data['amount_payable']]);
    }

    public function testACertificateIsRejectedThenAccepted(): void
    {
        $invoice = $this->paidYearlyInvoice();

        $this->sendCertificate($invoice, ['issued_on' => '2026-02-30'])->assertStatus(422);
        $this->sendCertificate($invoice)->assertStatus(200);
        $this->seeInDatabase('subscription_invoice', ['public_id' => $invoice, 'wht_cert_status' => 'U']);
        $this->sendCertificate($invoice)->assertStatus(409);

        $rows = $this->body($this->send('v1/staff/subscriptions/certificates', ['filters' => ['cert_status' => 'P']], $this->staff))['data']['rows'];
        $this->assertCount(1, $rows);
        $this->assertSame([$invoice, '89.70', '89.70', true], [$rows[0]['invoice_public_id'], $rows[0]['wht_amount'], $rows[0]['invoice_wht_amount'], $rows[0]['has_file']]);
        $this->withHeaders([])->get('v1/staff/subscriptions/certificate-file/' . $rows[0]['public_id'])->assertStatus(401);
        $file = $this->withHeaders(['Authorization' => 'Bearer ' . $this->staff])->get('v1/staff/subscriptions/certificate-file/' . $rows[0]['public_id']);
        $file->assertStatus(200);
        $this->assertStringStartsWith('image/png', $file->response()->getHeaderLine('Content-Type'));

        $this->send('v1/staff/subscriptions/certificate/reject', ['public_id' => $rows[0]['public_id'], 'rejection_reason' => ''], $this->staff)->assertStatus(422);
        $this->send('v1/staff/subscriptions/certificate/reject', ['public_id' => $rows[0]['public_id'], 'rejection_reason' => 'Wrong amount'], $this->staff)->assertStatus(200);
        $this->seeInDatabase('subscription_invoice', ['public_id' => $invoice, 'wht_cert_status' => 'W']);

        $this->sendCertificate($invoice)->assertStatus(200);
        $second = $this->db->table('withholding_tax_certificate')->where('cert_status', 'P')->get()->getRow()->public_id;
        $this->send('v1/staff/subscriptions/certificate/accept', ['public_id' => $second], $this->staff)->assertStatus(200);
        $this->seeInDatabase('subscription_invoice', ['public_id' => $invoice, 'wht_cert_status' => 'A']);
        $this->send('v1/staff/subscriptions/certificate/accept', ['public_id' => $second], $this->staff)->assertStatus(409);
        $this->sendCertificate($invoice)->assertStatus(409);

        $certificates = $this->body($this->send('v1/client/subscription/current', ['business_slug' => 'otter-cafe']))['data']['invoices'][0]['certificates'];
        $this->assertSame(['R', 'A'], array_column($certificates, 'cert_status'));
        $this->assertSame('Wrong amount', $certificates[0]['rejection_reason']);
    }

    public function testALateYearlyCertificateIsBilledAsATopUpWhichALateCertificateVoids(): void
    {
        $invoice = $this->paidYearlyInvoice();

        Time::setTestNow('2026-12-10 04:59:59', 'UTC');
        $this->assertSame(0, $this->runSchedule()['topped_up']);
        Time::setTestNow('2026-12-10 05:00:00', 'UTC');
        $this->assertSame(1, $this->runSchedule()['topped_up']);
        $this->assertSame(0, $this->runSchedule()['topped_up']);

        $parent = $this->db->table('subscription_invoice')->where('public_id', $invoice)->get()->getRow();
        $this->assertSame('T', $parent->wht_cert_status);
        $topUp = $this->db->table('subscription_invoice')->where('invoice_type', 'W')->get()->getRow();
        $this->assertSame([(int) $parent->id, '89.70', '89.70', 'P', '2026-12-24 05:00:00'],
            [(int) $topUp->parent_invoice_id, Money::format($topUp->amount_due), Money::format($topUp->amount_payable), $topUp->invoice_status, $topUp->due_at]);
        $this->send('v1/client/subscription/apply-discount', ['business_slug' => 'otter-cafe', 'invoice_public_id' => $topUp->public_id, 'discount_code' => 'ANY'])->assertStatus(422);

        // the certificate can still come; accepting it voids the unpaid top-up
        $this->sendCertificate($invoice)->assertStatus(200);
        $certificate = $this->db->table('withholding_tax_certificate')->get()->getRow()->public_id;
        $data        = $this->body($this->send('v1/staff/subscriptions/certificate/accept', ['public_id' => $certificate], $this->staff))['data'];
        $this->assertNull($data['refund_invoice_no']);
        $this->seeInDatabase('subscription_invoice', ['id' => $topUp->id, 'invoice_status' => 'V']);
        $this->seeInDatabase('subscription_invoice', ['public_id' => $invoice, 'wht_cert_status' => 'A']);
    }

    public function testAPaidTopUpIsLeftToRefundAndPaysForNoPeriod(): void
    {
        $invoice = $this->paidYearlyInvoice();
        Time::setTestNow('2026-12-10 05:00:00', 'UTC');
        $this->runSchedule();
        $topUp = $this->db->table('subscription_invoice')->where('invoice_type', 'W')->get()->getRow();

        $this->activate($topUp->public_id);
        $this->seeInDatabase('subscription_invoice', ['id' => $topUp->id, 'invoice_status' => 'D', 'period_start' => null]);
        $this->seeInDatabase('payment_transaction', ['invoice_id' => $topUp->id, 'amount' => '89.70']);
        $this->seeInDatabase('subscription_master', ['current_period_start' => self::NOW, 'current_period_end' => '2027-11-10 05:00:00']);

        $this->sendCertificate($invoice)->assertStatus(200);
        $certificate = $this->db->table('withholding_tax_certificate')->get()->getRow()->public_id;
        $data        = $this->body($this->send('v1/staff/subscriptions/certificate/accept', ['public_id' => $certificate], $this->staff))['data'];
        $this->assertSame($topUp->invoice_no, $data['refund_invoice_no']);
    }

    public function testALateMonthlyCertificateBlocksTheRenewalUntilItIsSent(): void
    {
        $this->db->table('plan_version')->where('public_id', 'version0')->update(['price' => '1290.00']);
        $this->approveWithholding();
        $first = $this->subscribe('M');
        $this->activate($first);
        $this->seeInDatabase('subscription_invoice', ['public_id' => $first, 'wht_amount' => '38.70', 'wht_cert_status' => 'W', 'wht_cert_due_at' => '2026-12-10 05:00:00']);

        Time::setTestNow('2026-12-05 05:00:00', 'UTC');
        $this->runSchedule();
        $renewal = $this->db->table('subscription_invoice')->where('invoice_status', 'P')->get()->getRow()->public_id;

        Time::setTestNow('2026-12-11 05:00:00', 'UTC');
        $this->assertSame(0, $this->runSchedule()['topped_up']);
        $response = $this->pay($renewal);
        $response->assertStatus(409);
        $this->assertSame(lang('Subscriptions.certificateOverdue'), $this->body($response)['message']);

        $this->sendCertificate($first, ['base_amount' => '1290.00', 'wht_amount' => '38.70'])->assertStatus(200);
        $this->pay($renewal)->assertStatus(200);
    }

    // ---- helpers ----

    private function runSchedule(): array
    {
        return (new SubscriptionBilling())->runSchedule();
    }

    private function requestWithholding(): void
    {
        $this->send('v1/client/subscription/withholding', ['business_slug' => 'otter-cafe', 'legal_name' => 'Otter Cafe Co., Ltd.', 'tax_branch_code' => '00000'])
            ->assertStatus(200);
    }

    private function approveWithholding(): void
    {
        $this->requestWithholding();
        $this->send('v1/staff/subscriptions/withholding/review', ['business_slug' => 'otter-cafe', 'wht_status' => 'A'], $this->staff)->assertStatus(200);
    }

    /**
     * Subscribe to boost; the first invoice's public_id
     */
    private function subscribe(string $cycle): string
    {
        $response = $this->send('v1/client/subscription/subscribe', ['business_slug' => 'otter-cafe', 'plan_code' => 'boost', 'billing_cycle' => $cycle]);
        $response->assertStatus(200);

        return $this->body($response)['data']['invoice_public_id'];
    }

    /**
     * A yearly invoice of a business that withholds, paid now; its public_id
     */
    private function paidYearlyInvoice(): string
    {
        $this->approveWithholding();
        $invoice = $this->subscribe('Y');
        $this->activate($invoice);

        return $invoice;
    }

    /**
     * Pay the invoice by slip and have the staff approve it
     */
    private function activate(string $invoice): void
    {
        $response = $this->pay($invoice);
        $response->assertStatus(200);
        $payment = $this->body($response)['data']['payment_public_id'];
        $this->send('v1/staff/subscriptions/payment/approve', ['public_id' => $payment], $this->staff)->assertStatus(200);
    }

    private function pay(string $invoice): TestResponse
    {
        $this->attach('slip');

        return $this->withHeaders(['Authorization' => 'Bearer ' . $this->owner])
            ->post('v1/client/subscription/pay', ['business_slug' => 'otter-cafe', 'invoice_public_id' => $invoice, 'method_code' => 'promptpay']);
    }

    private function sendCertificate(string $invoice, array $fields = []): TestResponse
    {
        $this->attach('certificate');

        return $this->withHeaders(['Authorization' => 'Bearer ' . $this->owner])
            ->post('v1/client/subscription/certificate', $fields + [
                'business_slug'     => 'otter-cafe',
                'invoice_public_id' => $invoice,
                'certificate_no'    => '001/0042',
                'issued_on'         => '2026-11-10',
                'base_amount'       => '2990.00',
                'wht_amount'        => '89.70',
            ]);
    }

    /**
     * A 1x1 PNG as the upload of $field
     */
    private function attach(string $field): void
    {
        $source = tempnam(sys_get_temp_dir(), 'wht');
        file_put_contents($source, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true));
        $this->sources[] = $source;
        service('superglobals')->setFilesArray([$field => ['name' => $field . '.png', 'type' => 'image/png', 'tmp_name' => $source, 'error' => UPLOAD_ERR_OK, 'size' => filesize($source)]]);
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

    private function body(TestResponse $response): array
    {
        return json_decode($response->getJSON(), true);
    }
}
