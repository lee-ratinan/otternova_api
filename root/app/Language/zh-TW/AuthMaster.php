<?php
return [
    'fields' => [
        'uid'              => 'UID',
        'email_address'    => '電子郵件地址',
        'telephone_number' => '電話號碼',
        'password_hash'    => '密碼雜湊',
        'password_expiry'  => '密碼到期日',
        'full_name'        => '全名',
        'avatar_path'      => '大頭貼',
        'otp_mode'         => 'OTP 方式',
        'account_status'   => '帳號狀態',
        'account_type'     => '帳號類型',
        'admin_notes'      => '管理員備註',
    ],
    'enum'   => [
        'otp_mode'       => ['E' => '電子郵件', 'T' => '簡訊', 'X' => '無'],
        'account_status' => ['P' => '待處理', 'A' => '啟用', 'B' => '已封鎖', 'S' => '已停權'],
        'account_type'   => ['S' => '員工', 'C' => '客戶'],
    ],
];
