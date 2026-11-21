<?php
return [
    'fields'             => [
        'business_id'     => 'ビジネス',
        'discount_code'   => 'コード',
        'description'     => '説明',
        'discount_type'   => '割引タイプ',
        'discount_value'  => '割引',
        'applies_to'      => '適用対象',
        'min_subtotal'    => '最低小計',
        'max_redemptions' => '最大利用回数',
        'valid_from'      => '有効開始日時',
        'expires_at'      => '有効期限',
        'is_active'       => '有効',
    ],
    'invalidCode'        => 'コードは、英字または数字で始まる3〜30文字の英字、数字、ハイフン、アンダースコアで入力してください。',
    'invalidValue'       => '0より大きく、小数点以下2桁までの割引を入力してください。',
    'percentTooHigh'     => 'パーセント割引は最大100までです。',
    'invalidAmount'      => '金額を小数点以下2桁までで入力してください。',
    'invalidDatetime'    => '日付と時刻を入力してください。',
    'expiresBeforeValid' => '割引の有効期限は、有効開始日時より後にしてください。',
    'belowRedeemed'      => 'この割引はすでに{0}回利用されているため、最大利用回数をそれより少なくすることはできません。',
];
