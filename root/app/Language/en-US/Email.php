<?php
// Subject and body of each EmailService::TYPE_* email; {placeholders} come from the $data passed to send()
return [
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
];
