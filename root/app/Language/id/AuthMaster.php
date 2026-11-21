<?php
return [
    'fields' => [
        'uid'              => 'UID',
        'email_address'    => 'Alamat Email',
        'telephone_number' => 'Nomor Telepon',
        'password_hash'    => 'Kata Sandi Ter-hash',
        'password_expiry'  => 'Tanggal Kedaluwarsa Kata Sandi',
        'full_name'        => 'Nama Lengkap',
        'avatar_path'      => 'Avatar',
        'otp_mode'         => 'Mode OTP',
        'account_status'   => 'Status Akun',
        'account_type'     => 'Jenis Akun',
        'admin_notes'      => 'Catatan Admin',
    ],
    'enum'   => [
        'otp_mode'       => ['E' => 'Email', 'T' => 'SMS', 'X' => 'Tidak Ada'],
        'account_status' => ['P' => 'Menunggu', 'A' => 'Aktif', 'B' => 'Diblokir', 'S' => 'Ditangguhkan'],
        'account_type'   => ['S' => 'Staf', 'C' => 'Klien'],
    ],
];
