<?php
// Subject and body of each EmailService::TYPE_* email; {placeholders} come from the $data passed to send()
return [
    // Text around the content in Views/email/template-v1.php; company_address is left out of the footer while it is empty
    'template' => [
        'closing_message'     => 'ご不明な点がございましたら、サポートチームまでお問い合わせください。',
        'best_regards'        => 'よろしくお願いいたします。',
        'team_name'           => 'OtterNovaチーム',
        'company_name'        => 'OtterNova',
        'company_address'     => '##/## Thanon Song Prapha, Khwaeng Don Mueang, Khet Don Mueang, Bangkok, 10210 THAILAND',
        'contact_support'     => 'サポートに問い合わせる',
        'privacy_policy'      => 'プライバシーポリシー',
        'all_rights_reserved' => 'All rights reserved.',
    ],
    'login_otp' => [
        'subject' => 'OtterNovaのサインインコード',
        'body'    => "{full_name} 様\n\nサインインコードは {otp} です。有効期限は{minutes}分です。\n\nサインインを試みていない場合は、パスワードを変更してください。",
    ],
    'account_activation' => [
        'subject' => 'OtterNovaアカウントを有効化してください',
        'body'    => "{full_name} 様\n\nOtterNovaにご登録いただきありがとうございます。次のリンクを開いて、アカウントを有効化してください。\n\n{link}\n\nリンクの有効期限は{hours}時間です。ご登録に心当たりがない場合は、このメールを無視してください。",
    ],
    'account_exists'     => [
        'subject' => 'OtterNovaアカウントについて',
        'body'    => "{full_name} 様\n\nこのメールアドレスでOtterNovaへの登録が試みられましたが、すでにアカウントが存在します。こちらからサインインできます。\n\n{link}\n\nパスワードをお忘れの場合は、サインインページの「パスワードをお忘れですか」をご利用ください。お心当たりがない場合は、このメールを無視してください。",
    ],
    'business_invitation' => [
        'subject' => '{inviter}さんがOtterNovaの{business_name}にあなたを招待しました',
        'body'    => "こんにちは。\n\n{inviter}さんが、OtterNovaの{business_name}で{role}として働くようあなたを招待しました。次のリンクを開いて、このメールアドレスでアカウントを作成してください。\n\n{link}\n\nリンクの有効期限は{days}日です。お心当たりがない場合は、このメールを無視してください。",
    ],
    'password_reset'     => [
        'subject' => 'OtterNovaのパスワードを再設定してください',
        'body'    => "{full_name} 様\n\nOtterNovaアカウントのパスワード再設定のリクエストを受け付けました。次のリンクを開いて、新しいパスワードを設定してください。\n\n{link}\n\nリンクの有効期限は{minutes}分で、1回のみ使用できます。お心当たりがない場合は、このメールを無視してください。パスワードは変更されません。",
    ],
    'order_cancelled'    => [
        'subject' => '{business_name}でのご注文 {order_number} はキャンセルされました',
        'body'    => "{first_name} 様\n\n{business_name}でのご注文 {order_number} はキャンセルされました。お支払いはなかったため、返金はありません。\n\nご注文はこちらでご確認いただけます。\n\n{link}\n\nご不明な点がございましたら、{business_name}までお問い合わせください。",
    ],
    'order_cancelled_paid' => [
        'subject' => '{business_name}でのご注文 {order_number} はキャンセルされました',
        'body'    => "{first_name} 様\n\n{business_name}でのご注文 {order_number} はキャンセルされました。お支払い済みの{amount}は{business_name}から返金されます。返金方法についてご連絡する場合があります。\n\nご注文はこちらでご確認いただけます。\n\n{link}\n\nご不明な点がございましたら、{business_name}までお問い合わせください。",
    ],
    'order_rescheduled'  => [
        'subject' => '{business_name}のご予約が{to}に変更されました',
        'body'    => "{first_name} 様\n\n{business_name}での{service}のご予約（注文 {order_number}）は、{from}から{to}に変更されました。\n\nご注文はこちらでご確認いただけます。\n\n{link}\n\nこの日時のご都合が悪い場合は、{business_name}までお問い合わせください。",
    ],
    'order_placed'       => [
        'subject'        => '{business_name}でのご注文 {order_number}',
        'body'           => "{first_name} 様\n\n{business_name}でご注文いただきありがとうございます。注文番号は {order_number} です。\n\n{items}\n\n合計：{total}\n\nまだお支払いが完了していません。お支払い方法：{method}\n{how}\n\nご注文の確認、お支払い、お支払い完了のご連絡はこちらから行えます。\n\n{link}\n\nお支払いが完了するまでは、ご注文がキャンセルされる場合があります。",
        'promptpay'      => '注文ページのPromptPay QRコードをスキャンしてください。金額はすでに入力されています。',
        'shipping'       => '配送：{0}',
        'shipping-label' => '配送',
        'no-instruction' => 'お支払い方法は{business_name}からご案内します。',
    ],
    'order_placed_paid'  => [
        'subject' => '{business_name}でのご注文 {order_number}',
        'body'    => "{first_name} 様\n\n{business_name}でご注文いただきありがとうございます。注文番号は {order_number} です。\n\n{items}\n\n合計：{total}（{method}でお支払い済み）\n\nご注文はこちらでご確認いただけます。\n\n{link}",
    ],
    'order_history_link' => [
        'subject' => '{business_name}でのご注文履歴',
        'body'    => "こんにちは。\n\nこのメールアドレス（{email}）で{business_name}に行われた注文の閲覧がリクエストされました。次のリンクを開くとご確認いただけます。\n\n{link}\n\nリンクは{minutes}分間、1回のみ使用できます。お心当たりがない場合は、このメールを無視してください。誰にも何も表示されていません。",
    ],
];
