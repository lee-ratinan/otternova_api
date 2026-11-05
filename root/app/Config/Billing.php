<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Subscriptions, invoices and payments (subscription_master, subscription_invoice, payment_transaction); see the
 * CreateSubscriptionMasterTable migration for how the lengths below are used. Each can be set in .env as billing.<name>.
 */
class Billing extends BaseConfig
{
    /** Days after current_period_end a renewal may still be paid with no gap before the subscription is suspended */
    public int $graceDays = 3;

    /** Days a subscription stays suspended, from suspended_at, before it expires */
    public int $suspendedDays = 30;

    /** Hours a first invoice stays payable before it is voided and its discount redemption given back */
    public int $invoiceHoldHours = 24;

    /** Transfer slips are kept at {$slipUploadPath}{payment_transaction.slip_path}; never under public/ */
    public string $slipUploadPath = WRITEPATH . 'uploads/payment-slips/';

    /** Largest slip accepted, in bytes */
    public int $maxSlipFileSize = 10 * 1024 * 1024;

    /** Shown to a business paying by PromptPay (payment_method promptpay) */
    public string $promptPayId = '';

    /** Shown to a business paying by bank transfer (payment_method bank_transfer) */
    public string $bankName          = '';
    public string $bankAccountName   = '';
    public string $bankAccountNumber = '';
}
