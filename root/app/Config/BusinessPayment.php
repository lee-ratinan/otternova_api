<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * The ways the customers of a business can pay it (business_payment_method): what they pay with ($methods) and how
 * each way is handled ($handlers). App\Models\BusinessPaymentMethodModel checks a row against these.
 * A payment gateway (Omise, Stripe) comes in as a new handler: its methods, confirmation A, and the settings it needs.
 */
class BusinessPayment extends BaseConfig
{
    /** method_code: what the customer pays with */
    public array $methods = ['cash', 'bank_transfer', 'promptpay', 'other'];

    /**
     * handler_code => how it works:
     * - methods:      the method_code values it takes
     * - confirmation: A when the provider confirms the payment on its own, M when the business's staff do
     * - instruction:  whether the row needs an instruction, the only thing the customer is shown
     * - settings:     settings_json key => regex its value must match (after spaces and dashes are taken out)
     * @var array<string, array{methods: list<string>, confirmation: string, instruction: bool, settings: array<string, string>}>
     */
    public array $handlers = [
        // the instruction is all there is, as "Bring cash and pay at the counter before the service"
        'instruction' => [
            'methods'      => ['cash', 'bank_transfer', 'promptpay', 'other'],
            'confirmation' => 'M',
            'instruction'  => true,
            'settings'     => [],
        ],
        // a PromptPay QR with the amount in it, from the QR generator (Config\Billing::$qrGeneratorLink);
        // promptpay_id is a mobile number (10 digits), a national or tax ID (13) or an e-wallet ID (15)
        'promptpay_qr' => [
            'methods'      => ['promptpay'],
            'confirmation' => 'M',
            'instruction'  => false,
            'settings'     => ['promptpay_id' => '/^(0\d{9}|\d{13}|\d{15})$/'],
        ],
    ];
}
