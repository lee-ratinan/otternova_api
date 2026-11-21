<?php
return [
    'fields' => [
        'uid'              => 'UID',
        'email_address'    => 'Alamat E-mel',
        'telephone_number' => 'Nombor Telefon',
        'password_hash'    => 'Kata Laluan Dicincang',
        'password_expiry'  => 'Tarikh Tamat Kata Laluan',
        'full_name'        => 'Nama Penuh',
        'avatar_path'      => 'Avatar',
        'otp_mode'         => 'Mod OTP',
        'account_status'   => 'Status Akaun',
        'account_type'     => 'Jenis Akaun',
        'admin_notes'      => 'Nota Pentadbir',
    ],
    'enum'   => [
        'otp_mode'       => ['E' => 'E-mel', 'T' => 'SMS', 'X' => 'Tiada'],
        'account_status' => ['P' => 'Belum Selesai', 'A' => 'Aktif', 'B' => 'Disekat', 'S' => 'Digantung'],
        'account_type'   => ['S' => 'Kakitangan', 'C' => 'Pelanggan'],
    ],
];
