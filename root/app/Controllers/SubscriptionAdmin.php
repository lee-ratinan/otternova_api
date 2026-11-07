<?php

namespace App\Controllers;

use App\Libraries\ApiResponse;
use App\Libraries\BillingException;
use App\Libraries\SubscriptionBilling;
use App\Models\BusinessMasterModel;
use App\Models\PaymentTransactionModel;
use App\Models\SubscriptionInvoiceModel;
use App\Models\SubscriptionMasterModel;
use App\Models\WithholdingTaxCertificateModel;
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

    /**
     * A slip_path as Subscriptions::pay() names it, or a certificate's file_path as Subscriptions::certificate() does;
     * anything else is never served
     */
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
     * POST /staff/subscriptions/invoices { start, length, search, filters: {invoice_status, plan_code, awaiting_review} }
     * One page of every business's invoices, each with its business and how many payments wait for review
     * (pending_payments). Unpaid ones (invoice_status P) come oldest due first, any other list newest first;
     * search matches the invoice number and the business's name and slug. awaiting_review Y keeps only the invoices
     * with a payment to review.
     * @return ResponseInterface
     */
    public function invoices(): ResponseInterface
    {
        $input = $this->jsonInput();
        if ($input === null) {
            return ApiResponse::error(lang('SubscriptionAdmin.invalidRequest'), 422);
        }
        [$start, $length] = $this->page($input);
        $filters          = $this->filters($input, ['invoice_status' => true, 'plan_code' => false, 'awaiting_review' => true]);
        try {
            $data = model(SubscriptionInvoiceModel::class)->listForStaff($start, $length, $this->text($input, 'search'), $filters);
        } catch (Throwable $e) {
            return $this->failed('v1/staff/subscriptions/invoices', $e, 'SubscriptionAdmin.loadFailed');
        }

        return ApiResponse::success($data);
    }

    /**
     * POST /staff/subscriptions/invoice { public_id }
     * One invoice with its business, its subscription and its payments, oldest first, with who confirmed them
     * @return ResponseInterface
     */
    public function invoice(): ResponseInterface
    {
        $input = $this->jsonInput();
        if ($input === null) {
            return ApiResponse::error(lang('SubscriptionAdmin.invalidRequest'), 422);
        }
        try {
            $invoice = model(SubscriptionInvoiceModel::class)->findForStaff($input['public_id'] ?? null);
        } catch (Throwable $e) {
            return $this->failed('v1/staff/subscriptions/invoice', $e, 'SubscriptionAdmin.loadFailed');
        }
        if ($invoice === null) {
            return ApiResponse::error(lang('SubscriptionAdmin.invoiceNotFound'), 404);
        }

        return ApiResponse::success(['invoice' => $invoice]);
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
     * POST /staff/subscriptions/withholding { start, length, filters: {wht_status} }
     * One page of businesses by their withholding, R (asking for it, the default), A or N, with tax_id to check
     * @return ResponseInterface
     */
    public function withholding(): ResponseInterface
    {
        $input = $this->jsonInput();
        if ($input === null) {
            return ApiResponse::error(lang('SubscriptionAdmin.invalidRequest'), 422);
        }
        [$start, $length] = $this->page($input);
        $status           = $this->filters($input, ['wht_status' => true])['wht_status'] ?? BusinessMasterModel::WHT_REQUESTED;
        try {
            $data = model(BusinessMasterModel::class)->listWithholding($start, $length, $status);
        } catch (Throwable $e) {
            return $this->failed('v1/staff/subscriptions/withholding', $e, 'SubscriptionAdmin.loadFailed');
        }

        return ApiResponse::success($data);
    }

    /**
     * POST /staff/subscriptions/withholding/review { business_slug, wht_status }
     * A approves the business's request to withhold tax, N turns it down; its pending invoices are worked out again
     * @return ResponseInterface
     */
    public function reviewWithholding(): ResponseInterface
    {
        $endpoint = 'v1/staff/subscriptions/withholding/review';
        $input    = $this->jsonInput();
        if ($input === null) {
            return ApiResponse::error(lang('SubscriptionAdmin.invalidRequest'), 422);
        }
        $businesses = model(BusinessMasterModel::class);
        try {
            $business = $businesses->where('business_slug', $this->text($input, 'business_slug'))->first();
            if ($business === null) {
                return ApiResponse::error(lang('SubscriptionAdmin.businessNotFound'), 404);
            }
            (new SubscriptionBilling())->reviewWithholding($business, strtoupper($this->text($input, 'wht_status')), $this->actorId());
            $status = $businesses->find((int) $business['id'])['wht_status'];
        } catch (BillingException $e) {
            return $this->refused($e);
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'SubscriptionAdmin.saveFailed');
        }

        return ApiResponse::success(['business_slug' => $business['business_slug'], 'wht_status' => $status], 200,
            lang($status === BusinessMasterModel::WHT_APPROVED ? 'SubscriptionAdmin.withholdingApproved' : 'SubscriptionAdmin.withholdingTurnedDown'));
    }

    /**
     * POST /staff/subscriptions/certificates { start, length, filters: {cert_status} }
     * One page of withholding tax certificates, oldest first, with their invoice and business; cert_status P lists
     * the ones waiting
     * @return ResponseInterface
     */
    public function certificates(): ResponseInterface
    {
        $input = $this->jsonInput();
        if ($input === null) {
            return ApiResponse::error(lang('SubscriptionAdmin.invalidRequest'), 422);
        }
        [$start, $length] = $this->page($input);
        try {
            $data = model(WithholdingTaxCertificateModel::class)->listCertificates($start, $length, $this->filters($input, ['cert_status' => true]));
        } catch (Throwable $e) {
            return $this->failed('v1/staff/subscriptions/certificates', $e, 'SubscriptionAdmin.loadFailed');
        }

        return ApiResponse::success($data);
    }

    /**
     * POST /staff/subscriptions/certificate/accept { public_id }
     * Accepts the certificate. A top-up made for its invoice is voided if unpaid; refund_invoice_no names one already
     * paid, for the staff to refund.
     * @return ResponseInterface
     */
    public function acceptCertificate(): ResponseInterface
    {
        return $this->reviewCertificate('v1/staff/subscriptions/certificate/accept', null);
    }

    /**
     * POST /staff/subscriptions/certificate/reject { public_id, rejection_reason }
     * Turns the certificate down with the reason the business is shown; it can send another
     * @return ResponseInterface
     */
    public function rejectCertificate(): ResponseInterface
    {
        return $this->reviewCertificate('v1/staff/subscriptions/certificate/reject', 'rejection_reason');
    }

    /**
     * GET /staff/subscriptions/certificate-file/{public_id}
     * The certificate's file, never cached: the app fetches it with its Bearer token
     * @param string $publicId
     * @return ResponseInterface
     */
    public function certificateFile(string $publicId): ResponseInterface
    {
        try {
            $stored = model(WithholdingTaxCertificateModel::class)->findByPublicId($publicId);
        } catch (Throwable $e) {
            return $this->failed('v1/staff/subscriptions/certificate-file', $e, 'SubscriptionAdmin.loadFailed');
        }
        $name = $stored['file_path'] ?? null;
        $path = $name !== null && preg_match(self::SLIP_PATTERN, $name, $parts)
            ? rtrim(config(BillingConfig::class)->whtCertUploadPath, '/') . '/' . $name
            : null;
        if ($path === null || ! is_file($path)) {
            return ApiResponse::error(lang('SubscriptionAdmin.noCertificateFile'), 404);
        }

        return $this->response
            ->removeHeader('Cache-Control')
            ->setHeader('Cache-Control', 'private, no-store')
            ->setHeader('Content-Disposition', 'inline; filename="certificate-' . $publicId . '.' . $parts[1] . '"')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setContentType(self::SLIP_TYPES[$parts[1]], '')
            ->setBody((string) file_get_contents($path));
    }

    /**
     * acceptCertificate() and rejectCertificate()
     * @param string      $endpoint
     * @param string|null $reasonField The body's field with the reason of a rejection; null to accept
     * @return ResponseInterface
     */
    private function reviewCertificate(string $endpoint, ?string $reasonField): ResponseInterface
    {
        $input = $this->jsonInput();
        if ($input === null) {
            return ApiResponse::error(lang('SubscriptionAdmin.invalidRequest'), 422);
        }
        $reason = $reasonField === null ? '' : $this->text($input, $reasonField);
        if ($reasonField !== null && ($reason === '' || mb_strlen($reason) > 255)) {
            return ApiResponse::error(lang('SubscriptionAdmin.invalidFields'), 422, [$reasonField => lang('SubscriptionAdmin.reasonRequired')]);
        }
        $certificates = model(WithholdingTaxCertificateModel::class);
        $refund       = null;
        try {
            $stored = $certificates->findByPublicId($input['public_id'] ?? null);
            if ($stored === null) {
                return ApiResponse::error(lang('SubscriptionAdmin.certificateNotFound'), 404);
            }
            $billing = new SubscriptionBilling();
            if ($reasonField === null) {
                $refund = $billing->acceptCertificate($stored, $this->actorId());
            } else {
                $billing->rejectCertificate($stored, $reason, $this->actorId());
            }
            $status = $certificates->find((int) $stored['id'])['cert_status'];
        } catch (BillingException $e) {
            return $this->refused($e);
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'SubscriptionAdmin.saveFailed');
        }
        $message = $reasonField !== null ? 'SubscriptionAdmin.certificateRejected'
            : ($refund === null ? 'SubscriptionAdmin.certificateAccepted' : 'SubscriptionAdmin.certificateAcceptedRefund');

        return ApiResponse::success(['public_id' => $stored['public_id'], 'cert_status' => $status, 'refund_invoice_no' => $refund], 200, lang($message, [$refund ?? '']));
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
