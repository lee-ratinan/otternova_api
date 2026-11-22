<?php
// Subject and body of each EmailService::TYPE_* email; {placeholders} come from the $data passed to send()
return [
    // Text around the content in Views/email/template-v1.php; company_address is left out of the footer while it is empty
    'template' => [
        'closing_message'     => '如有任何疑问，请联系我们的客服团队。',
        'best_regards'        => '此致，',
        'team_name'           => 'OtterNova 团队',
        'company_name'        => 'OtterNova',
        'company_address'     => '##/## Thanon Song Prapha, Khwaeng Don Mueang, Khet Don Mueang, Bangkok, 10210 THAILAND',
        'contact_support'     => '联系客服',
        'privacy_policy'      => '隐私政策',
        'all_rights_reserved' => '版权所有。',
    ],
    'login_otp' => [
        'subject' => '您的 OtterNova 登录验证码',
        'body'    => "{full_name} 您好，\n\n您的登录验证码是 {otp}，将在 {minutes} 分钟后过期。\n\n如果您没有尝试登录，请更改您的密码。",
    ],
    'account_activation' => [
        'subject' => '激活您的 OtterNova 账户',
        'body'    => "{full_name} 您好，\n\n感谢您注册 OtterNova。请打开以下链接以激活您的账户：\n\n{link}\n\n此链接将在 {hours} 小时后过期。如果您没有注册，可以忽略此电邮。",
    ],
    'account_exists'     => [
        'subject' => '您的 OtterNova 账户',
        'body'    => "{full_name} 您好，\n\n有人尝试使用此电邮地址注册 OtterNova，但此地址已有账户。您可以在此登录：\n\n{link}\n\n如果您忘记了密码，请在登录页面使用“忘记密码”。如果这不是您本人，可以忽略此电邮。",
    ],
    'business_invitation' => [
        'subject' => '{inviter} 邀请您加入 OtterNova 上的 {business_name}',
        'body'    => "您好，\n\n{inviter} 邀请您以 {role} 身份在 OtterNova 上的 {business_name} 工作。请打开以下链接，使用此电邮地址创建账户：\n\n{link}\n\n此链接将在 {days} 天后过期。如果您没有预期收到此邀请，可以忽略此电邮。",
    ],
    'password_reset'     => [
        'subject' => '重置您的 OtterNova 密码',
        'body'    => "{full_name} 您好，\n\n我们收到了重置您 OtterNova 账户密码的请求。请打开以下链接设置新密码：\n\n{link}\n\n此链接将在 {minutes} 分钟后过期，且只能使用一次。如果您没有提出此请求，可以忽略此电邮：您的密码不会改变。",
    ],
    'order_cancelled'    => [
        'subject' => '您在 {business_name} 的订单 {order_number} 已取消',
        'body'    => "{first_name} 您好，\n\n您在 {business_name} 的订单 {order_number} 已被取消。此订单尚未付款，因此无需退款。\n\n您可以在此查看订单：\n\n{link}\n\n如有任何疑问，请联系 {business_name}。",
    ],
    'order_cancelled_paid' => [
        'subject' => '您在 {business_name} 的订单 {order_number} 已取消',
        'body'    => "{first_name} 您好，\n\n您在 {business_name} 的订单 {order_number} 已被取消。您已为此支付 {amount}：{business_name} 将退款给您，并可能与您联系商讨退款方式。\n\n您可以在此查看订单：\n\n{link}\n\n如有任何疑问，请联系 {business_name}。",
    ],
    'order_rescheduled'  => [
        'subject' => '您在 {business_name} 的预约已改至 {to}',
        'body'    => "{first_name} 您好，\n\n您在 {business_name} 预约的 {service}（订单 {order_number}）已从 {from} 改至 {to}。\n\n您可以在此查看订单：\n\n{link}\n\n如果此时间不适合您，请联系 {business_name}。",
    ],
    'order_placed'       => [
        'subject'        => '您在 {business_name} 的订单 {order_number}',
        'body'           => "{first_name} 您好，\n\n感谢您在 {business_name} 下单。您的订单号是 {order_number}。\n\n{items}\n\n总计：{total}\n\n此订单尚未付款。付款方式：{method}\n{how}\n\n您可以在此查看订单、付款，并在付款后通知我们：\n\n{link}\n\n在付款之前，您的订单可能会被取消。",
        'promptpay'      => '请扫描订单页面上的 PromptPay 二维码，金额已自动填入。',
        'shipping'       => '配送：{0}',
        'shipping-label' => '配送',
        'no-instruction' => '{business_name} 会告诉您如何付款。',
    ],
    'order_placed_paid'  => [
        'subject' => '您在 {business_name} 的订单 {order_number}',
        'body'    => "{first_name} 您好，\n\n感谢您在 {business_name} 下单。您的订单号是 {order_number}。\n\n{items}\n\n总计：{total}，已通过 {method} 付款。\n\n您可以在此查看订单：\n\n{link}",
    ],
    'order_history_link' => [
        'subject' => '您在 {business_name} 的订单记录',
        'body'    => "您好，\n\n有人要求查看使用此电邮地址（{email}）在 {business_name} 下的订单。请打开以下链接查看：\n\n{link}\n\n此链接只能使用一次，有效期为 {minutes} 分钟。如果您没有提出此请求，可以忽略此电邮：没有任何内容被显示给任何人。",
    ],
];
