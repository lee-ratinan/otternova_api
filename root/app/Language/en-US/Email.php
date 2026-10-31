<?php
// Subject and body of each EmailService::TYPE_* email; {placeholders} come from the $data passed to send()
return [
    'login_otp' => [
        'subject' => 'Your OtterNova sign-in code',
        'body'    => "Hello {full_name},\n\nYour sign-in code is {otp}. It expires in {minutes} minutes.\n\nIf you did not try to sign in, please change your password.",
    ],
];
