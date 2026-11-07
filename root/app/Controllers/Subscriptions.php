<?php

namespace App\Controllers;

use App\Libraries\ApiResponse;
use App\Libraries\BillingException;
use App\Libraries\Money;
use App\Libraries\SubscriptionBilling;
use App\Models\BusinessClientLinkModel;
use App\Models\BusinessMasterModel;
use App\Models\ClientMasterModel;
use App\Models\DiscountMasterModel;
use App\Models\PaymentMethodModel;
use App\Models\PaymentTransactionModel;
use App\Models\PlanMasterModel;
use App\Models\PlanVersionModel;
use App\Models\SubscriptionInvoiceModel;
use App\Models\SubscriptionMasterModel;
use App\Models\WithholdingTaxCertificateModel;
use App\Models\WithholdingTaxRuleModel;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Billing as BillingConfig;
use RuntimeException;
use Throwable;

/**
 * A business's subscription, for the Client app, behind the jwt:C filter, and only for the active owners of the
 * business (business_client_link.client_role O). Every request names the business by its business_slug; a
 * subscription, an invoice and a payment go out by their public_id, and an invoice comes back in by it.
 * A business with no live subscription is on the free plan. Every time sent back is UTC.
 */
class Subscriptions extends BaseController
{
    use BillingRequestTrait;

    /** The slip types accepted, by the type detected from the content, and the extension they are saved with */
    private const SLIP_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];

    /** A certificate amount as the body may give it: up to 2 decimal places */
    private const AMOUNT_PATTERN = '/^\d{1,17}(\.\d{1,2})?$/';

    /**
     * POST /client/subscription/current { business_slug }
     * plan_code (free when there is no live subscription), the live subscription or null, its invoices, newest
     * first, each with its payments and withholding tax certificates, and the business's withholding (see withholding())
     * @return ResponseInterface
     */
    public function current(): ResponseInterface
    {
        $endpoint = 'v1/client/subscription/current';
        [$input, $business, $error] = $this->ownedBusiness($endpoint);
        if ($error !== null) {
            return $error;
        }
        try {
            $withholding   = $this->withholdingPayload($business);
            $subscriptions = model(SubscriptionMasterModel::class);
            $live          = $subscriptions->liveOfBusiness((int) $business['id']);
            if ($live === null) {
                $free = model(PlanMasterModel::class)->where('plan_tier', PlanMasterModel::FREE_TIER)->first();

                return ApiResponse::success(['plan_code' => $free['plan_code'] ?? null, 'subscription' => null, 'invoices' => [], 'withholding' => $withholding]);
            }
            $subscription = $subscriptions->findPayload((int) $live['id']);
            $invoices     = model(SubscriptionInvoiceModel::class)->payloadsOfSubscription((int) $live['id'], false);
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'Subscriptions.loadFailed');
        }

        return ApiResponse::success(['plan_code' => $subscription['plan_code'], 'subscription' => $subscription, 'invoices' => $invoices, 'withholding' => $withholding]);
    }

    /**
     * POST /client/subscription/plans { business_slug }
     * Every plan, the free one first, with the prices the business would pay: for each billing cycle, the price now
     * and, when a change is set, the next price and when it starts (next_price, next_price_from)
     * @return ResponseInterface
     */
    public function plans(): ResponseInterface
    {
        $endpoint = 'v1/client/subscription/plans';
        [$input, $business, $error] = $this->ownedBusiness($endpoint);
        if ($error !== null) {
            return $error;
        }
        $now      = SubscriptionBilling::now();
        $versions = model(PlanVersionModel::class);
        try {
            $plans = model(PlanMasterModel::class)->findAll();
            usort($plans, static fn (array $a, array $b) => (int) $a['plan_tier'] <=> (int) $b['plan_tier']);
            $rows = [];
            foreach ($plans as $plan) {
                $prices = [];
                foreach ([PlanVersionModel::MONTHLY, PlanVersionModel::YEARLY] as $cycle) {
                    $version = $versions->inEffect((int) $plan['id'], $business['country_code'], $cycle, $now);
                    if ($version === null) {
                        continue;
                    }
                    $next     = $version['effective_to'] === null ? null : $versions->inEffect((int) $plan['id'], $business['country_code'], $cycle, $version['effective_to']);
                    $prices[] = [
                        'billing_cycle'   => $cycle,
                        'currency_code'   => $version['currency_code'],
                        'price'           => Money::format($version['price']),
                        'next_price'      => Money::format($next['price'] ?? null),
                        'next_price_from' => $next === null ? null : $version['effective_to'],
                    ];
                }
                $rows[] = ['plan_code' => $plan['plan_code'], 'plan_description' => $plan['plan_description'], 'plan_tier' => (int) $plan['plan_tier'], 'prices' => $prices];
            }
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'Subscriptions.loadFailed');
        }

        return ApiResponse::success(['plans' => $rows]);
    }

    /**
     * POST /client/subscription/discounts { business_slug, plan_code, billing_cycle }
     * The listed discount codes the business can apply to that plan and cycle now
     * @return ResponseInterface
     */
    public function discounts(): ResponseInterface
    {
        $endpoint = 'v1/client/subscription/discounts';
        [$input, $business, $error] = $this->ownedBusiness($endpoint);
        if ($error !== null) {
            return $error;
        }
        $now = SubscriptionBilling::now();
        try {
            $plan    = model(PlanMasterModel::class)->findByCode($this->text($input, 'plan_code'));
            $version = $plan === null ? null : model(PlanVersionModel::class)->inEffect((int) $plan['id'], $business['country_code'], strtoupper($this->text($input, 'billing_cycle')), $now);
            if ($version === null) {
                return ApiResponse::error(lang('Subscriptions.noPrice'), 404);
            }
            $discounts = model(DiscountMasterModel::class);
            $rows      = [];
            foreach ($discounts->listedAt($now) as $discount) {
                if ($discounts->eligibilityError($discount, $business, $version, $now) === null) {
                    $rows[] = DiscountMasterModel::toCheckout($discount);
                }
            }
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'Subscriptions.loadFailed');
        }

        return ApiResponse::success(['discounts' => $rows]);
    }

    /**
     * POST /client/subscription/payment-methods { business_slug, invoice_public_id }
     * The ways the invoice can be paid, each with what the payer needs: the PromptPay ID or the bank account
     * @return ResponseInterface
     */
    public function paymentMethods(): ResponseInterface
    {
        $endpoint = 'v1/client/subscription/payment-methods';
        [$input, $business, $error] = $this->ownedBusiness($endpoint);
        if ($error !== null) {
            return $error;
        }
        try {
            $invoice = $this->invoiceOf($business, $input['invoice_public_id'] ?? null);
            if ($invoice === null) {
                return ApiResponse::error(lang('Subscriptions.invoiceNotFound'), 404);
            }
            $rows = model(PaymentMethodModel::class)->listUsable($business['country_code'], $invoice['currency_code']);
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'Subscriptions.loadFailed');
        }
        $config = config(BillingConfig::class);
        foreach ($rows as &$row) {
            $row['payee'] = match ($row['method_code']) {
                'promptpay'     => ['promptpay_id' => $config->promptPayId],
                'bank_transfer' => ['bank_name' => $config->bankName, 'account_name' => $config->bankAccountName, 'account_number' => $config->bankAccountNumber],
                default         => null,
            };
        }

        return ApiResponse::success(['payment_methods' => $rows]);
    }

    /**
     * POST /client/subscription/subscribe { business_slug, plan_code, billing_cycle, discount_code }
     * Starts a subscription at the price in effect now (discount_code optional), pending until its first invoice is
     * paid; one with nothing to pay after the discount is active at once. 409 when the business already has a live one.
     * @return ResponseInterface
     */
    public function subscribe(): ResponseInterface
    {
        $endpoint = 'v1/client/subscription/subscribe';
        [$input, $business, $error] = $this->ownedBusiness($endpoint);
        if ($error !== null) {
            return $error;
        }
        $cycle = strtoupper($this->text($input, 'billing_cycle'));
        try {
            $plan = model(PlanMasterModel::class)->findByCode($this->text($input, 'plan_code'));
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'Subscriptions.loadFailed');
        }
        $errors = [];
        if ($plan === null) {
            $errors['plan_code'] = lang('Subscriptions.planNotFound');
        }
        if (! in_array($cycle, [PlanVersionModel::MONTHLY, PlanVersionModel::YEARLY], true)) {
            $errors['billing_cycle'] = lang('Subscriptions.invalidCycle');
        }
        if ($errors !== []) {
            return ApiResponse::error(lang('Subscriptions.invalidFields'), 422, $errors);
        }

        try {
            $ids          = (new SubscriptionBilling())->subscribe($business, $plan, $cycle, $this->text($input, 'discount_code'), $this->actorId());
            $subscription = model(SubscriptionMasterModel::class)->find($ids['subscription_id']);
            $invoice      = model(SubscriptionInvoiceModel::class)->find($ids['invoice_id']);
        } catch (BillingException $e) {
            return $this->refused($e);
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'Subscriptions.saveFailed');
        }

        return ApiResponse::success([
            'subscription_public_id' => $subscription['public_id'],
            'subscription_status'    => $subscription['subscription_status'],
            'invoice_public_id'      => $invoice['public_id'],
            'amount_due'             => Money::format($invoice['amount_due']),
            'wht_amount'             => Money::format($invoice['wht_amount']),
            'amount_payable'         => Money::format($invoice['amount_payable']),
            'currency_code'          => $invoice['currency_code'],
        ], 200, lang('Subscriptions.subscribed'));
    }

    /**
     * POST /client/subscription/apply-discount { business_slug, invoice_public_id, discount_code }
     * Applies the code to the pending invoice, in place of the one it had. An issued invoice never changes, so it is
     * voided and made again with the discount: invoice_public_id is the new one's.
     * @return ResponseInterface
     */
    public function applyDiscount(): ResponseInterface
    {
        $endpoint = 'v1/client/subscription/apply-discount';
        [$input, $business, $error] = $this->ownedBusiness($endpoint);
        if ($error !== null) {
            return $error;
        }
        $code = $this->text($input, 'discount_code');
        if ($code === '') {
            return ApiResponse::error(lang('Subscriptions.invalidFields'), 422, ['discount_code' => lang('Subscriptions.discountRequired')]);
        }
        try {
            $invoice = $this->invoiceOf($business, $input['invoice_public_id'] ?? null);
            if ($invoice === null) {
                return ApiResponse::error(lang('Subscriptions.invoiceNotFound'), 404);
            }
            // the invoice is made again with the discount, under a new number and public_id
            $invoice = (new SubscriptionBilling())->applyDiscount($invoice, $business, $code, $this->actorId());
        } catch (BillingException $e) {
            return $this->refused($e);
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'Subscriptions.saveFailed');
        }

        return ApiResponse::success([
            'invoice_public_id' => $invoice['public_id'],
            'list_price'        => Money::format($invoice['list_price']),
            'discount_amount'   => Money::format($invoice['discount_amount']),
            'amount_due'        => Money::format($invoice['amount_due']),
            'wht_amount'        => Money::format($invoice['wht_amount']),
            'amount_payable'    => Money::format($invoice['amount_payable']),
            'invoice_status'    => $invoice['invoice_status'],
        ], 200, lang('Subscriptions.discountApplied'));
    }

    /**
     * POST /client/subscription/pay, multipart/form-data { business_slug, invoice_public_id, method_code,
     * provider_ref, slip }
     * Sends the payment of the pending invoice for the staff to check: slip is the transfer slip (JPEG, PNG, WebP or
     * PDF), provider_ref the bank's reference, if any. The invoice is paid once the staff approve it.
     * @return ResponseInterface
     */
    public function pay(): ResponseInterface
    {
        $endpoint = 'v1/client/subscription/pay';
        $input    = [
            'business_slug'     => $this->request->getPost('business_slug'),
            'invoice_public_id' => $this->request->getPost('invoice_public_id'),
            'method_code'       => $this->request->getPost('method_code'),
            'provider_ref'      => $this->request->getPost('provider_ref'),
        ];
        [$business, $error] = $this->ownedBusinessOf($input, $endpoint);
        if ($error !== null) {
            return $error;
        }
        $config = config(BillingConfig::class);
        $file   = $this->request->getFile('slip');
        if ($file === null || ! service('imageProcessor')->isGenuineUpload($file)) {
            return ApiResponse::error(lang('Subscriptions.slipRequired'), 422, ['slip' => lang('Subscriptions.slipRequired')]);
        }
        if ($file->getSize() > $config->maxSlipFileSize) {
            return ApiResponse::error(lang('Subscriptions.slipTooLarge', [intdiv($config->maxSlipFileSize, 1024 * 1024)]), 413);
        }
        // detected from the content: the client's claimed type and extension are not trusted
        $extension = self::SLIP_TYPES[$file->getMimeType()] ?? null;
        if ($extension === null) {
            return ApiResponse::error(lang('Subscriptions.slipType'), 415, ['slip' => lang('Subscriptions.slipType')]);
        }
        $reference = $this->text($input, 'provider_ref');
        if (mb_strlen($reference) > 100) {
            return ApiResponse::error(lang('Subscriptions.invalidFields'), 422, ['provider_ref' => lang('Subscriptions.referenceTooLong')]);
        }

        try {
            $invoice = $this->invoiceOf($business, $input['invoice_public_id']);
            if ($invoice === null) {
                return ApiResponse::error(lang('Subscriptions.invoiceNotFound'), 404);
            }
            $method = model(PaymentMethodModel::class)->findByCode($input['method_code']);
            if ($method === null) {
                return ApiResponse::error(lang('Subscriptions.invalidFields'), 422, ['method_code' => lang('Subscriptions.methodNotUsable')]);
            }
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'Subscriptions.loadFailed');
        }

        $folder = rtrim($config->slipUploadPath, '/') . '/';
        if (! is_dir($folder) && ! @mkdir($folder, 0750, true) && ! is_dir($folder)) {
            log_message('error', $endpoint . ': cannot create {folder}', ['folder' => $folder]);

            return ApiResponse::error(lang('Subscriptions.saveFailed'), 500);
        }
        $source = $file->getTempName();
        $saved  = null;
        try {
            $id = (new SubscriptionBilling())->submitPayment($invoice, $business, $method, $reference === '' ? null : $reference,
                static function (int $id) use ($source, $folder, $extension, &$saved): string {
                    $name = $id . '-' . bin2hex(random_bytes(8)) . '.' . $extension;
                    if (! @copy($source, $folder . $name)) {
                        throw new RuntimeException('Cannot save ' . $folder . $name);
                    }
                    $saved = $folder . $name;

                    return $name;
                }, $this->actorId());
        } catch (BillingException $e) {
            return $this->refused($e);
        } catch (Throwable $e) {
            if ($saved !== null) {
                @unlink($saved);
            }

            return $this->failed($endpoint, $e, 'Subscriptions.saveFailed');
        }

        return ApiResponse::success(['payment_public_id' => model(PaymentTransactionModel::class)->publicIdOf($id)], 200, lang('Subscriptions.paymentSent'));
    }

    /**
     * POST /client/subscription/cancel { business_slug }
     * An active subscription ends at the end of its period (cancel_at_period_end); a pending or suspended one ends now
     * @return ResponseInterface
     */
    public function cancel(): ResponseInterface
    {
        return $this->changeLive('v1/client/subscription/cancel', static fn (SubscriptionBilling $billing, array $live, int $actorId) => $billing->cancelByBusiness($live, $actorId), 'Subscriptions.cancelled');
    }

    /**
     * POST /client/subscription/resume { business_slug }
     * Takes back a cancellation at the end of the period
     * @return ResponseInterface
     */
    public function resume(): ResponseInterface
    {
        return $this->changeLive('v1/client/subscription/resume', static fn (SubscriptionBilling $billing, array $live, int $actorId) => $billing->resume($live, $actorId), 'Subscriptions.resumed');
    }

    /**
     * POST /client/subscription/change-plan { business_slug, plan_code, billing_cycle }
     * Moves the active subscription to another plan or billing cycle from its next period (an upgrade or a
     * downgrade alike); the plan and cycle it is on now takes back a change set earlier. A renewal invoice not yet
     * paid is made again at the new plan.
     * @return ResponseInterface
     */
    public function changePlan(): ResponseInterface
    {
        $endpoint = 'v1/client/subscription/change-plan';
        [$input, $business, $error] = $this->ownedBusiness($endpoint);
        if ($error !== null) {
            return $error;
        }
        $cycle = strtoupper($this->text($input, 'billing_cycle'));
        try {
            $plan = model(PlanMasterModel::class)->findByCode($this->text($input, 'plan_code'));
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'Subscriptions.loadFailed');
        }
        $errors = [];
        if ($plan === null) {
            $errors['plan_code'] = lang('Subscriptions.planNotFound');
        }
        if (! in_array($cycle, [PlanVersionModel::MONTHLY, PlanVersionModel::YEARLY], true)) {
            $errors['billing_cycle'] = lang('Subscriptions.invalidCycle');
        }
        if ($errors !== []) {
            return ApiResponse::error(lang('Subscriptions.invalidFields'), 422, $errors);
        }

        $subscriptions = model(SubscriptionMasterModel::class);
        try {
            $live = $subscriptions->liveOfBusiness((int) $business['id']);
            if ($live === null) {
                return ApiResponse::error(lang('Subscriptions.noSubscription'), 404);
            }
            $changed      = (new SubscriptionBilling())->changePlan($live, $business, $plan, $cycle, $this->actorId());
            $subscription = $subscriptions->findPayload((int) $live['id']);
        } catch (BillingException $e) {
            return $this->refused($e);
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'Subscriptions.saveFailed');
        }

        return ApiResponse::success(['subscription' => $subscription], 200, lang($changed ? 'Subscriptions.planChanged' : 'Subscriptions.planChangeUndone'));
    }

    /**
     * POST /client/subscription/invoices { business_slug, start, length }
     * One page of the business's invoices, of every subscription it has had, newest first, each with its payments
     * @return ResponseInterface
     */
    public function invoices(): ResponseInterface
    {
        $endpoint = 'v1/client/subscription/invoices';
        [$input, $business, $error] = $this->ownedBusiness($endpoint);
        if ($error !== null) {
            return $error;
        }
        [$start, $length] = $this->page($input);
        try {
            $data = model(SubscriptionInvoiceModel::class)->pageOfBusiness((int) $business['id'], $start, $length);
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'Subscriptions.loadFailed');
        }

        return ApiResponse::success($data);
    }

    /**
     * POST /client/subscription/withholding { business_slug, wht_rule_code, legal_name, tax_branch_code }
     * Asks to withhold tax from what the business pays, for the staff to approve; the business needs its tax_id.
     * wht_rule_code may be left out when its country has only one rule. Once approved, each invoice is less the tax
     * (amount_payable), and the business uploads the certificate of each one it pays.
     * @return ResponseInterface
     */
    public function withholding(): ResponseInterface
    {
        $endpoint = 'v1/client/subscription/withholding';
        [$input, $business, $error] = $this->ownedBusiness($endpoint);
        if ($error !== null) {
            return $error;
        }
        $ruleCode   = strtoupper($this->text($input, 'wht_rule_code'));
        $legalName  = $this->text($input, 'legal_name');
        $branchCode = $this->text($input, 'tax_branch_code');
        $errors     = [];
        if ($legalName === '' || mb_strlen($legalName) > 255) {
            $errors['legal_name'] = lang('Subscriptions.legalNameRequired');
        }
        if (! preg_match(BusinessMasterModel::TAX_BRANCH_PATTERN, $branchCode)) {
            $errors['tax_branch_code'] = lang('Subscriptions.branchCodeInvalid');
        }
        if ($errors !== []) {
            return ApiResponse::error(lang('Subscriptions.invalidFields'), 422, $errors);
        }
        try {
            if ($ruleCode === '') {
                $rules    = model(WithholdingTaxRuleModel::class)->inEffectOfCountry($business['country_code'], SubscriptionBilling::now());
                $ruleCode = count($rules) === 1 ? (string) array_key_first($rules) : '';
            }
            (new SubscriptionBilling())->requestWithholding($business, $ruleCode, $legalName, $branchCode, $this->actorId());
            $withholding = $this->withholdingPayload(model(BusinessMasterModel::class)->find((int) $business['id']));
        } catch (BillingException $e) {
            return $this->refused($e);
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'Subscriptions.saveFailed');
        }

        return ApiResponse::success(['withholding' => $withholding], 200, lang('Subscriptions.withholdingRequested'));
    }

    /**
     * POST /client/subscription/withholding/withdraw { business_slug }
     * Stops withholding, or takes back a request; the pending invoices are worked out again
     * @return ResponseInterface
     */
    public function withdrawWithholding(): ResponseInterface
    {
        $endpoint = 'v1/client/subscription/withholding/withdraw';
        [, $business, $error] = $this->ownedBusiness($endpoint);
        if ($error !== null) {
            return $error;
        }
        try {
            (new SubscriptionBilling())->withdrawWithholding($business, $this->actorId());
            $withholding = $this->withholdingPayload(model(BusinessMasterModel::class)->find((int) $business['id']));
        } catch (BillingException $e) {
            return $this->refused($e);
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'Subscriptions.saveFailed');
        }

        return ApiResponse::success(['withholding' => $withholding], 200, lang('Subscriptions.withholdingWithdrawn'));
    }

    /**
     * POST /client/subscription/certificate, multipart/form-data { business_slug, invoice_public_id, certificate_no,
     * issued_on, base_amount, wht_amount, certificate }
     * Sends the certificate (50 ทวิ) of the tax withheld from a paid invoice, for the staff to check: certificate is the
     * file (JPEG, PNG, WebP or PDF), issued_on its date (Y-m-d), and the amounts as written on it
     * @return ResponseInterface
     */
    public function certificate(): ResponseInterface
    {
        $endpoint = 'v1/client/subscription/certificate';
        $input    = [];
        foreach (['business_slug', 'invoice_public_id', 'certificate_no', 'issued_on', 'base_amount', 'wht_amount'] as $field) {
            $input[$field] = $this->request->getPost($field);
        }
        [$business, $error] = $this->ownedBusinessOf($input, $endpoint);
        if ($error !== null) {
            return $error;
        }
        $config = config(BillingConfig::class);
        $file   = $this->request->getFile('certificate');
        if ($file === null || ! service('imageProcessor')->isGenuineUpload($file)) {
            return ApiResponse::error(lang('Subscriptions.certificateRequired'), 422, ['certificate' => lang('Subscriptions.certificateRequired')]);
        }
        if ($file->getSize() > $config->maxWhtCertFileSize) {
            return ApiResponse::error(lang('Subscriptions.slipTooLarge', [intdiv($config->maxWhtCertFileSize, 1024 * 1024)]), 413);
        }
        // detected from the content: the client's claimed type and extension are not trusted
        $extension = self::SLIP_TYPES[$file->getMimeType()] ?? null;
        if ($extension === null) {
            return ApiResponse::error(lang('Subscriptions.slipType'), 415, ['certificate' => lang('Subscriptions.slipType')]);
        }
        $fields = [
            'certificate_no' => $this->text($input, 'certificate_no'),
            'issued_on'      => $this->text($input, 'issued_on'),
            'base_amount'    => $this->text($input, 'base_amount'),
            'wht_amount'     => $this->text($input, 'wht_amount'),
        ];
        $errors = [];
        if (mb_strlen($fields['certificate_no']) > 50) {
            $errors['certificate_no'] = lang('Subscriptions.certificateNoTooLong');
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $fields['issued_on']);
        if ($date === false || $date->format('Y-m-d') !== $fields['issued_on']) {
            $errors['issued_on'] = lang('Subscriptions.issuedOnInvalid');
        }
        foreach (['base_amount', 'wht_amount'] as $field) {
            if (! preg_match(self::AMOUNT_PATTERN, $fields[$field])) {
                $errors[$field] = lang('Subscriptions.amountInvalid');
            } else {
                $fields[$field] = Money::format($fields[$field]);
            }
        }
        if ($errors !== []) {
            return ApiResponse::error(lang('Subscriptions.invalidFields'), 422, $errors);
        }
        $fields['certificate_no'] = $fields['certificate_no'] === '' ? null : $fields['certificate_no'];

        try {
            $invoice = $this->invoiceOf($business, $input['invoice_public_id']);
            if ($invoice === null) {
                return ApiResponse::error(lang('Subscriptions.invoiceNotFound'), 404);
            }
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'Subscriptions.loadFailed');
        }

        $folder = rtrim($config->whtCertUploadPath, '/') . '/';
        if (! is_dir($folder) && ! @mkdir($folder, 0750, true) && ! is_dir($folder)) {
            log_message('error', $endpoint . ': cannot create {folder}', ['folder' => $folder]);

            return ApiResponse::error(lang('Subscriptions.saveFailed'), 500);
        }
        $source = $file->getTempName();
        $saved  = null;
        try {
            $id = (new SubscriptionBilling())->submitCertificate($invoice, $fields,
                static function (int $id) use ($source, $folder, $extension, &$saved): string {
                    $name = $id . '-' . bin2hex(random_bytes(8)) . '.' . $extension;
                    if (! @copy($source, $folder . $name)) {
                        throw new RuntimeException('Cannot save ' . $folder . $name);
                    }
                    $saved = $folder . $name;

                    return $name;
                }, $this->actorId());
        } catch (BillingException $e) {
            return $this->refused($e);
        } catch (Throwable $e) {
            if ($saved !== null) {
                @unlink($saved);
            }

            return $this->failed($endpoint, $e, 'Subscriptions.saveFailed');
        }

        return ApiResponse::success(['certificate_public_id' => model(WithholdingTaxCertificateModel::class)->publicIdOf($id)], 200, lang('Subscriptions.certificateSent'));
    }

    // ---- inside ----

    /**
     * The business's withholding as the API sends it, with the rules of its country it can choose from
     * @param array $business With country_code, tax_id, legal_name, tax_branch_code, wht_status and wht_rule_code
     * @return array
     */
    private function withholdingPayload(array $business): array
    {
        $rules = model(WithholdingTaxRuleModel::class)->inEffectOfCountry($business['country_code'], SubscriptionBilling::now());

        return [
            'wht_status'      => $business['wht_status'],
            'wht_rule_code'   => $business['wht_rule_code'],
            'legal_name'      => $business['legal_name'],
            'tax_branch_code' => $business['tax_branch_code'],
            'has_tax_id'      => $business['tax_id'] !== null && $business['tax_id'] !== '',
            'rules'           => array_values(array_map(WithholdingTaxRuleModel::toPayload(...), $rules)),
        ];
    }

    /**
     * cancel() and resume(): a change to the business's live subscription
     * @param string   $endpoint
     * @param callable $change  fn (SubscriptionBilling, array $live, int $actorId): void
     * @param string   $message Language key of the success message
     * @return ResponseInterface
     */
    private function changeLive(string $endpoint, callable $change, string $message): ResponseInterface
    {
        [, $business, $error] = $this->ownedBusiness($endpoint);
        if ($error !== null) {
            return $error;
        }
        $subscriptions = model(SubscriptionMasterModel::class);
        try {
            $live = $subscriptions->liveOfBusiness((int) $business['id']);
            if ($live === null) {
                return ApiResponse::error(lang('Subscriptions.noSubscription'), 404);
            }
            $change(new SubscriptionBilling(), $live, $this->actorId());
            $subscription = $subscriptions->findPayload((int) $live['id']);
        } catch (BillingException $e) {
            return $this->refused($e);
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'Subscriptions.saveFailed');
        }

        return ApiResponse::success(['subscription' => $subscription], 200, lang($message));
    }

    /**
     * The invoice with that public_id, when it is one of the business's
     * @param array $business
     * @param mixed $publicId
     * @return array|null Stored row
     */
    private function invoiceOf(array $business, mixed $publicId): ?array
    {
        $invoice = model(SubscriptionInvoiceModel::class)->findByPublicId($publicId);
        if ($invoice === null) {
            return null;
        }
        $subscription = model(SubscriptionMasterModel::class)->find((int) $invoice['subscription_id']);

        return (int) $subscription['business_id'] === (int) $business['id'] ? $invoice : null;
    }

    /**
     * The JSON body and the business of its business_slug, when the signed-in client is one of its active owners
     * @param string $endpoint For the error log
     * @return array{0: array|null, 1: array|null, 2: ResponseInterface|null} [input, business, null] or [.., .., error]
     */
    private function ownedBusiness(string $endpoint): array
    {
        $input = $this->jsonInput();
        if ($input === null) {
            return [null, null, ApiResponse::error(lang('Subscriptions.invalidRequest'), 422)];
        }
        [$business, $error] = $this->ownedBusinessOf($input, $endpoint);

        return [$input, $business, $error];
    }

    /**
     * The business of $input's business_slug (id, country_code, currency_code, its withholding columns and tax_id,
     * still encrypted), when the signed-in client is one of
     * its active owners: 404 when they are not active in it (it is not theirs to see), 403 for a manager or employee
     * @param array  $input
     * @param string $endpoint For the error log
     * @return array{0: array|null, 1: ResponseInterface|null}
     */
    private function ownedBusinessOf(array $input, string $endpoint): array
    {
        $slug = $this->text($input, 'business_slug');
        if ($slug === '') {
            return [null, ApiResponse::error(lang('Subscriptions.slugRequired'), 400)];
        }
        try {
            $business = model(BusinessMasterModel::class)
                ->select('id, country_code, currency_code, tax_id, legal_name, tax_branch_code, wht_status, wht_rule_code')
                ->where('business_slug', $slug)->first();
            $client   = $business === null ? null : model(ClientMasterModel::class)->findByAuthId($this->actorId());
            $role     = $client === null ? null : model(BusinessClientLinkModel::class)->activeRole((int) $business['id'], (int) $client['id']);
        } catch (Throwable $e) {
            return [null, $this->failed($endpoint, $e, 'Subscriptions.loadFailed')];
        }
        if ($role === null) {
            return [null, ApiResponse::error(lang('Subscriptions.businessNotFound'), 404)];
        }
        if ($role !== BusinessClientLinkModel::ROLE_OWNER) {
            return [null, ApiResponse::error(lang('Subscriptions.ownersOnly'), 403)];
        }

        return [$business, null];
    }
}
