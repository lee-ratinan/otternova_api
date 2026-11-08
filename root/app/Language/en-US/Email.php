<?php
// Subject and body of each EmailService::TYPE_* email; {placeholders} come from the $data passed to send()
return [
    // Text around the content in Views/email/template-v1.php; company_address is left out of the footer while it is empty
    'template' => [
        'closing_message'     => 'If you have any questions, please contact our support team.',
        'best_regards'        => 'Best regards,',
        'team_name'           => 'OtterNova Team',
        'company_name'        => 'OtterNova',
        'company_address'     => '##/## Thanon Song Prapha, Khwaeng Don Mueang, Khet Don Mueang, Bangkok, 10210 THAILAND',
        'contact_support'     => 'Contact Support',
        'privacy_policy'      => 'Privacy Policy',
        'all_rights_reserved' => 'All rights reserved.',
    ],
    'login_otp' => [
        'subject' => 'Your OtterNova sign-in code',
        'body'    => "Hello {full_name},\n\nYour sign-in code is {otp}. It expires in {minutes} minutes.\n\nIf you did not try to sign in, please change your password.",
    ],
    'account_activation' => [
        'subject' => 'Activate your OtterNova account',
        'body'    => "Hello {full_name},\n\nThank you for signing up for OtterNova. Please open this link to activate your account:\n\n{link}\n\nThe link expires in {hours} hours. If you did not sign up, you can ignore this email.",
    ],
    'account_exists'     => [
        'subject' => 'Your OtterNova account',
        'body'    => "Hello {full_name},\n\nSomeone tried to sign up for OtterNova with this email address, but it already has an account. You can sign in here:\n\n{link}\n\nIf you have forgotten your password, use \"Forget Password\" on the sign-in page. If it was not you, you can ignore this email.",
    ],
    'business_invitation' => [
        'subject' => '{inviter} invited you to {business_name} on OtterNova',
        'body'    => "Hello,\n\n{inviter} invited you to work at {business_name} on OtterNova as {role}. Please open this link to create your account with this email address:\n\n{link}\n\nThe link expires in {days} days. If you were not expecting this, you can ignore this email.",
    ],
    'password_reset'     => [
        'subject' => 'Reset your OtterNova password',
        'body'    => "Hello {full_name},\n\nWe received a request to reset the password of your OtterNova account. Please open this link to choose a new password:\n\n{link}\n\nThe link expires in {minutes} minutes and can be used once. If you did not ask for this, you can ignore this email: your password stays the same.",
    ],
];
