<?php

namespace App\Libraries;

use App\Models\DiscountMasterModel;
use App\Models\PaymentMethodModel;
use App\Models\PaymentTransactionModel;
use App\Models\PlanMasterModel;
use App\Models\PlanVersionModel;
use App\Models\SubscriptionInvoiceModel;
use App\Models\SubscriptionMasterModel;
use App\Models\TransactionTrait;
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
                $invoice   = $this->createInvoice($subscriptionId, $version, self::addTime($now, 'PT' . $holdHours . 'H'), $actorId);
                if ($discount !== null) {
                    $invoice = $this->setDiscount($invoice, $discount, $actorId);
                }
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
     * Apply a discount code to a pending invoice, in place of the one it had; one with nothing left to pay is paid
     * @param array  $invoice  Stored row
     * @param array  $business With id and country_code
     * @param string $code
     * @param int    $actorId
     * @return void
     * @throws BillingException
     * @throws Throwable
     */
    public function applyDiscount(array $invoice, array $business, string $code, int $actorId): void
    {
        $this->assertPayable($invoice);
        $version  = model(PlanVersionModel::class)->find((int) $invoice['plan_version_id']);
        $discount = $this->usableDiscount($code, $business, $version, self::now(), (int) $invoice['id']);
        if ((int) $discount['id'] === (int) ($invoice['discount_id'] ?? 0)) {
            return;
        }

        $this->inTransaction(function () use ($invoice, $discount, $actorId) {
            $invoice = $this->setDiscount($invoice, $discount, $actorId);
            if (Money::isZero($invoice['amount_due'])) {
                $this->markPaid($invoice, self::now(), $actorId);
            }
        });
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
                'amount'            => Money::format($invoice['amount_due']),
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
     * A subscription with a payment waiting for the staff is left as it is by 1, 4 and 5, as it may have paid in time.
     * @return array<string, int> How many were voided, cancelled, invoiced, suspended, expired, skipped and failed
     */
    public function runSchedule(): array
    {
        $config = config(BillingConfig::class);
        $now    = self::now();
        $counts = ['voided' => 0, 'cancelled' => 0, 'invoiced' => 0, 'suspended' => 0, 'expired' => 0, 'skipped' => 0, 'failed' => 0];
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
                . ".id AND " . $invoices . ".invoice_status = '" . SubscriptionInvoiceModel::STATUS_PENDING . "')", null, false)
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

        return $counts;
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
     * @throws BillingException When it is not pending, or a payment of it is waiting for the staff
     */
    private function assertPayable(array $invoice): void
    {
        if ($invoice['invoice_status'] !== SubscriptionInvoiceModel::STATUS_PENDING) {
            throw new BillingException('Subscriptions.invoiceNotPending', 409);
        }
        if (model(PaymentTransactionModel::class)->hasPending((int) $invoice['id'])) {
            throw new BillingException('Subscriptions.paymentUnderReview', 409);
        }
    }

    /**
     * Insert an invoice at the version's price; its number comes from its id: INV-YYYYMM-000123
     * @param int    $subscriptionId
     * @param array  $version
     * @param string $dueAt
     * @param int|null $actorId Null for the scheduled run
     * @return array The stored row
     */
    private function createInvoice(int $subscriptionId, array $version, string $dueAt, ?int $actorId): array
    {
        $publicId = model(SubscriptionInvoiceModel::class)->newPublicId();
        $row      = [
            'public_id'       => $publicId,
            'invoice_no'      => 'NEW-' . $publicId,
            'subscription_id' => $subscriptionId,
            'plan_version_id' => (int) $version['id'],
            'currency_code'   => $version['currency_code'],
            'list_price'      => Money::format($version['price']),
            'discount_amount' => '0.00',
            'amount_due'      => Money::format($version['price']),
            'invoice_status'  => SubscriptionInvoiceModel::STATUS_PENDING,
            'due_at'          => $dueAt,
        ];
        $this->db->table('subscription_invoice')->insert($row);
        $id                = (int) $this->db->insertID();
        $row['invoice_no'] = 'INV-' . gmdate('Ym', strtotime(self::now() . ' UTC')) . '-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
        $this->db->table('subscription_invoice')->where('id', $id)->update(['invoice_no' => $row['invoice_no']]);
        $this->log->inserted($actorId, 'Created invoice ' . $row['invoice_no'], 'subscription_invoice', $id, $row);

        return $this->db->table('subscription_invoice')->where('id', $id)->get()->getRowArray();
    }

    /**
     * Put the discount on the invoice, giving back the use of the one it had and counting one of the new one
     * @param array $invoice Stored row
     * @param array $discount
     * @param int   $actorId
     * @return array The invoice as stored now
     * @throws BillingException When the discount's cap was reached meanwhile
     */
    private function setDiscount(array $invoice, array $discount, int $actorId): array
    {
        $discounts = model(DiscountMasterModel::class);
        if ($invoice['discount_id'] !== null) {
            $discounts->release((int) $invoice['discount_id']);
        }
        if (! $discounts->reserve((int) $discount['id'])) {
            throw new BillingException('Subscriptions.discountUsedUp', 422, ['discount_code' => lang('Subscriptions.discountUsedUp')]);
        }
        $price = Money::toCents(Money::format($invoice['list_price']));
        $cut   = DiscountMasterModel::discountCents($discount, $price);
        $done  = $this->change('subscription_invoice', $invoice, [
            'discount_id'     => (int) $discount['id'],
            'discount_amount' => Money::fromCents($cut),
            'amount_due'      => Money::fromCents($price - $cut),
        ], $actorId, 'Applied discount ' . $discount['discount_code'] . ' to invoice ' . $invoice['invoice_no'], ['invoice_status' => SubscriptionInvoiceModel::STATUS_PENDING]);
        if (! $done) {
            throw new BillingException('Subscriptions.invoiceNotPending', 409);
        }

        return $this->db->table('subscription_invoice')->where('id', $invoice['id'])->get()->getRowArray();
    }

    /**
     * Mark the invoice paid as of $paidAt and move its subscription on to the period it pays for. A first invoice, or
     * one paid after the grace days, starts the period when it was paid (and the anchor day becomes that day); one
     * paid by the end of the grace days starts it when the last one ended.
     * @param array    $invoice Stored row, pending
     * @param string   $paidAt  UTC
     * @param int|null $actorId
     * @return void
     * @throws BillingException
     */
    private function markPaid(array $invoice, string $paidAt, ?int $actorId): void
    {
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

        $paid = $this->change('subscription_invoice', $invoice, [
            'invoice_status' => SubscriptionInvoiceModel::STATUS_PAID,
            'paid_at'        => $paidAt,
            'period_start'   => $start,
            'period_end'     => $end,
        ], $actorId, 'Paid invoice ' . $invoice['invoice_no'], ['invoice_status' => SubscriptionInvoiceModel::STATUS_PENDING]);
        if (! $paid) {
            throw new BillingException('SubscriptionAdmin.invoiceNotPending', 409);
        }

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
