<?php
// Subject and body of each EmailService::TYPE_* email; {placeholders} come from the $data passed to send()
return [
    // Text around the content in Views/email/template-v1.php; company_address is left out of the footer while it is empty
    'template' => [
        'closing_message'     => '如有任何問題，請聯絡我們的客服團隊。',
        'best_regards'        => '敬祝 順心，',
        'team_name'           => 'OtterNova 團隊',
        'company_name'        => 'OtterNova',
        'company_address'     => '##/## Thanon Song Prapha, Khwaeng Don Mueang, Khet Don Mueang, Bangkok, 10210 THAILAND',
        'contact_support'     => '聯絡客服',
        'privacy_policy'      => '隱私權政策',
        'all_rights_reserved' => '版權所有。',
    ],
    'login_otp' => [
        'subject' => '您的 OtterNova 登入驗證碼',
        'body'    => "{full_name} 您好：\n\n您的登入驗證碼是 {otp}，將在 {minutes} 分鐘後過期。\n\n如果您沒有嘗試登入，請變更您的密碼。",
    ],
    'account_activation' => [
        'subject' => '啟用您的 OtterNova 帳號',
        'body'    => "{full_name} 您好：\n\n感謝您註冊 OtterNova。請開啟以下連結以啟用您的帳號：\n\n{link}\n\n此連結將在 {hours} 小時後過期。如果您沒有註冊，可以忽略此郵件。",
    ],
    'account_exists'     => [
        'subject' => '您的 OtterNova 帳號',
        'body'    => "{full_name} 您好：\n\n有人嘗試使用此電子郵件地址註冊 OtterNova，但此地址已有帳號。您可以在此登入：\n\n{link}\n\n如果您忘記密碼，請在登入頁面使用「忘記密碼」。如果這不是您本人，可以忽略此郵件。",
    ],
    'business_invitation' => [
        'subject' => '{inviter} 邀請您加入 OtterNova 上的 {business_name}',
        'body'    => "您好：\n\n{inviter} 邀請您以 {role} 身分在 OtterNova 上的 {business_name} 工作。請開啟以下連結，使用此電子郵件地址建立帳號：\n\n{link}\n\n此連結將在 {days} 天後過期。如果您沒有預期收到此邀請，可以忽略此郵件。",
    ],
    'password_reset'     => [
        'subject' => '重設您的 OtterNova 密碼',
        'body'    => "{full_name} 您好：\n\n我們收到了重設您 OtterNova 帳號密碼的請求。請開啟以下連結設定新密碼：\n\n{link}\n\n此連結將在 {minutes} 分鐘後過期，且只能使用一次。如果您沒有提出此請求，可以忽略此郵件：您的密碼不會改變。",
    ],
    'order_cancelled'    => [
        'subject' => '您在 {business_name} 的訂單 {order_number} 已取消',
        'body'    => "{first_name} 您好：\n\n您在 {business_name} 的訂單 {order_number} 已被取消。此訂單尚未付款，因此無需退款。\n\n您可以在此查看訂單：\n\n{link}\n\n如有任何問題，請聯絡 {business_name}。",
    ],
    'order_cancelled_paid' => [
        'subject' => '您在 {business_name} 的訂單 {order_number} 已取消',
        'body'    => "{first_name} 您好：\n\n您在 {business_name} 的訂單 {order_number} 已被取消。您已為此支付 {amount}：{business_name} 將退款給您，並可能與您聯絡退款方式。\n\n您可以在此查看訂單：\n\n{link}\n\n如有任何問題，請聯絡 {business_name}。",
    ],
    'order_rescheduled'  => [
        'subject' => '您在 {business_name} 的預約已改至 {to}',
        'body'    => "{first_name} 您好：\n\n您在 {business_name} 預約的 {service}（訂單 {order_number}）已從 {from} 改至 {to}。\n\n您可以在此查看訂單：\n\n{link}\n\n如果此時間不適合您，請聯絡 {business_name}。",
    ],
    'order_placed'       => [
        'subject'        => '您在 {business_name} 的訂單 {order_number}',
        'body'           => "{first_name} 您好：\n\n感謝您在 {business_name} 下單。您的訂單編號是 {order_number}。\n\n{items}\n\n總計：{total}\n\n此訂單尚未付款。付款方式：{method}\n{how}\n\n您可以在此查看訂單、付款，並在付款後通知我們：\n\n{link}\n\n在付款之前，您的訂單可能會被取消。",
        'promptpay'      => '請掃描訂單頁面上的 PromptPay QR 碼，金額已自動帶入。',
        'shipping'       => '配送：{0}',
        'no-instruction' => '{business_name} 會告訴您如何付款。',
    ],
    'order_placed_paid'  => [
        'subject' => '您在 {business_name} 的訂單 {order_number}',
        'body'    => "{first_name} 您好：\n\n感謝您在 {business_name} 下單。您的訂單編號是 {order_number}。\n\n{items}\n\n總計：{total}，已透過 {method} 付款。\n\n您可以在此查看訂單：\n\n{link}",
    ],
    'order_history_link' => [
        'subject' => '您在 {business_name} 的訂單紀錄',
        'body'    => "您好：\n\n有人要求查看使用此電子郵件地址（{email}）在 {business_name} 下的訂單。請開啟以下連結查看：\n\n{link}\n\n此連結只能使用一次，有效期為 {minutes} 分鐘。如果您沒有提出此請求，可以忽略此郵件：沒有任何內容被顯示給任何人。",
    ],
];
