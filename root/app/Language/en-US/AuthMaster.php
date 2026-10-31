<?php
return [
    'fields' => [
        'uid'              => 'UID',
        'email_address'    => 'Email Address',
        'telephone_number' => 'Telephone Number',
        'password_hash'    => 'Hashed Password',
        'password_expiry'  => 'Password Expiry Date',
        'full_name'        => 'Full Name',
        'otp_mode'         => 'OTP Mode',
        'account_status'   => 'Account Status',
        'account_type'     => 'Account Type',
        'admin_notes'      => 'Admin Notes',
    ],
    'enum'   => [
        'otp_mode'       => ['E' => 'Email', 'T' => 'SMS', 'X' => 'None'],
        'account_status' => ['P' => 'Pending', 'A' => 'Active', 'B' => 'Blocked', 'S' => 'Suspended'],
        'account_type'   => ['S' => 'Staff', 'C' => 'Client'],
    ],
];