<?php
return [
    'fields'              => [
        'business_id'    => '商家',
        'store_id'       => '門市',
        'method_code'    => '付款方式',
        'handler_code'   => '處理方式',
        'method_name'    => '名稱',
        'instruction'    => '付款說明',
        'is_active'      => '啟用',
        'allow_shipping' => '適用於配送訂單',
        'sort_order'     => '排序',
    ],
    'enum'                => [
        'method_code'    => ['cash' => '現金', 'bank_transfer' => '銀行轉帳', 'promptpay' => 'PromptPay', 'other' => '其他'],
        'handler_code'   => ['instruction' => '僅提供說明', 'promptpay_qr' => 'PromptPay QR 碼'],
        'is_active'      => ['Y' => '啟用', 'N' => '停用'],
        'allow_shipping' => ['Y' => '可用於配送', 'N' => '僅限到店取貨'],
    ],
    'invalidMethod'       => '請從清單中選擇付款方式。',
    'invalidHandler'      => '請從清單中選擇此付款的處理方式。',
    'handlerMismatch'     => '此付款方式無法以這種方式處理。',
    'instructionRequired' => '請告訴顧客如何付款。',
    'invalidSettings'     => '請將設定以物件傳送。',
    'invalidSetting'      => [
        'promptpay_id' => '請輸入 PromptPay ID：手機號碼（10 位數）、身分證或稅籍編號（13 位數）或電子錢包 ID（15 位數）。',
    ],
];
