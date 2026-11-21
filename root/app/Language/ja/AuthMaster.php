<?php
return [
    'fields' => [
        'uid'              => 'UID',
        'email_address'    => 'メールアドレス',
        'telephone_number' => '電話番号',
        'password_hash'    => 'ハッシュ化パスワード',
        'password_expiry'  => 'パスワード有効期限',
        'full_name'        => '氏名',
        'avatar_path'      => 'アバター',
        'otp_mode'         => 'OTP方式',
        'account_status'   => 'アカウントステータス',
        'account_type'     => 'アカウント種別',
        'admin_notes'      => '管理者メモ',
    ],
    'enum'   => [
        'otp_mode'       => ['E' => 'メール', 'T' => 'SMS', 'X' => 'なし'],
        'account_status' => ['P' => '保留中', 'A' => '有効', 'B' => 'ブロック中', 'S' => '停止中'],
        'account_type'   => ['S' => 'スタッフ', 'C' => 'クライアント'],
    ],
];
