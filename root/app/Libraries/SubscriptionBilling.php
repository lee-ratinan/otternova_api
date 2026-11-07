<?php

namespace App\Libraries;

use App\Models\BusinessMasterModel;
use App\Models\DiscountMasterModel;
use App\Models\PaymentMethodModel;
use App\Models\PaymentTransactionModel;
use App\Models\PlanMasterModel;
use App\Models\PlanVersionModel;
use App\Models\SubscriptionInvoiceModel;
use App\Models\SubscriptionMasterModel;
use App\Models\TransactionTrait;
use App\Models\WithholdingTaxCertificateModel;
use App\Models\WithholdingTaxRuleModel;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\I18n\Time;
use Config\Billing as BillingConfig;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Throwable;

/**
 * The rules of subscriptions, invoices and payments, for the client's checkout (App\Controllers\Subscriptions) and the
 * staff's review (App\Controllers\SubscriptionAdmin). Every change is written with its log_activity rows in one
 * transaction; a request the rules refuse throws a BillingException and writes nothing.
 * Times are UTC (Y-m-d H:i:s); a billing period is worked out in the business's local time, from its country.
 * See the CreateSubscriptionMasterTable migration for the statuses, the grace days and the anchor day.
 *
 * Withholding tax (see the AddWithholdingTax migration): a business approved for it has the tax of its rule worked out
 * on each period invoice, when made and again when a discount changes amount_due, and pays amount_payable. Once paid,
 * the invoice waits for its certificate until wht_cert_due_at. Past that, a period invoice of the business cannot be
 * paid (so a monthly subscription is suspended at its renewal), and the scheduled run bills the tax of a yearly one
 * as a top-up invoice, whose renewal would come after the tax is due.
 */
class SubscriptionBilling
{
    use TransactionTrait;

    /** The format of every time the billing tables store */
    public const DATETIME = 'Y-m-d H:i:s';

    protected BaseConnection $db;
    private ActivityLog $log;

    public function __construct()
    {
        $this->db  = db_connect();
        $this->log = new ActivityLog();
    }

    // ---- times ----

    /**
     * The current time, which the tests can set with Time::setTestNow()
     * @return string UTC
     */
    public static function now(): string
    {
        return Time::now('UTC')->format(self::DATETIME);
    }

    /**
     * A time from the body, with its offset, in UTC: "2026-11-15T00:00:00+07:00" is "2026-11-14 17:00:00".
     * A time with no offset ("Z" or "+07:00") is refused, as it could be meant in any timezone.
     * @param mixed $value
     * @return string|null
     */
    public static function parseTime(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^(\d{4}-\d{2}-\d{2})[T ]\d{2}:\d{2}(:\d{2})?(Z|[+-]\d{2}:?\d{2})$/', trim($value), $parts)) {
            return null;
        }
        try {
            $time = new DateTimeImmutable(trim($value));
        } catch (Exception) {
            return null;
        }
        // a day that does not exist (2026-02-30) is rolled over by PHP rather than refused
        if ($time->format('Y-m-d') !== $parts[1]) {
            return null;
        }

        return $time->setTimezone(new DateTimeZone('UTC'))->format(self::DATETIME);
    }

    /**
     * The timezone billing periods are worked out in for a business in $countryCode
     * @param string $countryCode
     * @return string
     */
    public static function timezoneOf(string $countryCode): string
    {
        helper('list');
        $zones = listSupportedTimezones($countryCode);

        return $zones === [] ? 'UTC' : (string) array_key_first($zones);
    }

    /**
     * The day of the month of $at in $timezone, as billing_anchor_day
     * @param string $at UTC
     * @param string $timezone
     * @return int
     */
    public static function localDay(string $at, string $timezone): int
    {
        return (int) (new DateTimeImmutable($at, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($timezone))->format('j');
    }

    /**
     * The end of a period starting at $start: a month or a year later, on $anchorDay or the month's last day when it
     * is shorter, at the same local time
     * @param string $start        UTC
     * @param string $billingCycle M or Y
     * @param int    $anchorDay    1-31
     * @param string $timezone
     * @return string UTC
     */
    public static function periodEnd(string $start, string $billingCycle, int $anchorDay, string $timezone): string
    {
        $local = (new DateTimeImmutable($start, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($timezone));
        $month = (int) $local->format('n') - 1 + ($billingCycle === PlanVersionModel::YEARLY ? 12 : 1);
        $year  = (int) $local->format('Y') + intdiv($month, 12);
        $month = $month % 12 + 1;
        $days  = (int) (new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->format('t');

        return $local->setDate($year, $month, min($anchorDay, $days))->setTimezone(new DateTimeZone('UTC'))->format(self::DATETIME);
    }

    // ---- the business's checkout ----

    /**
     * Start a subscription: pending, with its first invoice at the price in effect now, the discount applied when a
     * code is given. An invoice with nothing to pay is paid at once, which makes the subscription active.
     * @param array       $business     With id and country_code
     * @param array       $plan         plan_master row
     * @param string      $billingCycle M or Y
     * @param string|null $discountCode
     * @param int         $actorId
     * @return array{subscription_id: int, invoice_id: int}
     * @throws BillingException
     * @throws Throwable
     */
    public function subscribe(array $business, array $plan, string $billingCycle, ?string $discountCode, int $actorId): array
    {
        if ((int) $plan['plan_tier'] === PlanMasterModel::FREE_TIER) {
            throw new BillingException('Subscriptions.freePlan', 422, ['plan_code' => lang('Subscriptions.freePlan')]);
        }
        $now     = self::now();
        $version = model(PlanVersionModel::class)->inEffect((int) $plan['id'], $business['country_code'], $billingCycle, $now);
        if ($version === null) {
            throw new BillingException('Subscriptions.noPrice', 422, ['billing_cycle' => lang('Subscriptions.noPrice')]);
        }
        if (model(SubscriptionMasterModel::class)->liveOfBusiness((int) $business['id']) !== null) {
            throw new BillingException('Subscriptions.alreadySubscribed', 409);
        }
        $discount = null;
        if ($discountCode !== null && $discountCode !== '') {
            $discount = $this->usableDiscount($discountCode, $business, $version, $now, null);
        }

        try {
            return $this->inTransaction(function () use ($business, $version, $discount, $actorId, $now) {
                $subscriptionId = $this->insert('subscription_master', [
                    'public_id'           => model(SubscriptionMasterModel::class)->newPublicId(),
                    'business_id'         => (int) $business['id'],
                    'plan_version_id'     => (int) $version['id'],
                    'subscription_status' => SubscriptionMasterModel::STATUS_PENDING,
                    'billing_mode'        => SubscriptionMasterModel::BILLING_MANUAL,
                ], $actorId, 'Subscribed business ' . $business['id']);

                $holdHours = config(BillingConfig::class)->invoiceHoldHours;
                $invoice   = $this->createInvoice($subscriptionId, $version, self::addTime($now, 'PT' . $holdHours . 'H'), $actorId, $discount);
                if (Money::isZero($invoice['amount_due'])) {
                    $this->markPaid($invoice, $now, $actorId);
                }

                return ['subscription_id' => $subscriptionId, 'invoice_id' => (int) $invoice['id']];
            });
        } catch (DatabaseException $e) {
            // another request subscribed the business in between: uq_subscription_master_one_live refused this one
            if (str_contains($e->getMessage(), 'live_guard') || str_contains($e->getMessage(), 'one_live')) {
                throw new BillingException('Subscriptions.alreadySubscribed', 409);
            }

            throw $e;
        }
    }

    /**
     * Apply a discount code to a pending invoice, in place of the one it had. An issued invoice never changes, so it
     * is voided and made again with the discount, under a new number; one with nothing left to pay is paid.
     * @param array  $invoice  Stored row
     * @param array  $business With id and country_code
     * @param string $code
     * @param int    $actorId
     * @return array The invoice as stored now: the new one, or $invoice when it has that discount already
     * @throws BillingException
     * @throws Throwable
     */
    public function applyDiscount(array $invoice, array $business, string $code, int $actorId): array
    {
        $this->assertPayable($invoice);
        if ($invoice['invoice_type'] === SubscriptionInvoiceModel::TYPE_TOP_UP) {
            throw new BillingException('Subscriptions.noDiscountOnTopUp', 422, ['discount_code' => lang('Subscriptions.noDiscountOnTopUp')]);
        }
        $version  = model(PlanVersionModel::class)->find((int) $invoice['plan_version_id']);
        $discount = $this->usableDiscount($code, $business, $version, self::now(), (int) $invoice['id']);
        if ((int) $discount['id'] === (int) ($invoice['discount_id'] ?? 0)) {
            return $invoice;
        }

        $id = $this->inTransaction(function () use ($invoice, $discount, $actorId) {
            if ($invoice['discount_id'] !== null) {
                model(DiscountMasterModel::class)->release((int) $invoice['discount_id']);
            }
            $amounts = $this->amountsOf((int) $invoice['subscription_id'], Money::format($invoice['list_price']), $invoice['currency_code'], $discount);
            $new     = $this->reissue($invoice, $amounts, $actorId, 'applied discount ' . $discount['discount_code']);
            if (Money::isZero($new['amount_due'])) {
                $this->markPaid($new, self::now(), $actorId);
            }

            return (int) $new['id'];
        });

        return $this->db->table('subscription_invoice')->where('id', $id)->get()->getRowArray();
    }

    /**
     * Record a payment of a pending invoice, waiting for the staff to check it
     * @param array         $invoice   Stored row
     * @param array         $business  With country_code
     * @param array         $method    payment_method row
     * @param string|null   $providerRef
     * @param callable|null $placeSlip fn (int $transactionId): string, puts the slip in place inside the transaction
     *                                 and gives its file name; throws when it cannot
     * @param int           $actorId
     * @return int The payment_transaction id
     * @throws BillingException
     * @throws Throwable
     */
    public function submitPayment(array $invoice, array $business, array $method, ?string $providerRef, ?callable $placeSlip, int $actorId): int
    {
        $this->assertPayable($invoice);
        if (! PaymentMethodModel::usable($method, $business['country_code'], $invoice['currency_code'])) {
            throw new BillingException('Subscriptions.methodNotUsable', 422, ['method_code' => lang('Subscriptions.methodNotUsable')]);
        }
        if ($method['confirmation_mode'] !== PaymentMethodModel::CONFIRMED_BY_STAFF) {
            throw new BillingException('Subscriptions.methodNotUsable', 422, ['method_code' => lang('Subscriptions.methodNotUsable')]);
        }

        return $this->inTransaction(function () use ($invoice, $method, $providerRef, $placeSlip, $actorId) {
            $summary = 'Paid invoice ' . $invoice['invoice_no'] . ' by ' . $method['method_code'];
            $id      = $this->insert('payment_transaction', [
                'public_id'         => model(PaymentTransactionModel::class)->newPublicId(),
                'invoice_id'        => (int) $invoice['id'],
                'payment_method_id' => (int) $method['id'],
                'currency_code'     => $invoice['currency_code'],
                'amount'            => Money::format($invoice['amount_payable']),
                'txn_status'        => PaymentTransactionModel::STATUS_PENDING,
                'provider_ref'      => $providerRef,
                // when the payment was sent, which approvePayment() pays the invoice as of; by the billing clock
                'created_at'        => self::now(),
            ], $actorId, $summary);
            if ($placeSlip !== null) {
                $stored = $this->db->table('payment_transaction')->where('id', $id)->get()->getRowArray();
                $this->change('payment_transaction', $stored, ['slip_path' => $placeSlip($id)], $actorId, $summary);
            }

            return $id;
        });
    }

    /**
     * Cancel the business's subscription: a pending or suspended one, or one past its period end, at once; an active
     * one at the end of its period
     * @param array $subscription Stored row
     * @param int   $actorId
     * @return void
     * @throws BillingException
     * @throws Throwable
     */
    public function cancelByBusiness(array $subscription, int $actorId): void
    {
        $now = self::now();
        if ($subscription['subscription_status'] === SubscriptionMasterModel::STATUS_ACTIVE
            && $subscription['current_period_end'] !== null && $subscription['current_period_end'] > $now) {
            if ($subscription['cancel_at_period_end'] === 'Y') {
                throw new BillingException('Subscriptions.alreadyCancelling', 409);
            }
            $this->inTransaction(fn () => $this->change('subscription_master', $subscription,
                ['cancel_at_period_end' => 'Y', 'cancel_requested_at' => $now], $actorId, 'Cancelled subscription at period end'));

            return;
        }
        $this->endNow($subscription, $actorId, SubscriptionMasterModel::STATUS_CANCELLED, ['cancel_requested_at' => $now]);
    }

    /**
     * Undo a cancellation at the end of the period
     * @param array $subscription Stored row
     * @param int   $actorId
     * @return void
     * @throws BillingException
     * @throws Throwable
     */
    public function resume(array $subscription, int $actorId): void
    {
        if ($subscription['subscription_status'] !== SubscriptionMasterModel::STATUS_ACTIVE || $subscription['cancel_at_period_end'] !== 'Y') {
            throw new BillingException('Subscriptions.notCancelling', 409);
        }
        $this->inTransaction(fn () => $this->change('subscription_master', $subscription,
            ['cancel_at_period_end' => 'N', 'cancel_requested_at' => null], $actorId, 'Resumed subscription'));
    }

    /**
     * Move an active subscription to another plan or billing cycle from its next period: next_plan_version_id is set
     * to the version of that series in effect now, so the renewal is invoiced at the series' price when the next
     * period starts (see invoiceRenewal()). A renewal invoice already made and not yet paid is voided and made again
     * at the new plan, due when the voided one was. Choosing the plan and cycle it is on takes back a change set earlier.
     * @param array  $subscription Stored row
     * @param array  $business     With id and country_code
     * @param array  $plan         plan_master row
     * @param string $billingCycle M or Y
     * @param int    $actorId
     * @return bool True when a change is set, false when one was taken back
     * @throws BillingException
     * @throws Throwable
     */
    public function changePlan(array $subscription, array $business, array $plan, string $billingCycle, int $actorId): bool
    {
        if ((int) $plan['plan_tier'] === PlanMasterModel::FREE_TIER) {
            throw new BillingException('Subscriptions.changeToFree', 422, ['plan_code' => lang('Subscriptions.changeToFree')]);
        }
        if ($subscription['subscription_status'] !== SubscriptionMasterModel::STATUS_ACTIVE) {
            throw new BillingException('Subscriptions.changeNotActive', 409);
        }
        if ($subscription['cancel_at_period_end'] === 'Y') {
            throw new BillingException('Subscriptions.changeWhileCancelling', 409);
        }
        $versions = model(PlanVersionModel::class);
        $version  = $versions->inEffect((int) $plan['id'], $business['country_code'], $billingCycle, self::now());
        if ($version === null) {
            throw new BillingException('Subscriptions.noPrice', 422, ['billing_cycle' => lang('Subscriptions.noPrice')]);
        }
        $sameSeries = static fn (?array $a, array $b) => $a !== null && (int) $a['plan_id'] === (int) $b['plan_id'] && $a['billing_cycle'] === $b['billing_cycle'];
        $current    = $versions->find((int) $subscription['plan_version_id']);
        $scheduled  = $subscription['next_plan_version_id'] === null ? null : $versions->find((int) $subscription['next_plan_version_id']);
        $next       = $sameSeries($current, $version) ? null : (int) $version['id'];
        if ($next === null ? $scheduled === null : $sameSeries($scheduled, $version)) {
            throw new BillingException('Subscriptions.planUnchanged', 409);
        }

        $invoices = model(SubscriptionInvoiceModel::class)->pendingOf((int) $subscription['id']);
        foreach ($invoices as $invoice) {
            if (model(PaymentTransactionModel::class)->hasPending((int) $invoice['id'])) {
                throw new BillingException('Subscriptions.paymentUnderReview', 409);
            }
        }
        $series = $next === null ? $current : $version;
        $this->inTransaction(function () use ($subscription, $business, $invoices, $next, $series, $actorId) {
            $summary = $next === null ? 'Took back plan change' : 'Changed plan from next period';
            if (! $this->change('subscription_master', $subscription, ['next_plan_version_id' => $next], $actorId, $summary,
                ['subscription_status' => SubscriptionMasterModel::STATUS_ACTIVE, 'cancel_at_period_end' => 'N'])) {
                throw new BillingException('Subscriptions.changeNotActive', 409);
            }
            if ($invoices === []) {
                return;
            }
            // the renewal was invoiced at the plan it had: made again at the one it will have
            $now = self::now();
            foreach ($invoices as $invoice) {
                $this->change('subscription_invoice', $invoice, ['invoice_status' => SubscriptionInvoiceModel::STATUS_VOID, 'voided_at' => $now],
                    $actorId, 'Voided invoice ' . $invoice['invoice_no'] . ' for a plan change', ['invoice_status' => SubscriptionInvoiceModel::STATUS_PENDING]);
                if ($invoice['discount_id'] !== null) {
                    model(DiscountMasterModel::class)->release((int) $invoice['discount_id']);
                }
            }
            $renewal = model(PlanVersionModel::class)->inEffect((int) $series['plan_id'], $business['country_code'], $series['billing_cycle'], $subscription['current_period_end']);
            if ($renewal === null) {
                throw new BillingException('Subscriptions.noPrice', 422, ['billing_cycle' => lang('Subscriptions.noPrice')]);
            }
            $this->createInvoice((int) $subscription['id'], $renewal, end($invoices)['due_at'], $actorId);
        });

        return $next !== null;
    }

    // ---- the staff's review ----

    /**
     * Confirm a pending payment: the invoice is paid as of when the payment was sent, which starts its period
     * @param array $transaction Stored row
     * @param int   $actorId
     * @return void
     * @throws BillingException
     * @throws Throwable
     */
    public function approvePayment(array $transaction, int $actorId): void
    {
        if ($transaction['txn_status'] !== PaymentTransactionModel::STATUS_PENDING) {
            throw new BillingException('SubscriptionAdmin.paymentNotPending', 409);
        }
        $invoice = $this->db->table('subscription_invoice')->where('id', $transaction['invoice_id'])->get()->getRowArray();
        if ($invoice['invoice_status'] !== SubscriptionInvoiceModel::STATUS_PENDING) {
            throw new BillingException('SubscriptionAdmin.invoiceNotPending', 409);
        }

        $this->inTransaction(function () use ($transaction, $invoice, $actorId) {
            $done = $this->change('payment_transaction', $transaction, [
                'txn_status'   => PaymentTransactionModel::STATUS_SUCCEEDED,
                'confirmed_by' => $actorId,
                'confirmed_at' => self::now(),
            ], $actorId, 'Approved payment of invoice ' . $invoice['invoice_no'], ['txn_status' => PaymentTransactionModel::STATUS_PENDING]);
            if (! $done) {
                throw new BillingException('SubscriptionAdmin.paymentNotPending', 409);
            }
            $this->markPaid($invoice, $transaction['created_at'], $actorId);
        });
    }

    /**
     * Turn down a pending payment; the invoice stays payable
     * @param array  $transaction Stored row
     * @param string $reason      Shown to the business
     * @param int    $actorId
     * @return void
     * @throws BillingException
     * @throws Throwable
     */
    public function rejectPayment(array $transaction, string $reason, int $actorId): void
    {
        if ($transaction['txn_status'] !== PaymentTransactionModel::STATUS_PENDING) {
            throw new BillingException('SubscriptionAdmin.paymentNotPending', 409);
        }
        $this->inTransaction(function () use ($transaction, $reason, $actorId) {
            $done = $this->change('payment_transaction', $transaction, [
                'txn_status'     => PaymentTransactionModel::STATUS_FAILED,
                'failure_reason' => $reason,
                'confirmed_by'   => $actorId,
                'confirmed_at'   => self::now(),
            ], $actorId, 'Rejected payment ' . $transaction['public_id'], ['txn_status' => PaymentTransactionModel::STATUS_PENDING]);
            if (! $done) {
                throw new BillingException('SubscriptionAdmin.paymentNotPending', 409);
            }
        });
    }

    /**
     * A status change by the staff: suspend an active subscription (S), reactivate a suspended one whose period has
     * not ended (A), or cancel a live one at once (C)
     * @param array  $subscription Stored row
     * @param string $status
     * @param int    $actorId
     * @return void
     * @throws BillingException
     * @throws Throwable
     */
    public function setStatusByStaff(array $subscription, string $status, int $actorId): void
    {
        $current = $subscription['subscription_status'];
        $now     = self::now();
        switch ($status) {
            case SubscriptionMasterModel::STATUS_SUSPENDED:
                if ($current !== SubscriptionMasterModel::STATUS_ACTIVE) {
                    throw new BillingException('SubscriptionAdmin.cannotSuspend', 409);
                }
                $this->inTransaction(fn () => $this->change('subscription_master', $subscription,
                    ['subscription_status' => $status, 'suspended_at' => $now], $actorId, 'Suspended subscription'));

                return;

            case SubscriptionMasterModel::STATUS_ACTIVE:
                if ($current !== SubscriptionMasterModel::STATUS_SUSPENDED || $subscription['current_period_end'] === null || $subscription['current_period_end'] <= $now) {
                    throw new BillingException('SubscriptionAdmin.cannotReactivate', 409);
                }
                $this->inTransaction(fn () => $this->change('subscription_master', $subscription,
                    ['subscription_status' => $status, 'suspended_at' => null], $actorId, 'Reactivated subscription'));

                return;

            case SubscriptionMasterModel::STATUS_CANCELLED:
                if (! in_array($current, SubscriptionMasterModel::LIVE_STATUSES, true)) {
                    throw new BillingException('SubscriptionAdmin.alreadyEnded', 409);
                }
                $this->endNow($subscription, $actorId, SubscriptionMasterModel::STATUS_CANCELLED);

                return;
        }

        throw new BillingException('SubscriptionAdmin.invalidStatus', 422, ['subscription_status' => lang('SubscriptionAdmin.invalidStatus')]);
    }

    // ---- withholding tax ----

    /**
     * The business asks to withhold tax under one of its country's rules, for staff to approve. Its registered name
     * and branch go on the invoices and are checked against its certificates.
     * @param array  $business  Stored row
     * @param string $ruleCode
     * @param string $legalName
     * @param string $branchCode
     * @param int    $actorId
     * @return void
     * @throws BillingException
     * @throws Throwable
     */
    public function requestWithholding(array $business, string $ruleCode, string $legalName, string $branchCode, int $actorId): void
    {
        if ($business['wht_status'] !== BusinessMasterModel::WHT_NONE) {
            throw new BillingException('Subscriptions.withholdingAlreadyRequested', 409);
        }
        if ($business['tax_id'] === null || $business['tax_id'] === '') {
            throw new BillingException('Subscriptions.withholdingNeedsTaxId', 422, ['tax_id' => lang('Subscriptions.withholdingNeedsTaxId')]);
        }
        if (model(WithholdingTaxRuleModel::class)->inEffect($business['country_code'], $ruleCode, self::now()) === null) {
            throw new BillingException('Subscriptions.withholdingNotAvailable', 422, ['wht_rule_code' => lang('Subscriptions.withholdingNotAvailable')]);
        }
        $this->inTransaction(function () use ($business, $ruleCode, $legalName, $branchCode, $actorId) {
            $done = $this->change('business_master', $business, [
                'wht_status'      => BusinessMasterModel::WHT_REQUESTED,
                'wht_rule_code'   => $ruleCode,
                'legal_name'      => $legalName,
                'tax_branch_code' => $branchCode,
            ], $actorId, 'Asked to withhold tax under ' . $ruleCode, ['wht_status' => BusinessMasterModel::WHT_NONE]);
            if (! $done) {
                throw new BillingException('Subscriptions.withholdingAlreadyRequested', 409);
            }
        });
    }

    /**
     * The business stops withholding, or takes back its request; its pending invoices are worked out again
     * @param array $business Stored row
     * @param int   $actorId
     * @return void
     * @throws BillingException
     * @throws Throwable
     */
    public function withdrawWithholding(array $business, int $actorId): void
    {
        if ($business['wht_status'] === BusinessMasterModel::WHT_NONE) {
            throw new BillingException('Subscriptions.withholdingNotRequested', 409);
        }
        $this->inTransaction(function () use ($business, $actorId) {
            $done = $this->change('business_master', $business, ['wht_status' => BusinessMasterModel::WHT_NONE, 'wht_rule_code' => null],
                $actorId, 'Stopped withholding tax', ['wht_status' => $business['wht_status']]);
            if (! $done) {
                throw new BillingException('Subscriptions.withholdingNotRequested', 409);
            }
            $this->refreshWithholding((int) $business['id'], $actorId);
        });
    }

    /**
     * The staff approve (A) or turn down (N) a request to withhold; the business's pending invoices are worked out again
     * @param array  $business Stored row
     * @param string $decision BusinessMasterModel::WHT_APPROVED or WHT_NONE
     * @param int    $actorId
     * @return void
     * @throws BillingException
     * @throws Throwable
     */
    public function reviewWithholding(array $business, string $decision, int $actorId): void
    {
        if (! in_array($decision, [BusinessMasterModel::WHT_APPROVED, BusinessMasterModel::WHT_NONE], true)) {
            throw new BillingException('SubscriptionAdmin.invalidDecision', 422, ['wht_status' => lang('SubscriptionAdmin.invalidDecision')]);
        }
        if ($business['wht_status'] !== BusinessMasterModel::WHT_REQUESTED) {
            throw new BillingException('SubscriptionAdmin.withholdingNotRequested', 409);
        }
        $this->inTransaction(function () use ($business, $decision, $actorId) {
            $changes = ['wht_status' => $decision, 'wht_reviewed_by' => $actorId, 'wht_reviewed_at' => self::now()];
            if ($decision === BusinessMasterModel::WHT_NONE) {
                $changes['wht_rule_code'] = null;
            }
            $summary = $decision === BusinessMasterModel::WHT_APPROVED ? 'Approved withholding tax' : 'Turned down withholding tax';
            if (! $this->change('business_master', $business, $changes, $actorId, $summary, ['wht_status' => BusinessMasterModel::WHT_REQUESTED])) {
                throw new BillingException('SubscriptionAdmin.withholdingNotRequested', 409);
            }
            $this->refreshWithholding((int) $business['id'], $actorId);
        });
    }

    /**
     * Record the certificate of the tax withheld from a paid invoice, waiting for the staff to check it
     * @param array    $invoice   Stored row
     * @param array    $fields    certificate_no (or null), issued_on (Y-m-d), base_amount, wht_amount
     * @param callable $placeFile fn (int $certificateId): string, puts the file in place inside the transaction and
     *                            gives its name; throws when it cannot
     * @param int      $actorId
     * @return int The withholding_tax_certificate id
     * @throws BillingException
     * @throws Throwable
     */
    public function submitCertificate(array $invoice, array $fields, callable $placeFile, int $actorId): int
    {
        $status = $invoice['wht_cert_status'];
        if ($status === SubscriptionInvoiceModel::CERT_UPLOADED) {
            throw new BillingException('Subscriptions.certificateUnderReview', 409);
        }
        if ($invoice['invoice_status'] !== SubscriptionInvoiceModel::STATUS_PAID
            || ! in_array($status, [SubscriptionInvoiceModel::CERT_WAITING, SubscriptionInvoiceModel::CERT_TOPPED_UP], true)) {
            throw new BillingException('Subscriptions.certificateNotNeeded', 409);
        }

        return $this->inTransaction(function () use ($invoice, $status, $fields, $placeFile, $actorId) {
            $summary = 'Sent the withholding tax certificate of invoice ' . $invoice['invoice_no'];
            if (! $this->change('subscription_invoice', $invoice, ['wht_cert_status' => SubscriptionInvoiceModel::CERT_UPLOADED], $actorId, $summary, ['wht_cert_status' => $status])) {
                throw new BillingException('Subscriptions.certificateUnderReview', 409);
            }
            $id = $this->insert('withholding_tax_certificate', [
                'public_id'      => model(WithholdingTaxCertificateModel::class)->newPublicId(),
                'invoice_id'     => (int) $invoice['id'],
                'certificate_no' => $fields['certificate_no'],
                'issued_on'      => $fields['issued_on'],
                'base_amount'    => $fields['base_amount'],
                'wht_amount'     => $fields['wht_amount'],
                'file_path'      => '',
                'cert_status'    => WithholdingTaxCertificateModel::STATUS_PENDING,
                'uploaded_by'    => $actorId,
                'created_at'     => self::now(),
            ], $actorId, $summary);
            $stored = $this->db->table('withholding_tax_certificate')->where('id', $id)->get()->getRowArray();
            $this->change('withholding_tax_certificate', $stored, ['file_path' => $placeFile($id)], $actorId, $summary);

            return $id;
        });
    }

    /**
     * Accept a pending certificate. When the invoice's tax had been billed as a top-up, the top-up is voided if still
     * unpaid; one already paid is left for the staff to refund.
     * @param array $certificate Stored row
     * @param int   $actorId
     * @return string|null The invoice_no of a paid top-up to refund
     * @throws BillingException
     * @throws Throwable
     */
    public function acceptCertificate(array $certificate, int $actorId): ?string
    {
        if ($certificate['cert_status'] !== WithholdingTaxCertificateModel::STATUS_PENDING) {
            throw new BillingException('SubscriptionAdmin.certificateNotPending', 409);
        }
        $invoice = $this->db->table('subscription_invoice')->where('id', $certificate['invoice_id'])->get()->getRowArray();
        $topUp   = $this->topUpOf((int) $invoice['id']);
        if ($topUp !== null && $topUp['invoice_status'] === SubscriptionInvoiceModel::STATUS_PENDING
            && model(PaymentTransactionModel::class)->hasPending((int) $topUp['id'])) {
            throw new BillingException('SubscriptionAdmin.topUpPaymentUnderReview', 409);
        }

        return $this->inTransaction(function () use ($certificate, $invoice, $topUp, $actorId) {
            $now = self::now();
            $this->reviewCertificate($certificate, WithholdingTaxCertificateModel::STATUS_ACCEPTED, null, $actorId);
            $this->change('subscription_invoice', $invoice, ['wht_cert_status' => SubscriptionInvoiceModel::CERT_ACCEPTED], $actorId,
                'Accepted the withholding tax certificate of invoice ' . $invoice['invoice_no']);
            if ($topUp === null) {
                return null;
            }
            if ($topUp['invoice_status'] === SubscriptionInvoiceModel::STATUS_PENDING) {
                $this->change('subscription_invoice', $topUp, ['invoice_status' => SubscriptionInvoiceModel::STATUS_VOID, 'voided_at' => $now], $actorId,
                    'Voided top-up ' . $topUp['invoice_no'] . ': its certificate came', ['invoice_status' => SubscriptionInvoiceModel::STATUS_PENDING]);

                return null;
            }

            return $topUp['invoice_status'] === SubscriptionInvoiceModel::STATUS_PAID ? $topUp['invoice_no'] : null;
        });
    }

    /**
     * Turn down a pending certificate; the business can send another
     * @param array  $certificate Stored row
     * @param string $reason      Shown to the business
     * @param int    $actorId
     * @return void
     * @throws BillingException
     * @throws Throwable
     */
    public function rejectCertificate(array $certificate, string $reason, int $actorId): void
    {
        if ($certificate['cert_status'] !== WithholdingTaxCertificateModel::STATUS_PENDING) {
            throw new BillingException('SubscriptionAdmin.certificateNotPending', 409);
        }
        $invoice = $this->db->table('subscription_invoice')->where('id', $certificate['invoice_id'])->get()->getRowArray();
        $this->inTransaction(function () use ($certificate, $invoice, $reason, $actorId) {
            $this->reviewCertificate($certificate, WithholdingTaxCertificateModel::STATUS_REJECTED, $reason, $actorId);
            $back = $this->topUpOf((int) $invoice['id']) === null ? SubscriptionInvoiceModel::CERT_WAITING : SubscriptionInvoiceModel::CERT_TOPPED_UP;
            $this->change('subscription_invoice', $invoice, ['wht_cert_status' => $back], $actorId,
                'Rejected the withholding tax certificate of invoice ' . $invoice['invoice_no']);
        });
    }

    // ---- the scheduled run ----

    /**
     * What `php spark billing:run` does, in this order, each subscription in a transaction of its own, logged as the
     * system's: one that fails is logged and left for the next run, and the others go on.
     * 1. A pending subscription whose first invoice is past due_at expires (X) and the invoice is voided.
     * 2. An active subscription to be cancelled at its period end is cancelled (C) once the period has ended.
     * 3. An active subscription gets its renewal invoice Config\Billing::$renewalInvoiceDays before its period ends,
     *    at the price in effect when the next period starts, of next_plan_version_id's series if set, else its own.
     * 4. An active subscription unpaid Config\Billing::$graceDays after its period ended is suspended (S).
     * 5. A subscription suspended for Config\Billing::$suspendedDays expires (X) and its invoices are voided.
     * 6. A paid yearly invoice still waiting for its withholding tax certificate after wht_cert_due_at has the tax
     *    billed as a top-up invoice (its wht_cert_status becomes T). A monthly one needs nothing here: the business
     *    cannot pay its next period invoice until the certificate is sent (see assertPayable()).
     * A subscription with a payment waiting for the staff is left as it is by 1, 4 and 5, as it may have paid in time.
     * @return array<string, int> How many were voided, cancelled, invoiced, suspended, expired, topped up, skipped and failed
     */
    public function runSchedule(): array
    {
        $config = config(BillingConfig::class);
        $now    = self::now();
        $counts = ['voided' => 0, 'cancelled' => 0, 'invoiced' => 0, 'suspended' => 0, 'expired' => 0, 'topped_up' => 0, 'skipped' => 0, 'failed' => 0];
        $each   = function (array $rows, string $count, callable $work) use (&$counts): void {
            foreach ($rows as $row) {
                try {
                    $work($row) === false ? $counts['skipped']++ : $counts[$count]++;
                } catch (BillingException) {
                    $counts['skipped']++;
                } catch (Throwable $e) {
                    $counts['failed']++;
                    log_message('error', 'billing:run subscription {id}: {message}', ['id' => $row['id'], 'message' => $e->getMessage()]);
                }
            }
        };
        $live = fn (string $status) => $this->db->table('subscription_master')->where('subscription_status', $status);

        // 1. first invoices left unpaid
        $stale = $this->db->table('subscription_master s')->distinct()->select('s.*')
            ->join('subscription_invoice i', 'i.subscription_id = s.id', 'inner')
            ->where('s.subscription_status', SubscriptionMasterModel::STATUS_PENDING)
            ->where('i.invoice_type', SubscriptionInvoiceModel::TYPE_PERIOD)
            ->where('i.invoice_status', SubscriptionInvoiceModel::STATUS_PENDING)
            ->where('i.due_at <=', $now)
            ->get()->getResultArray();
        $each($stale, 'voided', fn (array $row) => $this->endNow($row, null, SubscriptionMasterModel::STATUS_EXPIRED));

        // 2. cancellations at the period end
        $ending = $live(SubscriptionMasterModel::STATUS_ACTIVE)->where('cancel_at_period_end', 'Y')->where('current_period_end <=', $now)->get()->getResultArray();
        $each($ending, 'cancelled', fn (array $row) => $this->endNow($row, null, SubscriptionMasterModel::STATUS_CANCELLED));

        // 3. renewal invoices
        $invoices = $this->db->prefixTable('subscription_invoice');
        $due      = $live(SubscriptionMasterModel::STATUS_ACTIVE)->where('cancel_at_period_end', 'N')
            ->where('next_billing_at <=', self::addTime($now, 'P' . $config->renewalInvoiceDays . 'D'))
            // not one already invoiced and waiting for payment
            ->where('NOT EXISTS (SELECT 1 FROM ' . $invoices . ' WHERE ' . $invoices . '.subscription_id = ' . $this->db->prefixTable('subscription_master')
                . ".id AND " . $invoices . ".invoice_type = '" . SubscriptionInvoiceModel::TYPE_PERIOD
                . "' AND " . $invoices . ".invoice_status = '" . SubscriptionInvoiceModel::STATUS_PENDING . "')", null, false)
            ->get()->getResultArray();
        $each($due, 'invoiced', fn (array $row) => $this->invoiceRenewal($row, $config->graceDays));

        // 4. unpaid after the grace days
        $unpaid = $live(SubscriptionMasterModel::STATUS_ACTIVE)->where('cancel_at_period_end', 'N')
            ->where('current_period_end <=', self::subTime($now, 'P' . $config->graceDays . 'D'))
            ->get()->getResultArray();
        $each($unpaid, 'suspended', function (array $row) use ($now) {
            if ($this->hasPaymentUnderReview((int) $row['id'])) {
                return false;
            }
            $this->inTransaction(fn () => $this->change('subscription_master', $row, ['subscription_status' => SubscriptionMasterModel::STATUS_SUSPENDED, 'suspended_at' => $now],
                null, 'Suspended subscription unpaid after the grace days', ['subscription_status' => SubscriptionMasterModel::STATUS_ACTIVE]));
        });

        // 5. suspended for too long
        $expiring = $live(SubscriptionMasterModel::STATUS_SUSPENDED)
            ->where('suspended_at <=', self::subTime($now, 'P' . $config->suspendedDays . 'D'))
            ->get()->getResultArray();
        $each($expiring, 'expired', fn (array $row) => $this->endNow($row, null, SubscriptionMasterModel::STATUS_EXPIRED));

        // 6. yearly invoices whose withholding tax certificate did not come
        $overdue = $this->db->table('subscription_invoice i')->select('i.*')
            ->join('plan_version v', 'v.id = i.plan_version_id', 'inner')
            ->where('i.invoice_type', SubscriptionInvoiceModel::TYPE_PERIOD)
            ->where('i.invoice_status', SubscriptionInvoiceModel::STATUS_PAID)
            ->where('i.wht_cert_status', SubscriptionInvoiceModel::CERT_WAITING)
            ->where('i.wht_cert_due_at <=', $now)
            ->where('v.billing_cycle', PlanVersionModel::YEARLY)
            ->get()->getResultArray();
        $each($overdue, 'topped_up', fn (array $row) => $this->invoiceTopUp($row, self::addTime($now, 'P' . $config->whtTopUpDueDays . 'D')));

        return $counts;
    }

    /**
     * Bill the tax withheld from a paid invoice whose certificate did not come, as a top-up invoice
     * @param array  $invoice Stored row
     * @param string $dueAt
     * @return void
     * @throws Throwable
     */
    private function invoiceTopUp(array $invoice, string $dueAt): void
    {
        $this->inTransaction(function () use ($invoice, $dueAt) {
            $done = $this->change('subscription_invoice', $invoice, ['wht_cert_status' => SubscriptionInvoiceModel::CERT_TOPPED_UP], null,
                'Billed the tax withheld from invoice ' . $invoice['invoice_no'] . ': no certificate', ['wht_cert_status' => SubscriptionInvoiceModel::CERT_WAITING]);
            if (! $done) {
                throw new BillingException('Subscriptions.certificateUnderReview', 409); // a certificate came meanwhile
            }
            $amount = Money::format($invoice['wht_amount']);
            $this->insertInvoice([
                'invoice_type'      => SubscriptionInvoiceModel::TYPE_TOP_UP,
                'parent_invoice_id' => (int) $invoice['id'],
                'subscription_id'   => (int) $invoice['subscription_id'],
                'plan_version_id'   => (int) $invoice['plan_version_id'],
                'currency_code'     => $invoice['currency_code'],
                'list_price'        => $amount,
                'discount_amount'   => '0.00',
                'amount_due'        => $amount,
                'amount_payable'    => $amount,
                'due_at'            => $dueAt,
            ], null);
        });
    }

    /**
     * The renewal invoice of an active subscription, unless it has a pending invoice already
     * @param array $subscription Stored row
     * @param int   $graceDays
     * @return bool False when there was nothing to do
     * @throws Throwable
     */
    private function invoiceRenewal(array $subscription, int $graceDays): bool
    {
        if (model(SubscriptionInvoiceModel::class)->pendingOf((int) $subscription['id']) !== []) {
            return false;
        }
        $series  = $this->db->table('plan_version')->where('id', $subscription['next_plan_version_id'] ?? $subscription['plan_version_id'])->get()->getRowArray();
        $country = $this->db->table('business_master')->select('country_code')->where('id', $subscription['business_id'])->get()->getRow()->country_code;
        $version = model(PlanVersionModel::class)->inEffect((int) $series['plan_id'], $country, $series['billing_cycle'], $subscription['current_period_end']);
        if ($version === null) {
            log_message('warning', 'billing:run subscription {id}: no price in effect to renew at', ['id' => $subscription['id']]);

            return false;
        }
        $this->inTransaction(fn () => $this->createInvoice((int) $subscription['id'], $version, self::addTime($subscription['current_period_end'], 'P' . $graceDays . 'D'), null));

        return true;
    }

    /**
     * Whether a pending invoice of the subscription has a payment waiting for the staff
     * @param int $subscriptionId
     * @return bool
     */
    private function hasPaymentUnderReview(int $subscriptionId): bool
    {
        foreach (model(SubscriptionInvoiceModel::class)->pendingOf($subscriptionId) as $invoice) {
            if (model(PaymentTransactionModel::class)->hasPending((int) $invoice['id'])) {
                return true;
            }
        }

        return false;
    }

    // ---- inside ----

    /**
     * The discount for $code, when the business may apply it to an invoice for $version
     * @param string   $code
     * @param array    $business
     * @param array    $version
     * @param string   $now
     * @param int|null $invoiceId
     * @return array
     * @throws BillingException
     */
    private function usableDiscount(string $code, array $business, array $version, string $now, ?int $invoiceId): array
    {
        $discounts = model(DiscountMasterModel::class);
        $discount  = $discounts->findByCode($code);
        $error     = $discount === null ? 'Subscriptions.discountNotFound' : $discounts->eligibilityError($discount, $business, $version, $now, $invoiceId);
        if ($error !== null) {
            throw new BillingException($error, 422, ['discount_code' => lang($error)]);
        }

        return $discount;
    }

    /**
     * @param array $invoice
     * @return void
     * @throws BillingException When it is not pending, a payment of it is waiting for the staff, or it is a period
     *                          invoice of a business late with a withholding tax certificate or a top-up
     */
    private function assertPayable(array $invoice): void
    {
        if ($invoice['invoice_status'] !== SubscriptionInvoiceModel::STATUS_PENDING) {
            throw new BillingException('Subscriptions.invoiceNotPending', 409);
        }
        if (model(PaymentTransactionModel::class)->hasPending((int) $invoice['id'])) {
            throw new BillingException('Subscriptions.paymentUnderReview', 409);
        }
        if ($invoice['invoice_type'] === SubscriptionInvoiceModel::TYPE_PERIOD && $this->isLateWithWithholding((int) $invoice['subscription_id'])) {
            throw new BillingException('Subscriptions.certificateOverdue', 409);
        }
    }

    /**
     * Whether the business of the subscription has, on any of its subscriptions, a certificate past wht_cert_due_at
     * that it has not sent, or a top-up past its due_at that it has not paid
     * @param int $subscriptionId
     * @return bool
     */
    private function isLateWithWithholding(int $subscriptionId): bool
    {
        $now = self::now();

        return $this->db->table('subscription_invoice i')
            ->join('subscription_master s', 's.id = i.subscription_id', 'inner')
            ->join('subscription_master m', 'm.business_id = s.business_id', 'inner')
            ->where('m.id', $subscriptionId)
            ->groupStart()
                ->groupStart()
                    ->where('i.invoice_type', SubscriptionInvoiceModel::TYPE_PERIOD)
                    ->where('i.wht_cert_status', SubscriptionInvoiceModel::CERT_WAITING)
                    ->where('i.wht_cert_due_at <=', $now)
                ->groupEnd()
                ->orGroupStart()
                    ->where('i.invoice_type', SubscriptionInvoiceModel::TYPE_TOP_UP)
                    ->where('i.invoice_status', SubscriptionInvoiceModel::STATUS_PENDING)
                    ->where('i.due_at <=', $now)
                ->groupEnd()
            ->groupEnd()
            ->countAllResults() > 0;
    }

    /**
     * What the business of the subscription withholds from $amountDue under its rule in effect at $at, and what it
     * pays then: nothing withheld unless it is approved, the rule is in the invoice's currency, and $amountDue is at
     * least the rule's min_base_amount
     * @param int    $subscriptionId
     * @param string $amountDue
     * @param string $currencyCode
     * @param string $at UTC
     * @return array{wht_rule_id: int|null, wht_rate: string, wht_amount: string, amount_payable: string}
     */
    private function withholdingOf(int $subscriptionId, string $amountDue, string $currencyCode, string $at): array
    {
        $base     = Money::toCents(Money::format($amountDue));
        $none     = ['wht_rule_id' => null, 'wht_rate' => '0.00', 'wht_amount' => '0.00', 'amount_payable' => Money::fromCents($base)];
        $business = $this->db->table('business_master b')->select('b.country_code, b.wht_status, b.wht_rule_code')
            ->join('subscription_master s', 's.business_id = b.id', 'inner')
            ->where('s.id', $subscriptionId)
            ->get()->getRowArray();
        if ($business === null || $business['wht_status'] !== BusinessMasterModel::WHT_APPROVED || $business['wht_rule_code'] === null) {
            return $none;
        }
        $rule = model(WithholdingTaxRuleModel::class)->inEffect($business['country_code'], $business['wht_rule_code'], $at);
        if ($rule === null || $rule['currency_code'] !== $currencyCode) {
            return $none;
        }
        if ($rule['min_base_amount'] !== null && $base < Money::toCents(Money::format($rule['min_base_amount']))) {
            return $none;
        }
        $withheld = WithholdingTaxRuleModel::withheldCents($base, (string) $rule['rate']);
        if ($withheld === 0) {
            return $none;
        }

        return [
            'wht_rule_id'    => (int) $rule['id'],
            'wht_rate'       => Money::format($rule['rate']),
            'wht_amount'     => Money::fromCents($withheld),
            'amount_payable' => Money::fromCents($base - $withheld),
        ];
    }

    /**
     * Work the withholding out again on the business's pending period invoices that have no payment waiting for
     * the staff, after its withholding changed
     * @param int $businessId
     * @param int $actorId
     * @return void
     */
    private function refreshWithholding(int $businessId, int $actorId): void
    {
        $invoices = $this->db->table('subscription_invoice i')->select('i.*')
            ->join('subscription_master s', 's.id = i.subscription_id', 'inner')
            ->where('s.business_id', $businessId)
            ->where('i.invoice_type', SubscriptionInvoiceModel::TYPE_PERIOD)
            ->where('i.invoice_status', SubscriptionInvoiceModel::STATUS_PENDING)
            ->get()->getResultArray();
        foreach ($invoices as $invoice) {
            if (model(PaymentTransactionModel::class)->hasPending((int) $invoice['id'])) {
                continue;
            }
            $changes = $this->withholdingOf((int) $invoice['subscription_id'], $invoice['amount_due'], $invoice['currency_code'], self::now());
            if ($changes['wht_amount'] === Money::format($invoice['wht_amount']) && $changes['wht_rule_id'] === ($invoice['wht_rule_id'] === null ? null : (int) $invoice['wht_rule_id'])) {
                continue;
            }
            // an issued invoice never changes: made again with the tax worked out again
            $this->reissue($invoice, [
                'discount_id'     => $invoice['discount_id'] === null ? null : (int) $invoice['discount_id'],
                'discount_amount' => Money::format($invoice['discount_amount']),
                'amount_due'      => Money::format($invoice['amount_due']),
            ] + $changes, $actorId, 'withholding tax worked out again');
        }
    }

    /**
     * The top-up invoice of an invoice's withheld tax, if one was made and not voided
     * @param int $invoiceId
     * @return array|null Stored row
     */
    private function topUpOf(int $invoiceId): ?array
    {
        return $this->db->table('subscription_invoice')
            ->where('parent_invoice_id', $invoiceId)
            ->where('invoice_type', SubscriptionInvoiceModel::TYPE_TOP_UP)
            ->where('invoice_status !=', SubscriptionInvoiceModel::STATUS_VOID)
            ->orderBy('id', 'DESC')
            ->get()->getRowArray();
    }

    /**
     * @param array       $certificate Stored row, pending
     * @param string      $status      WithholdingTaxCertificateModel::STATUS_ACCEPTED or STATUS_REJECTED
     * @param string|null $reason
     * @param int         $actorId
     * @return void
     * @throws BillingException When it was reviewed meanwhile
     */
    private function reviewCertificate(array $certificate, string $status, ?string $reason, int $actorId): void
    {
        $done = $this->change('withholding_tax_certificate', $certificate, [
            'cert_status'      => $status,
            'rejection_reason' => $reason,
            'reviewed_by'      => $actorId,
            'reviewed_at'      => self::now(),
        ], $actorId, ($status === WithholdingTaxCertificateModel::STATUS_ACCEPTED ? 'Accepted' : 'Rejected') . ' certificate ' . $certificate['public_id'],
            ['cert_status' => WithholdingTaxCertificateModel::STATUS_PENDING]);
        if (! $done) {
            throw new BillingException('SubscriptionAdmin.certificateNotPending', 409);
        }
    }

    /**
     * Insert a period invoice at the version's price, less what the business withholds
     * @param int    $subscriptionId
     * @param array  $version
     * @param string $dueAt
     * @param int|null $actorId Null for the scheduled run
     * @return array The stored row
     */
    private function createInvoice(int $subscriptionId, array $version, string $dueAt, ?int $actorId, ?array $discount = null): array
    {
        return $this->insertInvoice([
            'subscription_id' => $subscriptionId,
            'plan_version_id' => (int) $version['id'],
            'currency_code'   => $version['currency_code'],
            'list_price'      => Money::format($version['price']),
            'due_at'          => $dueAt,
        ] + $this->amountsOf($subscriptionId, Money::format($version['price']), $version['currency_code'], $discount), $actorId);
    }

    /**
     * What an invoice of $listPrice comes to: less the discount, if any, whose use is counted here, then less the
     * withholding tax in effect now
     * @param int        $subscriptionId
     * @param string     $listPrice
     * @param string     $currencyCode
     * @param array|null $discount
     * @return array discount_id, discount_amount, amount_due and the withholding columns
     * @throws BillingException When the discount's cap was reached meanwhile
     */
    private function amountsOf(int $subscriptionId, string $listPrice, string $currencyCode, ?array $discount): array
    {
        $price = Money::toCents($listPrice);
        $cut   = 0;
        if ($discount !== null) {
            if (! model(DiscountMasterModel::class)->reserve((int) $discount['id'])) {
                throw new BillingException('Subscriptions.discountUsedUp', 422, ['discount_code' => lang('Subscriptions.discountUsedUp')]);
            }
            $cut = DiscountMasterModel::discountCents($discount, $price);
        }
        $due = Money::fromCents($price - $cut);

        return [
            'discount_id'     => $discount === null ? null : (int) $discount['id'],
            'discount_amount' => Money::fromCents($cut),
            'amount_due'      => $due,
        ] + $this->withholdingOf($subscriptionId, $due, $currencyCode, self::now());
    }

    /**
     * Void a pending invoice and make it again with other amounts, under a new number, as an issued invoice never
     * changes. The new one keeps its type, plan, list price and due time. The voided one's discount use is not given
     * back here: $amounts carries it on, or the caller released it.
     * @param array    $invoice Stored row, pending
     * @param array    $amounts discount_id, discount_amount, amount_due and the withholding columns
     * @param int|null $actorId
     * @param string   $why     For the log
     * @return array The new invoice as stored
     * @throws BillingException When it is no longer pending
     */
    private function reissue(array $invoice, array $amounts, ?int $actorId, string $why): array
    {
        $voided = $this->change('subscription_invoice', $invoice, ['invoice_status' => SubscriptionInvoiceModel::STATUS_VOID, 'voided_at' => self::now()],
            $actorId, 'Voided invoice ' . $invoice['invoice_no'] . ' to make it again: ' . $why, ['invoice_status' => SubscriptionInvoiceModel::STATUS_PENDING]);
        if (! $voided) {
            throw new BillingException('Subscriptions.invoiceNotPending', 409);
        }

        return $this->insertInvoice([
            'invoice_type'      => $invoice['invoice_type'],
            'parent_invoice_id' => $invoice['parent_invoice_id'] === null ? null : (int) $invoice['parent_invoice_id'],
            'subscription_id'   => (int) $invoice['subscription_id'],
            'plan_version_id'   => (int) $invoice['plan_version_id'],
            'currency_code'     => $invoice['currency_code'],
            'list_price'        => Money::format($invoice['list_price']),
            'due_at'            => $invoice['due_at'],
        ] + $amounts, $actorId);
    }

    /**
     * Insert a pending invoice, to be rendered with the invoice template in use now, and freeze its document; its
     * number comes from its id: INV-YYYYMM-000123
     * @param array    $fields
     * @param int|null $actorId Null for the scheduled run
     * @return array The stored row
     */
    private function insertInvoice(array $fields, ?int $actorId): array
    {
        $publicId = model(SubscriptionInvoiceModel::class)->newPublicId();
        $row      = ['public_id' => $publicId, 'invoice_no' => 'NEW-' . $publicId, 'invoice_version_id' => DocumentRenderer::invoiceVersion(),
            'invoice_status' => SubscriptionInvoiceModel::STATUS_PENDING] + $fields;
        $this->db->table('subscription_invoice')->insert($row);
        $id                = (int) $this->db->insertID();
        $row['invoice_no'] = 'INV-' . gmdate('Ym', strtotime(self::now() . ' UTC')) . '-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
        $this->db->table('subscription_invoice')->where('id', $id)->update(['invoice_no' => $row['invoice_no']]);
        $this->log->inserted($actorId, 'Created invoice ' . $row['invoice_no'], 'subscription_invoice', $id, $row);
        $stored = $this->db->table('subscription_invoice')->where('id', $id)->get()->getRowArray();
        (new DocumentRenderer())->freeze($stored, 'invoice', self::now());

        return $stored;
    }

    /**
     * Mark the invoice paid as of $paidAt and move its subscription on to the period it pays for. A first invoice, or
     * one paid after the grace days, starts the period when it was paid (and the anchor day becomes that day); one
     * paid by the end of the grace days starts it when the last one ended. One with tax withheld waits for its
     * certificate from then on, when its rule asks for one. A top-up is only marked paid: it pays for no period.
     * Either gets its receipt (see receiptOf()), whose document is frozen as it is paid.
     * @param array    $invoice Stored row, pending
     * @param string   $paidAt  UTC
     * @param int|null $actorId
     * @return void
     * @throws BillingException
     */
    private function markPaid(array $invoice, string $paidAt, ?int $actorId): void
    {
        if ($invoice['invoice_type'] === SubscriptionInvoiceModel::TYPE_TOP_UP) {
            $paid = $this->change('subscription_invoice', $invoice, ['invoice_status' => SubscriptionInvoiceModel::STATUS_PAID, 'paid_at' => $paidAt]
                + self::receiptOf($invoice, $paidAt), $actorId, 'Paid top-up ' . $invoice['invoice_no'], ['invoice_status' => SubscriptionInvoiceModel::STATUS_PENDING]);
            if (! $paid) {
                throw new BillingException('SubscriptionAdmin.invoiceNotPending', 409);
            }
            $this->freezeReceipt((int) $invoice['id']);

            return;
        }
        $subscription = $this->db->table('subscription_master')->where('id', $invoice['subscription_id'])->get()->getRowArray();
        $country      = $this->db->table('business_master')->select('country_code')->where('id', $subscription['business_id'])->get()->getRow()->country_code;
        $version      = $this->db->table('plan_version')->where('id', $invoice['plan_version_id'])->get()->getRowArray();
        $timezone     = self::timezoneOf($country);
        $status       = $subscription['subscription_status'];
        if (! in_array($status, SubscriptionMasterModel::LIVE_STATUSES, true)) {
            throw new BillingException('SubscriptionAdmin.alreadyEnded', 409);
        }

        $start  = $paidAt;
        $anchor = self::localDay($paidAt, $timezone);
        if ($status !== SubscriptionMasterModel::STATUS_PENDING && $subscription['current_period_end'] !== null) {
            $graceEnd = self::addTime($subscription['current_period_end'], 'P' . config(BillingConfig::class)->graceDays . 'D');
            if ($paidAt < $graceEnd) {
                $start  = $subscription['current_period_end'];
                $anchor = (int) ($subscription['billing_anchor_day'] ?? self::localDay($start, $timezone));
            }
        }
        $end = self::periodEnd($start, $version['billing_cycle'], $anchor, $timezone);

        $certificate = [];
        $rule        = Money::isZero($invoice['wht_amount']) || $invoice['wht_rule_id'] === null
            ? null : $this->db->table('withholding_tax_rule')->where('id', $invoice['wht_rule_id'])->get()->getRowArray();
        if ($rule !== null && $rule['certificate_required'] === 'Y') {
            $certificate = [
                'wht_cert_status' => SubscriptionInvoiceModel::CERT_WAITING,
                'wht_cert_due_at' => self::addTime($paidAt, 'P' . (int) $rule['certificate_due_days'] . 'D'),
            ];
        }
        $paid = $this->change('subscription_invoice', $invoice, [
            'invoice_status' => SubscriptionInvoiceModel::STATUS_PAID,
            'paid_at'        => $paidAt,
            'period_start'   => $start,
            'period_end'     => $end,
        ] + self::receiptOf($invoice, $paidAt) + $certificate, $actorId, 'Paid invoice ' . $invoice['invoice_no'], ['invoice_status' => SubscriptionInvoiceModel::STATUS_PENDING]);
        if (! $paid) {
            throw new BillingException('SubscriptionAdmin.invoiceNotPending', 409);
        }
        $this->freezeReceipt((int) $invoice['id']);

        $changes = [
            'subscription_status'  => SubscriptionMasterModel::STATUS_ACTIVE,
            'plan_version_id'      => (int) $version['id'],
            'billing_anchor_day'   => $anchor,
            'current_period_start' => $start,
            'current_period_end'   => $end,
            'next_billing_at'      => $end,
            'suspended_at'         => null,
            'activated_at'         => $subscription['activated_at'] ?? $paidAt,
        ];
        if ((int) ($subscription['next_plan_version_id'] ?? 0) === (int) $version['id']) {
            $changes['next_plan_version_id'] = null;
        }
        $this->change('subscription_master', $subscription, $changes, $actorId, 'Started period of invoice ' . $invoice['invoice_no']);
    }

    /**
     * Freeze the receipt of an invoice just paid, with its payment
     * @param int $invoiceId
     * @return void
     */
    private function freezeReceipt(int $invoiceId): void
    {
        (new DocumentRenderer())->freeze($this->db->table('subscription_invoice')->where('id', $invoiceId)->get()->getRowArray(), 'receipt');
    }

    /**
     * The receipt of an invoice paid at $paidAt, rendered with the receipt template in use now: RCT-YYYYMM-000123,
     * the month it was paid and the invoice's id
     * @param array  $invoice Stored row
     * @param string $paidAt  UTC
     * @return array{receipt_no: string, receipt_version_id: string}
     */
    private static function receiptOf(array $invoice, string $paidAt): array
    {
        return [
            'receipt_no'         => 'RCT-' . gmdate('Ym', strtotime($paidAt . ' UTC')) . '-' . str_pad((string) $invoice['id'], 6, '0', STR_PAD_LEFT),
            'receipt_version_id' => DocumentRenderer::receiptVersion(),
        ];
    }

    /**
     * End a live subscription now, as cancelled (C) or expired (X), and void its pending invoices, giving their
     * discounts' uses back
     * @param array    $subscription Stored row
     * @param int|null $actorId      Null for the scheduled run
     * @param string   $status       STATUS_CANCELLED or STATUS_EXPIRED
     * @param array    $extra        More columns to set
     * @return void
     * @throws BillingException When a payment is waiting for the staff
     * @throws Throwable
     */
    private function endNow(array $subscription, ?int $actorId, string $status, array $extra = []): void
    {
        $invoices = model(SubscriptionInvoiceModel::class)->pendingOf((int) $subscription['id']);
        foreach ($invoices as $invoice) {
            if (model(PaymentTransactionModel::class)->hasPending((int) $invoice['id'])) {
                throw new BillingException('Subscriptions.paymentUnderReview', 409);
            }
        }
        $now     = self::now();
        $changes = $extra + ['subscription_status' => $status, 'ended_at' => $now];
        if ($status === SubscriptionMasterModel::STATUS_CANCELLED) {
            $changes['cancelled_at'] = $now;
        }
        $summary = $status === SubscriptionMasterModel::STATUS_CANCELLED ? 'Cancelled subscription' : 'Expired subscription';
        $this->inTransaction(function () use ($subscription, $invoices, $actorId, $changes, $summary, $now) {
            foreach ($invoices as $invoice) {
                $this->change('subscription_invoice', $invoice, ['invoice_status' => SubscriptionInvoiceModel::STATUS_VOID, 'voided_at' => $now],
                    $actorId, 'Voided invoice ' . $invoice['invoice_no'], ['invoice_status' => SubscriptionInvoiceModel::STATUS_PENDING]);
                if ($invoice['discount_id'] !== null) {
                    model(DiscountMasterModel::class)->release((int) $invoice['discount_id']);
                }
            }
            if (! $this->change('subscription_master', $subscription, $changes, $actorId, $summary, ['subscription_status' => $subscription['subscription_status']])) {
                throw new BillingException('SubscriptionAdmin.alreadyEnded', 409);
            }
        });
    }

    /**
     * @param string $at       UTC
     * @param string $interval ISO 8601 duration, such as P3D or PT24H
     * @return string UTC
     */
    private static function addTime(string $at, string $interval): string
    {
        return (new DateTimeImmutable($at, new DateTimeZone('UTC')))->add(new DateInterval($interval))->format(self::DATETIME);
    }

    /**
     * @param string $at       UTC
     * @param string $interval ISO 8601 duration
     * @return string UTC
     */
    private static function subTime(string $at, string $interval): string
    {
        return (new DateTimeImmutable($at, new DateTimeZone('UTC')))->sub(new DateInterval($interval))->format(self::DATETIME);
    }

    /**
     * Insert a row and its log_activity row
     * @param string   $table
     * @param array    $row
     * @param int|null $actorId
     * @param string   $summary
     * @return int
     */
    private function insert(string $table, array $row, ?int $actorId, string $summary): int
    {
        $this->db->table($table)->insert($row);
        $id = (int) $this->db->insertID();
        $this->log->inserted($actorId, $summary, $table, $id, $row);

        return $id;
    }

    /**
     * Update a row, only while it still has the $expected values, and log what changed
     * @param string   $table
     * @param array    $stored   The row as read
     * @param array    $changes
     * @param int|null $actorId
     * @param string   $summary
     * @param array    $expected Column => value the row must still have, so two requests cannot both make the change
     * @return bool False when the row no longer had them; nothing is written then
     */
    private function change(string $table, array $stored, array $changes, ?int $actorId, string $summary, array $expected = []): bool
    {
        $builder = $this->db->table($table)->where('id', $stored['id']);
        foreach ($expected as $column => $value) {
            $builder->where($column, $value);
        }
        $builder->update($changes);
        if ($this->db->affectedRows() === 0) {
            return false;
        }
        $this->log->updated($actorId, $summary, $table, (int) $stored['id'], $stored, $changes);

        return true;
    }
}
