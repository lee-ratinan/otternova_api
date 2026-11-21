<?php
return [
    'fields'              => [
        'business_id'  => '企业',
        'store_id'     => '门店',
        'method_code'  => '付款方式',
        'handler_code' => '处理方式',
        'method_name'  => '名称',
        'instruction'  => '付款说明',
        'is_active'    => '有效',
        'sort_order'   => '排序',
    ],
    'enum'                => [
        'method_code'  => ['cash' => '现金', 'bank_transfer' => '银行转账', 'promptpay' => 'PromptPay', 'other' => '其他'],
        'handler_code' => ['instruction' => '仅提供说明', 'promptpay_qr' => 'PromptPay 二维码'],
        'is_active'    => ['Y' => '有效', 'N' => '无效'],
    ],
    'invalidMethod'       => '请从列表中选择付款方式。',
    'invalidHandler'      => '请从列表中选择此付款的处理方式。',
    'handlerMismatch'     => '此付款方式无法以这种方式处理。',
    'instructionRequired' => '请告诉顾客如何付款。',
    'invalidSettings'     => '请将设置作为对象发送。',
    'invalidSetting'      => [
        'promptpay_id' => '请输入 PromptPay ID：手机号码（10 位）、身份证或税务编号（13 位）或电子钱包 ID（15 位）。',
    ],
];
