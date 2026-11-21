<?php
return [
    'fields' => [
        'uid'              => 'UID',
        'email_address'    => '电邮地址',
        'telephone_number' => '电话号码',
        'password_hash'    => '密码哈希',
        'password_expiry'  => '密码到期日',
        'full_name'        => '全名',
        'avatar_path'      => '头像',
        'otp_mode'         => 'OTP 方式',
        'account_status'   => '账户状态',
        'account_type'     => '账户类型',
        'admin_notes'      => '管理员备注',
    ],
    'enum'   => [
        'otp_mode'       => ['E' => '电邮', 'T' => '短信', 'X' => '无'],
        'account_status' => ['P' => '待处理', 'A' => '有效', 'B' => '已封锁', 'S' => '已暂停'],
        'account_type'   => ['S' => '员工', 'C' => '客户'],
    ],
];
