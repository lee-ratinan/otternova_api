<?php

namespace App\Controllers;

use App\Libraries\ApiResponse;
use App\Libraries\BillingException;
use App\Libraries\SubscriptionBilling;
use App\Models\PaymentTransactionModel;
use App\Models\SubscriptionInvoiceModel;
use App\Models\SubscriptionMasterModel;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Billing as BillingConfig;
use Throwable;

/**
 * Every business's subscriptions and payments, for the Admin app, behind the jwt:S and department:EXE,FIN filters:
 * the staff look them up, approve or reject the payments waiting for them, and suspend, reactivate or cancel a
 * subscription. Everything goes out and comes in by its public_id; the ids stay inside. Every time is UTC.
 */
class SubscriptionAdmin extends BaseController
{
    use BillingRequestTrait;

    /** A slip_path as Subscriptions::pay() names it; anything else is never served */
    private const SLIP_PATTERN = '/^\d+-[0-9a-f]{16}\.(jpg|png|webp|pdf)$/';

    /** The content type a slip is served with, by extension */
    private const SLIP_TYPES = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'pdf' => 'application/pdf'];

    /**
     * POST /staff/subscriptions/list { start, length, search, filters: {subscription_status, plan_code} }
     * One page of subscriptions, newest first; search matches the business's name and slug
     * @return ResponseInterface
     */
    public function list(): ResponseInterface
    {
        $input = $this->jsonInput();
        if ($input === null) {
            return ApiResponse::error(lang('SubscriptionAdmin.invalidRequest'), 422);
        }
        [$start, $length] = $this->page($input);
        $filters          = $this->filters($input, ['subscription_status' => true, 'plan_code' => false]);
        try {
            $data = model(SubscriptionMasterModel::class)->listSubscriptions($start, $length, $this->text($input, 'search'), $filters);
        } catch (Throwable $e) {
            return $this->failed('v1/staff/subscriptions/list', $e, 'SubscriptionAdmin.loadFailed');
        }

        return ApiResponse::success($data);
    }

    /**
     * POST /staff/subscriptions/subscription { public_id }
     * One subscription with its invoices, newest first, each with its payments and who confirmed them
     * @return ResponseInterface
     */
    public function subscription(): ResponseInterface
    {
        $input = $this->jsonInput();
        if ($input === null) {
            return ApiResponse::error(lang('SubscriptionAdmin.invalidRequest'), 422);
        }
        $subscriptions = model(SubscriptionMasterModel::class);
        try {
            $id = $subscriptions->idOfPublicId($input['public_id'] ?? null);
            if ($id === null) {
                return ApiResponse::error(lang('SubscriptionAdmin.subscriptionNotFound'), 404);
            }
            $subscription = $subscriptions->findPayload($id);
            $invoices     = model(SubscriptionInvoiceModel::class)->payloadsOfSubscription($id, true);
        } catch (Throwable $e) {
            return $this->failed('v1/staff/subscriptions/subscription', $e, 'SubscriptionAdmin.loadFailed');
        }

        return ApiResponse::success(['subscription' => $subscription, 'invoices' => $invoices]);
    }

    /**
     * POST /staff/subscriptions/subscription/status { public_id, subscription_status }
     * S suspends an active subscription; A reactivates a suspended one whose period has not ended; C cancels a live
     * one now, voiding its pending invoices. A payment waiting for review has to be approved or rejected first.
     * @return ResponseInterface
     */
    public function setStatus(): ResponseInterface
    {
        $endpoint = 'v1/staff/subscriptions/subscription/status';
        $input    = $this->jsonInput();
        if ($input === null) {
            return ApiResponse::error(lang('SubscriptionAdmin.invalidRequest'), 422);
        }
        $subscriptions = model(SubscriptionMasterModel::class);
        try {
            $stored = $subscriptions->findByPublicId($input['public_id'] ?? null);
            if ($stored === null) {
                return ApiResponse::error(lang('SubscriptionAdmin.subscriptionNotFound'), 404);
            }
            (new SubscriptionBilling())->setStatusByStaff($stored, strtoupper($this->text($input, 'subscription_status')), $this->actorId());
            $subscription = $subscriptions->findPayload((int) $stored['id']);
        } catch (BillingException $e) {
            return $this->refused($e);
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'SubscriptionAdmin.saveFailed');
        }

        return ApiResponse::success(['subscription' => $subscription], 200, lang('SubscriptionAdmin.statusChanged'));
    }

    /**
     * POST /staff/subscriptions/payments { start, length, filters: {txn_status, method_code} }
     * One page of payments, oldest first, with their invoice and business; txn_status P lists the ones waiting
     * @return ResponseInterface
     */
    public function payments(): ResponseInterface
    {
        $input = $this->jsonInput();
        if ($input === null) {
            return ApiResponse::error(lang('SubscriptionAdmin.invalidRequest'), 422);
        }
        [$start, $length] = $this->page($input);
        $filters          = $this->filters($input, ['txn_status' => true, 'method_code' => false]);
        try {
            $data = model(PaymentTransactionModel::class)->listPayments($start, $length, $filters);
        } catch (Throwable $e) {
            return $this->failed('v1/staff/subscriptions/payments', $e, 'SubscriptionAdmin.loadFailed');
        }

        return ApiResponse::success($data);
    }

    /**
     * POST /staff/subscriptions/payment/approve { public_id }
     * Confirms the payment: its invoice is paid as of when the payment was sent, and the subscription moves on to
     * the period it pays for
     * @return ResponseInterface
     */
    public function approvePayment(): ResponseInterface
    {
        return $this->reviewPayment('v1/staff/subscriptions/payment/approve', null);
    }

    /**
     * POST /staff/subscriptions/payment/reject { public_id, failure_reason }
     * Turns the payment down with the reason the business is shown; the invoice can be paid again
     * @return ResponseInterface
     */
    public function rejectPayment(): ResponseInterface
    {
        return $this->reviewPayment('v1/staff/subscriptions/payment/reject', 'failure_reason');
    }

    /**
     * GET /staff/subscriptions/slip/{public_id}
     * The payment's slip, never cached: the app fetches it with its Bearer token
     * @param string $publicId
     * @return ResponseInterface
     */
    public function slip(string $publicId): ResponseInterface
    {
        try {
            $stored = model(PaymentTransactionModel::class)->findByPublicId($publicId);
        } catch (Throwable $e) {
            return $this->failed('v1/staff/subscriptions/slip', $e, 'SubscriptionAdmin.loadFailed');
        }
        $name = $stored['slip_path'] ?? null;
        $path = $name !== null && preg_match(self::SLIP_PATTERN, $name, $parts)
            ? rtrim(config(BillingConfig::class)->slipUploadPath, '/') . '/' . $name
            : null;
        if ($path === null || ! is_file($path)) {
            return ApiResponse::error(lang('SubscriptionAdmin.noSlip'), 404);
        }

        // removed first: the response starts with a no-store Cache-Control that setHeader() would add to
        return $this->response
            ->removeHeader('Cache-Control')
            ->setHeader('Cache-Control', 'private, no-store')
            ->setHeader('Content-Disposition', 'inline; filename="slip-' . $publicId . '.' . $parts[1] . '"')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setContentType(self::SLIP_TYPES[$parts[1]], '')
            ->setBody((string) file_get_contents($path));
    }

    /**
     * approvePayment() and rejectPayment()
     * @param string      $endpoint
     * @param string|null $reasonField The body's field with the reason of a rejection; null to approve
     * @return ResponseInterface
     */
    private function reviewPayment(string $endpoint, ?string $reasonField): ResponseInterface
    {
        $input = $this->jsonInput();
        if ($input === null) {
            return ApiResponse::error(lang('SubscriptionAdmin.invalidRequest'), 422);
        }
        $reason = $reasonField === null ? '' : $this->text($input, $reasonField);
        if ($reasonField !== null && ($reason === '' || mb_strlen($reason) > 255)) {
            return ApiResponse::error(lang('SubscriptionAdmin.invalidFields'), 422, [$reasonField => lang('SubscriptionAdmin.reasonRequired')]);
        }
        $payments = model(PaymentTransactionModel::class);
        try {
            $stored = $payments->findByPublicId($input['public_id'] ?? null);
            if ($stored === null) {
                return ApiResponse::error(lang('SubscriptionAdmin.paymentNotFound'), 404);
            }
            $billing = new SubscriptionBilling();
            $reasonField === null ? $billing->approvePayment($stored, $this->actorId()) : $billing->rejectPayment($stored, $reason, $this->actorId());
            $status = $payments->find((int) $stored['id'])['txn_status'];
        } catch (BillingException $e) {
            return $this->refused($e);
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'SubscriptionAdmin.saveFailed');
        }

        return ApiResponse::success(['public_id' => $stored['public_id'], 'txn_status' => $status], 200,
            lang($reasonField === null ? 'SubscriptionAdmin.paymentApproved' : 'SubscriptionAdmin.paymentRejected'));
    }

    /**
     * The filters of a list request that are given, as strings
     * @param array               $input
     * @param array<string, bool> $names filter => whether it is upper case
     * @return array<string, string>
     */
    private function filters(array $input, array $names): array
    {
        $given = is_array($input['filters'] ?? null) ? $input['filters'] : [];
        $valid = [];
        foreach ($names as $name => $upper) {
            $value = $this->text($given, $name);
            if ($value !== '') {
                $valid[$name] = $upper ? strtoupper($value) : $value;
            }
        }

        return $valid;
    }
}
