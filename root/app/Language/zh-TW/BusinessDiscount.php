<?php
return [
    'fields'             => [
        'business_id'     => '商家',
        'discount_code'   => '代碼',
        'description'     => '說明',
        'discount_type'   => '折扣類型',
        'discount_value'  => '折扣',
        'applies_to'      => '適用於',
        'min_subtotal'    => '最低小計',
        'max_redemptions' => '最多使用次數',
        'valid_from'      => '生效時間',
        'expires_at'      => '到期時間',
        'is_active'       => '啟用',
    ],
    'invalidCode'        => '請輸入 3 到 30 個字元的代碼，可使用字母、數字、連字號與底線，並以字母或數字開頭。',
    'invalidValue'       => '請輸入大於零的折扣，最多 2 位小數。',
    'percentTooHigh'     => '百分比折扣最多為 100。',
    'invalidAmount'      => '請輸入金額，最多 2 位小數。',
    'invalidDatetime'    => '請輸入日期與時間。',
    'expiresBeforeValid' => '折扣的到期時間必須晚於生效時間。',
    'belowRedeemed'      => '此折扣已被使用 {0} 次；上限不能低於此數。',
];
