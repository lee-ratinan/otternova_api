<?php
return [
    'fields'              => [
        'business_id'  => 'Business',
        'store_id'     => 'Store',
        'method_code'  => 'Payment Method',
        'handler_code' => 'Handled By',
        'method_name'  => 'Name',
        'instruction'  => 'Instruction',
        'is_active'    => 'Active',
        'sort_order'   => 'Sort Order',
    ],
    'enum'                => [
        'method_code'  => ['cash' => 'Cash', 'bank_transfer' => 'Bank Transfer', 'promptpay' => 'PromptPay', 'other' => 'Other'],
        'handler_code' => ['instruction' => 'Instruction only', 'promptpay_qr' => 'PromptPay QR code'],
        'is_active'    => ['Y' => 'Active', 'N' => 'Inactive'],
    ],
    'invalidMethod'       => 'Please choose a payment method from the list.',
    'invalidHandler'      => 'Please choose how this payment is handled from the list.',
    'handlerMismatch'     => 'This payment method cannot be handled this way.',
    'instructionRequired' => 'Please tell customers how to pay.',
    'invalidSettings'     => 'Please send the settings as an object.',
    'invalidSetting'      => [
        'promptpay_id' => 'Please enter a PromptPay ID: a mobile number (10 digits), a national or tax ID (13 digits) or an e-wallet ID (15 digits).',
    ],
];
