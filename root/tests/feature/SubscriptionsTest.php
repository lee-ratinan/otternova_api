<?php

use App\Libraries\ImageProcessor;
use App\Libraries\SubscriptionBilling;
use App\Models\BusinessTypeModel;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\I18n\Time;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\StreamFilterTrait;
use CodeIgniter\Test\TestResponse;
use Config\Billing;
use Config\Services;

/**
 * /client/subscription/* (the owners of a business subscribe, apply discounts and pay) and /staff/subscriptions/*
 * (the Executive Office and Finance review the payments and the subscriptions)
 *
 * @internal
 */
final class SubscriptionsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use StreamFilterTrait;

    protected $namespace = 'App'; // the app's own migrations, the schema of the real database

    /** Bangkok 2026-11-10 12:00 */
    private const NOW = '2026-11-10 05:00:00';

    private string $owner;
    private string $staff;
    private int $businessId;
    private int $staffId;
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
        $this->uploads                          = sys_get_temp_dir() . '/otternova-slips-' . bin2hex(random_bytes(4)) . '/';
        config(Billing::class)->slipUploadPath = $this->uploads;

        foreach (['EXE' => 'Executive Office', 'FIN' => 'Finance', 'ENG' => 'Engineering'] as $code => $name) {
            $this->db->table('otternova_department')->insert(['department_code' => $code, 'department_name' => $name, 'country_code' => 'TH']);
            $this->departments[$code] = (int) $this->db->insertID();
        }
        $staff         = $this->createStaff('fin@otternova.com', 'FIN');
        $this->staffId = (int) $staff['id'];
        $this->staff   = service('jwt')->issue($staff)['access_token'];

        $owner       = $this->createAccount('owner@example.com', 'C');
        $this->db->table('client_master')->insert(['auth_id' => $owner['id']]);
        $this->owner = service('jwt')->issue($owner)['access_token'];
        $types       = new BusinessTypeModel();
        $typeId      = $types->publicIdOf($types->createWithNames(['type_code' => 'cafe'], ['en-US' => 'Café', 'th' => 'คาเฟ่'], $this->staffId));
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
        foreach ([['TH', 'THB', 'M', '299.00'], ['TH', 'THB', 'Y', '2990.00'], ['ZZ', 'USD', 'M', '9.00']] as $i => [$country, $currency, $cycle, $price]) {
            $this->db->table('plan_version')->insert(['public_id' => 'version' . $i, 'plan_id' => $boost, 'country_code' => $country, 'currency_code' => $currency,
                'billing_cycle' => $cycle, 'price' => $price, 'effective_from' => '2026-01-01 00:00:00']);
        }
        foreach (['promptpay' => 1, 'bank_transfer' => 2] as $code => $order) {
            $this->db->table('payment_method')->insert(['method_code' => $code, 'country_code' => 'TH', 'currency_code' => 'THB', 'confirmation_mode' => 'M', 'sort_order' => $order]);
        }
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

    public function testOnlyActiveOwnersGetIn(): void
    {
        $this->withHeaders([])->post('v1/client/subscription/current')->assertStatus(401);
        $this->send('v1/client/subscription/current', ['business_slug' => 'otter-cafe'], $this->staff)->assertStatus(403);

        foreach (['M' => 403, 'E' => 403] as $role => $status) {
            $this->send('v1/client/subscription/current', ['business_slug' => 'otter-cafe'], $this->createMember(strtolower($role) . 'member@example.com', $role))->assertStatus($status);
        }
        $this->send('v1/client/subscription/current', ['business_slug' => 'nobody'])->assertStatus(404);
        $this->send('v1/client/subscription/current', [])->assertStatus(400);

        $response = $this->send('v1/client/subscription/current', ['business_slug' => 'otter-cafe']);
        $response->assertStatus(200);
        $this->assertSame(['plan_code' => 'free', 'subscription' => null, 'invoices' => []], $this->body($response)['data']);
    }

    public function testPlansShowTheCountrysPricesAndTheNextChange(): void
    {
        $this->db->table('plan_version')->where('public_id', 'version0')->update(['effective_to' => '2026-11-14 17:00:00']);
        $this->db->table('plan_version')->insert(['public_id' => 'version9', 'plan_id' => 2, 'country_code' => 'TH', 'currency_code' => 'THB',
            'billing_cycle' => 'M', 'price' => '399.00', 'effective_from' => '2026-11-14 17:00:00']);

        $plans = $this->body($this->send('v1/client/subscription/plans', ['business_slug' => 'otter-cafe']))['data']['plans'];
        $this->assertSame(['free', 'boost'], array_column($plans, 'plan_code'));
        $this->assertSame([], $plans[0]['prices']);
        $this->assertSame([
            ['billing_cycle' => 'M', 'currency_code' => 'THB', 'price' => '299.00', 'next_price' => '399.00', 'next_price_from' => '2026-11-14 17:00:00'],
            ['billing_cycle' => 'Y', 'currency_code' => 'THB', 'price' => '2990.00', 'next_price' => null, 'next_price_from' => null],
        ], $plans[1]['prices']);
    }

    public function testASlipPaymentApprovedByStaffActivatesTheSubscription(): void
    {
        $response = $this->send('v1/client/subscription/subscribe', ['business_slug' => 'otter-cafe', 'plan_code' => 'boost', 'billing_cycle' => 'm']);
        $response->assertStatus(200);
        $data = $this->body($response)['data'];
        $this->assertSame(['P', '299.00', 'THB'], [$data['subscription_status'], $data['amount_due'], $data['currency_code']]);
        $invoice = $data['invoice_public_id'];
        $this->seeInDatabase('subscription_invoice', ['public_id' => $invoice, 'due_at' => '2026-11-11 05:00:00', 'invoice_no' => 'INV-202611-000001']);

        // one live subscription per business
        $this->send('v1/client/subscription/subscribe', ['business_slug' => 'otter-cafe', 'plan_code' => 'boost', 'billing_cycle' => 'Y'])->assertStatus(409);
        $this->send('v1/client/subscription/subscribe', ['business_slug' => 'otter-cafe', 'plan_code' => 'free', 'billing_cycle' => 'M'])->assertStatus(422);

        $methods = $this->body($this->send('v1/client/subscription/payment-methods', ['business_slug' => 'otter-cafe', 'invoice_public_id' => $invoice]))['data']['payment_methods'];
        $this->assertSame(['promptpay', 'bank_transfer'], array_column($methods, 'method_code'));

        $payment = $this->pay($invoice, 'promptpay');
        $payment->assertStatus(200);
        $payment = $this->body($payment)['data']['payment_public_id'];
        $this->pay($invoice, 'bank_transfer')->assertStatus(409); // one waits for the staff already

        // the staff see it waiting, with its slip
        $rows = $this->body($this->send('v1/staff/subscriptions/payments', ['filters' => ['txn_status' => 'P']], $this->staff))['data']['rows'];
        $this->assertSame([[$payment, 'INV-202611-000001', 'otter-cafe', '299.00', true]],
            array_map(static fn ($row) => [$row['public_id'], $row['invoice_no'], $row['business_slug'], $row['amount'], $row['has_slip']], $rows));
        $slip = $this->withHeaders(['Authorization' => 'Bearer ' . $this->staff])->get('v1/staff/subscriptions/slip/' . $payment);
        $slip->assertStatus(200);
        $this->assertSame('image/png', $slip->response()->getHeaderLine('Content-Type'));

        // approved a day later: paid as of when it was sent, so the period runs from then to the same day next month
        Time::setTestNow('2026-11-11 02:00:00', 'UTC');
        $engineer = service('jwt')->issue($this->createStaff('eng@otternova.com', 'ENG'))['access_token'];
        $this->send('v1/staff/subscriptions/payment/approve', ['public_id' => $payment], $engineer)->assertStatus(403);
        $this->send('v1/staff/subscriptions/payment/approve', ['public_id' => $payment], $this->staff)->assertStatus(200);
        $this->send('v1/staff/subscriptions/payment/approve', ['public_id' => $payment], $this->staff)->assertStatus(409);

        $current = $this->body($this->send('v1/client/subscription/current', ['business_slug' => 'otter-cafe']))['data'];
        $this->assertSame(['boost', 'A', self::NOW, '2026-12-10 05:00:00', '2026-12-10 05:00:00', 10],
            [$current['plan_code'], $current['subscription']['subscription_status'], $current['subscription']['current_period_start'],
                $current['subscription']['current_period_end'], $current['subscription']['next_billing_at'], $current['subscription']['billing_anchor_day']]);
        $this->assertSame(['D', self::NOW, 'S'], [$current['invoices'][0]['invoice_status'], $current['invoices'][0]['paid_at'], $current['invoices'][0]['payments'][0]['txn_status']]);
        $this->seeInDatabase('log_activity', ['table_involved' => 'payment_transaction', 'activity_summary' => 'Approved payment of invoice INV-202611-000001']);
    }

    public function testARejectedPaymentCanBeSentAgain(): void
    {
        $invoice = $this->subscribe();
        $payment = $this->body($this->pay($invoice, 'bank_transfer'))['data']['payment_public_id'];

        $this->send('v1/staff/subscriptions/payment/reject', ['public_id' => $payment], $this->staff)->assertStatus(422);
        $this->send('v1/staff/subscriptions/payment/reject', ['public_id' => $payment, 'failure_reason' => 'The slip is unreadable'], $this->staff)->assertStatus(200);
        $this->seeInDatabase('payment_transaction', ['public_id' => $payment, 'txn_status' => 'F', 'failure_reason' => 'The slip is unreadable', 'confirmed_by' => $this->staffId]);
        $this->seeInDatabase('subscription_invoice', ['public_id' => $invoice, 'invoice_status' => 'P']);

        $this->pay($invoice, 'bank_transfer')->assertStatus(200);
    }

    public function testDiscountsCutTheInvoiceAndCountTheirUses(): void
    {
        $this->discount(['discount_code' => 'TENOFF', 'discount_value' => '10.00', 'max_redemptions' => 1]);
        $this->discount(['discount_code' => 'HIDDEN', 'discount_value' => '15.00', 'is_listed' => 'N']);
        $this->discount(['discount_code' => 'YEARLY', 'discount_value' => '20.00', 'billing_cycle' => 'Y']);
        $this->discount(['discount_code' => 'EXPIRED', 'discount_value' => '20.00', 'expires_at' => '2026-11-01 00:00:00']);

        // only the listed ones valid now for this plan and cycle are offered
        $listed = $this->body($this->send('v1/client/subscription/discounts', ['business_slug' => 'otter-cafe', 'plan_code' => 'boost', 'billing_cycle' => 'M']))['data']['discounts'];
        $this->assertSame(['TENOFF'], array_column($listed, 'discount_code'));

        $response = $this->send('v1/client/subscription/subscribe', ['business_slug' => 'otter-cafe', 'plan_code' => 'boost', 'billing_cycle' => 'M', 'discount_code' => 'yearly']);
        $response->assertStatus(422);
        $this->assertSame(['discount_code' => lang('Subscriptions.discountNotForCycle')], $this->body($response)['errors']);

        // 10% of 299.00 is 29.90
        $response = $this->send('v1/client/subscription/subscribe', ['business_slug' => 'otter-cafe', 'plan_code' => 'boost', 'billing_cycle' => 'M', 'discount_code' => 'tenoff']);
        $response->assertStatus(200);
        $invoice = $this->body($response)['data']['invoice_public_id'];
        $this->assertSame('269.10', $this->body($response)['data']['amount_due']);
        $this->seeInDatabase('discount_master', ['discount_code' => 'TENOFF', 'redemption_count' => 1]);

        // swapped for a code typed in: the first one's use is given back; 15% of 299.00 is 44.85
        $response = $this->send('v1/client/subscription/apply-discount', ['business_slug' => 'otter-cafe', 'invoice_public_id' => $invoice, 'discount_code' => 'HIDDEN']);
        $response->assertStatus(200);
        $this->assertSame(['299.00', '44.85', '254.15'], [$this->body($response)['data']['list_price'], $this->body($response)['data']['discount_amount'], $this->body($response)['data']['amount_due']]);
        $this->seeInDatabase('discount_master', ['discount_code' => 'TENOFF', 'redemption_count' => 0]);
        $this->seeInDatabase('discount_master', ['discount_code' => 'HIDDEN', 'redemption_count' => 1]);

        // cancelled while pending: the invoice is voided and the use given back
        $this->send('v1/client/subscription/cancel', ['business_slug' => 'otter-cafe'])->assertStatus(200);
        $this->seeInDatabase('subscription_invoice', ['public_id' => $invoice, 'invoice_status' => 'V']);
        $this->seeInDatabase('discount_master', ['discount_code' => 'HIDDEN', 'redemption_count' => 0]);
    }

    public function testAFullDiscountActivatesAtOnceAndIsUsedOncePerBusiness(): void
    {
        $this->discount(['discount_code' => 'FREEMONTH', 'discount_type' => 'F', 'discount_value' => '500.00', 'currency_code' => 'THB', 'country_code' => 'TH']);
        $this->discount(['discount_code' => 'DOLLARS', 'discount_type' => 'F', 'discount_value' => '3.00', 'currency_code' => 'USD', 'country_code' => 'ZZ']);

        $response = $this->send('v1/client/subscription/subscribe', ['business_slug' => 'otter-cafe', 'plan_code' => 'boost', 'billing_cycle' => 'M', 'discount_code' => 'DOLLARS']);
        $this->assertSame(['discount_code' => lang('Subscriptions.discountNotForCountry')], $this->body($response)['errors']);

        $response = $this->send('v1/client/subscription/subscribe', ['business_slug' => 'otter-cafe', 'plan_code' => 'boost', 'billing_cycle' => 'M', 'discount_code' => 'FREEMONTH']);
        $response->assertStatus(200);
        $this->assertSame(['A', '0.00'], [$this->body($response)['data']['subscription_status'], $this->body($response)['data']['amount_due']]);
        $this->seeInDatabase('subscription_invoice', ['public_id' => $this->body($response)['data']['invoice_public_id'], 'invoice_status' => 'D', 'discount_amount' => '299.00']);

        // the business cancels at once (via the staff) and comes back: the code was used already
        $live = $this->body($response)['data']['subscription_public_id'];
        $this->send('v1/staff/subscriptions/subscription/status', ['public_id' => $live, 'subscription_status' => 'C'], $this->staff)->assertStatus(200);
        $response = $this->send('v1/client/subscription/subscribe', ['business_slug' => 'otter-cafe', 'plan_code' => 'boost', 'billing_cycle' => 'M', 'discount_code' => 'FREEMONTH']);
        $this->assertSame(['discount_code' => lang('Subscriptions.discountAlreadyUsed')], $this->body($response)['errors']);
    }

    public function testCancelAtPeriodEndCanBeResumed(): void
    {
        $this->activate($this->subscribe());

        $response = $this->send('v1/client/subscription/cancel', ['business_slug' => 'otter-cafe']);
        $response->assertStatus(200);
        $this->assertSame(['A', 'Y', self::NOW], [$this->body($response)['data']['subscription']['subscription_status'],
            $this->body($response)['data']['subscription']['cancel_at_period_end'], $this->body($response)['data']['subscription']['cancel_requested_at']]);
        $this->send('v1/client/subscription/cancel', ['business_slug' => 'otter-cafe'])->assertStatus(409);

        $response = $this->send('v1/client/subscription/resume', ['business_slug' => 'otter-cafe']);
        $response->assertStatus(200);
        $this->assertSame(['N', null], [$this->body($response)['data']['subscription']['cancel_at_period_end'], $this->body($response)['data']['subscription']['cancel_requested_at']]);
        $this->send('v1/client/subscription/resume', ['business_slug' => 'otter-cafe'])->assertStatus(409);
    }

    public function testARenewalPaidWithinTheGraceDaysStartsWhenTheLastPeriodEnded(): void
    {
        $this->activate($this->subscribe());
        $subscriptionId = (int) $this->db->table('subscription_master')->get()->getRow()->id;

        // two days after the period ended
        Time::setTestNow('2026-12-12 05:00:00', 'UTC');
        $renewal = $this->renewalInvoice($subscriptionId);
        $this->activate($renewal);
        $this->seeInDatabase('subscription_master', ['id' => $subscriptionId, 'current_period_start' => '2026-12-10 05:00:00', 'current_period_end' => '2027-01-10 05:00:00', 'billing_anchor_day' => 10]);
    }

    public function testASuspendedSubscriptionPaidLateStartsWhenPaid(): void
    {
        $this->activate($this->subscribe());
        $subscriptionId = (int) $this->db->table('subscription_master')->get()->getRow()->id;
        $public         = $this->db->table('subscription_master')->get()->getRow()->public_id;

        Time::setTestNow('2026-12-20 03:00:00', 'UTC'); // Bangkok 10:00 on the 20th
        $renewal = $this->renewalInvoice($subscriptionId);
        $this->send('v1/staff/subscriptions/subscription/status', ['public_id' => $public, 'subscription_status' => 'S'], $this->staff)->assertStatus(200);
        // the period has ended, so the staff cannot simply reactivate it
        $this->send('v1/staff/subscriptions/subscription/status', ['public_id' => $public, 'subscription_status' => 'A'], $this->staff)->assertStatus(409);

        $this->activate($renewal);
        $this->seeInDatabase('subscription_master', ['id' => $subscriptionId, 'subscription_status' => 'A', 'suspended_at' => null,
            'current_period_start' => '2026-12-20 03:00:00', 'current_period_end' => '2027-01-20 03:00:00', 'billing_anchor_day' => 20]);
    }

    public function testStaffListAndShowSubscriptions(): void
    {
        $invoice = $this->subscribe();
        $this->pay($invoice, 'promptpay');

        $list = $this->body($this->send('v1/staff/subscriptions/list', ['search' => 'otter', 'filters' => ['subscription_status' => 'p']], $this->staff))['data'];
        $this->assertSame([1, 'otter-cafe', 'boost', 'M', '299.00'], [$list['records_filtered'], $list['rows'][0]['business_slug'], $list['rows'][0]['plan_code'], $list['rows'][0]['billing_cycle'], $list['rows'][0]['price']]);

        $detail = $this->body($this->send('v1/staff/subscriptions/subscription', ['public_id' => $list['rows'][0]['public_id']], $this->staff))['data'];
        $this->assertSame([$invoice, 'promptpay', null], [$detail['invoices'][0]['public_id'], $detail['invoices'][0]['payments'][0]['method_code'], $detail['invoices'][0]['payments'][0]['confirmed_by_name']]);
        $this->send('v1/staff/subscriptions/subscription', ['public_id' => 'nope'], $this->staff)->assertStatus(404);

        // a payment waiting for review has to be dealt with before the subscription is cancelled
        $this->send('v1/staff/subscriptions/subscription/status', ['public_id' => $list['rows'][0]['public_id'], 'subscription_status' => 'C'], $this->staff)->assertStatus(409);
        $this->send('v1/staff/subscriptions/subscription/status', ['public_id' => $list['rows'][0]['public_id'], 'subscription_status' => 'Z'], $this->staff)->assertStatus(422);
    }

    public function testPeriodsKeepTheAnchorDayThroughShortMonths(): void
    {
        // Bangkok midnight, 31 January 2027, then the end of each month
        $end = SubscriptionBilling::periodEnd('2027-01-30 17:00:00', 'M', 31, 'Asia/Bangkok');
        $this->assertSame('2027-02-27 17:00:00', $end); // 28 Feb
        $this->assertSame('2027-03-30 17:00:00', SubscriptionBilling::periodEnd($end, 'M', 31, 'Asia/Bangkok')); // back to 31 Mar
        // 29 Feb 2028, yearly
        $this->assertSame('2029-02-27 17:00:00', SubscriptionBilling::periodEnd('2028-02-28 17:00:00', 'Y', 29, 'Asia/Bangkok'));
        $this->assertSame('2028-02-28 17:00:00', SubscriptionBilling::periodEnd('2027-02-27 17:00:00', 'Y', 29, 'Asia/Bangkok'));
        $this->assertNull(SubscriptionBilling::parseTime('2026-11-15 00:00:00'));
        $this->assertNull(SubscriptionBilling::parseTime('2026-02-30T00:00:00Z'));
        $this->assertSame('2026-11-14 17:00:00', SubscriptionBilling::parseTime('2026-11-15T00:00:00+07:00'));
    }

    public function testSwitchingBusinessGivesThePlanItIsOn(): void
    {
        $plan = function (): array {
            $business = $this->body($this->send('v1/client/business/switch', ['business_slug' => 'otter-cafe']))['data']['business']['business'];

            return [$business['plan_code'], $business['subscription_status']];
        };
        $this->assertSame(['free', null], $plan());

        $invoice = $this->subscribe();
        $this->assertSame(['free', 'P'], $plan()); // not paid yet

        $this->activate($invoice);
        $this->assertSame(['boost', 'A'], $plan());

        $public = $this->db->table('subscription_master')->get()->getRow()->public_id;
        $this->send('v1/staff/subscriptions/subscription/status', ['public_id' => $public, 'subscription_status' => 'S'], $this->staff)->assertStatus(200);
        $this->assertSame(['free', 'S'], $plan());
    }

    // ---- the scheduled run ----

    public function testAFirstInvoiceLeftUnpaidIsVoidedAndTheDiscountGivenBack(): void
    {
        $this->discount(['discount_code' => 'TENOFF', 'discount_value' => '10.00', 'max_redemptions' => 1]);
        $this->send('v1/client/subscription/subscribe', ['business_slug' => 'otter-cafe', 'plan_code' => 'boost', 'billing_cycle' => 'M', 'discount_code' => 'TENOFF'])->assertStatus(200);

        $this->assertSame(0, $this->runSchedule()['voided']); // 24 hours to pay
        Time::setTestNow('2026-11-11 05:00:00', 'UTC');
        $this->assertSame(1, $this->runSchedule()['voided']);
        $this->seeInDatabase('subscription_master', ['subscription_status' => 'X', 'ended_at' => '2026-11-11 05:00:00', 'cancelled_at' => null]);
        $this->seeInDatabase('subscription_invoice', ['invoice_status' => 'V']);
        $this->seeInDatabase('discount_master', ['discount_code' => 'TENOFF', 'redemption_count' => 0]);
        $this->seeInDatabase('log_activity', ['table_involved' => 'subscription_master', 'activity_summary' => 'Expired subscription', 'changed_by_acc' => null]);

        // the business can start again
        $this->subscribe();
    }

    public function testAFirstInvoiceWithASlipUnderReviewIsKept(): void
    {
        $this->pay($this->subscribe(), 'promptpay');
        Time::setTestNow('2026-11-12 05:00:00', 'UTC');

        $counts = $this->runSchedule();
        $this->assertSame([0, 1], [$counts['voided'], $counts['skipped']]);
        $this->seeInDatabase('subscription_master', ['subscription_status' => 'P']);
    }

    public function testRenewalIsInvoicedAtTheNewPriceThenSuspendedThenExpired(): void
    {
        $this->activate($this->subscribe()); // period ends 2026-12-10 05:00
        // the price rises from 1 December
        $this->db->table('plan_version')->where('public_id', 'version0')->update(['effective_to' => '2026-11-30 17:00:00']);
        $this->db->table('plan_version')->insert(['public_id' => 'version9', 'plan_id' => 2, 'country_code' => 'TH', 'currency_code' => 'THB',
            'billing_cycle' => 'M', 'price' => '399.00', 'effective_from' => '2026-11-30 17:00:00']);

        Time::setTestNow('2026-12-03 04:00:00', 'UTC'); // just over 7 days before
        $this->assertSame(0, $this->runSchedule()['invoiced']);
        Time::setTestNow('2026-12-03 05:00:00', 'UTC');
        $this->assertSame(1, $this->runSchedule()['invoiced']);
        $this->seeInDatabase('subscription_invoice', ['invoice_no' => 'INV-202612-000002', 'invoice_status' => 'P', 'due_at' => '2026-12-13 05:00:00', 'plan_version_id' => 4]);
        $this->assertSame(0, $this->runSchedule()['invoiced']); // made once only

        Time::setTestNow('2026-12-13 04:59:59', 'UTC');
        $this->assertSame(0, $this->runSchedule()['suspended']);
        Time::setTestNow('2026-12-13 05:00:00', 'UTC');
        $this->assertSame(1, $this->runSchedule()['suspended']);
        $this->seeInDatabase('subscription_master', ['subscription_status' => 'S', 'suspended_at' => '2026-12-13 05:00:00']);

        Time::setTestNow('2027-01-12 05:00:00', 'UTC'); // 30 days suspended
        $this->assertSame(1, $this->runSchedule()['expired']);
        $this->seeInDatabase('subscription_master', ['subscription_status' => 'X', 'ended_at' => '2027-01-12 05:00:00']);
        $this->seeInDatabase('subscription_invoice', ['invoice_no' => 'INV-202612-000002', 'invoice_status' => 'V']);
    }

    public function testARenewalPaidEarlyContinuesFromThePeriodEnd(): void
    {
        $this->activate($this->subscribe());
        Time::setTestNow('2026-12-05 05:00:00', 'UTC');
        $this->runSchedule();
        $renewal = $this->db->table('subscription_invoice')->where('invoice_status', 'P')->get()->getRow()->public_id;

        $this->activate($renewal);
        $this->seeInDatabase('subscription_master', ['subscription_status' => 'A', 'current_period_start' => '2026-12-10 05:00:00', 'current_period_end' => '2027-01-10 05:00:00']);
    }

    public function testASlipUnderReviewHoldsTheSuspension(): void
    {
        $this->activate($this->subscribe());
        Time::setTestNow('2026-12-05 05:00:00', 'UTC');
        $this->runSchedule();
        Time::setTestNow('2026-12-12 05:00:00', 'UTC');
        $this->pay($this->db->table('subscription_invoice')->where('invoice_status', 'P')->get()->getRow()->public_id, 'promptpay');

        Time::setTestNow('2026-12-15 05:00:00', 'UTC');
        $counts = $this->runSchedule();
        $this->assertSame([0, 1], [$counts['suspended'], $counts['skipped']]);
        $this->seeInDatabase('subscription_master', ['subscription_status' => 'A']);
    }

    public function testACancellationTakesEffectAtThePeriodEndWithNoRenewal(): void
    {
        $this->activate($this->subscribe());
        $this->send('v1/client/subscription/cancel', ['business_slug' => 'otter-cafe'])->assertStatus(200);

        Time::setTestNow('2026-12-05 05:00:00', 'UTC');
        $this->assertSame(0, $this->runSchedule()['invoiced']);
        Time::setTestNow('2026-12-10 05:00:00', 'UTC');
        $this->assertSame(1, $this->runSchedule()['cancelled']);
        $this->seeInDatabase('subscription_master', ['subscription_status' => 'C', 'cancelled_at' => '2026-12-10 05:00:00', 'ended_at' => '2026-12-10 05:00:00']);
        $this->assertSame(1, $this->db->table('subscription_invoice')->countAllResults());
    }

    public function testTheCommandReportsWhatItDid(): void
    {
        $this->subscribe();
        Time::setTestNow('2026-11-12 05:00:00', 'UTC');

        command('billing:run');
        $output = $this->getStreamFilterBuffer();
        $this->assertStringContainsString('voided 1, cancelled 0, invoiced 0, suspended 0, expired 0, skipped 0, failed 0', $output);
    }

    // ---- helpers ----

    private function runSchedule(): array
    {
        return (new SubscriptionBilling())->runSchedule();
    }

    /**
     * Subscribe to boost monthly; the first invoice's public_id
     */
    private function subscribe(): string
    {
        $response = $this->send('v1/client/subscription/subscribe', ['business_slug' => 'otter-cafe', 'plan_code' => 'boost', 'billing_cycle' => 'M']);
        $response->assertStatus(200);

        return $this->body($response)['data']['invoice_public_id'];
    }

    /**
     * Pay the invoice by slip and have the staff approve it
     */
    private function activate(string $invoice): void
    {
        $response = $this->pay($invoice, 'promptpay');
        $response->assertStatus(200);
        $payment = $this->body($response)['data']['payment_public_id'];
        $this->send('v1/staff/subscriptions/payment/approve', ['public_id' => $payment], $this->staff)->assertStatus(200);
    }

    /**
     * A renewal invoice as the renewal run will make it; its public_id
     */
    private function renewalInvoice(int $subscriptionId): string
    {
        $this->db->table('subscription_invoice')->insert(['public_id' => 'renewal00001', 'invoice_no' => 'INV-202612-000002', 'subscription_id' => $subscriptionId,
            'plan_version_id' => 1, 'currency_code' => 'THB', 'list_price' => '299.00', 'amount_due' => '299.00', 'due_at' => '2026-12-13 05:00:00']);

        return 'renewal00001';
    }

    private function discount(array $fields): void
    {
        $this->db->table('discount_master')->insert($fields + [
            'public_id'     => substr(md5($fields['discount_code']), 0, 8),
            'discount_type' => 'P',
            'country_code'  => 'TH',
            'valid_from'    => '2026-11-01 00:00:00',
            'expires_at'    => '2027-01-01 00:00:00',
        ]);
    }

    private function pay(string $invoice, string $method): TestResponse
    {
        $source = tempnam(sys_get_temp_dir(), 'slip');
        // a 1x1 PNG
        file_put_contents($source, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true));
        $this->sources[] = $source;
        service('superglobals')->setFilesArray(['slip' => ['name' => 'slip.png', 'type' => 'image/png', 'tmp_name' => $source, 'error' => UPLOAD_ERR_OK, 'size' => filesize($source)]]);

        return $this->withHeaders(['Authorization' => 'Bearer ' . $this->owner])
            ->post('v1/client/subscription/pay', ['business_slug' => 'otter-cafe', 'invoice_public_id' => $invoice, 'method_code' => $method]);
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

    private function body(TestResponse $response): array
    {
        return json_decode($response->getJSON(), true);
    }
}
