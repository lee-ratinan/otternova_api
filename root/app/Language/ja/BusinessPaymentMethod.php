<?php
return [
    'fields'              => [
        'business_id'  => 'ビジネス',
        'store_id'     => '店舗',
        'method_code'  => '支払い方法',
        'handler_code' => '処理方法',
        'method_name'  => '名前',
        'instruction'  => '支払い案内',
        'is_active'    => '有効',
        'sort_order'   => '並び順',
    ],
    'enum'                => [
        'method_code'  => ['cash' => '現金', 'bank_transfer' => '銀行振込', 'promptpay' => 'PromptPay', 'other' => 'その他'],
        'handler_code' => ['instruction' => '案内のみ', 'promptpay_qr' => 'PromptPay QRコード'],
        'is_active'    => ['Y' => '有効', 'N' => '無効'],
    ],
    'invalidMethod'       => 'リストから支払い方法を選択してください。',
    'invalidHandler'      => 'リストからこの支払いの処理方法を選択してください。',
    'handlerMismatch'     => 'この支払い方法はこの方法で処理できません。',
    'instructionRequired' => 'お客様に支払い方法を案内してください。',
    'invalidSettings'     => '設定をオブジェクトとして送信してください。',
    'invalidSetting'      => [
        'promptpay_id' => 'PromptPay IDを入力してください：携帯電話番号（10桁）、国民IDまたは納税者番号（13桁）、またはeウォレットID（15桁）。',
    ],
];
