<?php
return [
    'fields'             => [
        'business_id'     => '企业',
        'discount_code'   => '代码',
        'description'     => '描述',
        'discount_type'   => '折扣类型',
        'discount_value'  => '折扣',
        'applies_to'      => '适用于',
        'min_subtotal'    => '最低小计',
        'max_redemptions' => '最多使用次数',
        'valid_from'      => '生效时间',
        'expires_at'      => '到期时间',
        'is_active'       => '有效',
    ],
    'invalidCode'        => '请输入 3 至 30 个字符的代码，可使用字母、数字、连字符和下划线，并以字母或数字开头。',
    'invalidValue'       => '请输入大于零的折扣，最多 2 位小数。',
    'percentTooHigh'     => '百分比折扣最多为 100。',
    'invalidAmount'      => '请输入金额，最多 2 位小数。',
    'invalidDatetime'    => '请输入日期和时间。',
    'expiresBeforeValid' => '折扣的到期时间必须晚于生效时间。',
    'belowRedeemed'      => '此折扣已被使用 {0} 次；上限不能低于此数。',
];
