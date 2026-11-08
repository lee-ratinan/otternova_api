<?php
return [
    'fields' => [
        'uid'              => 'UID',
        'email_address'    => 'อีเมล',
        'telephone_number' => 'หมายเลขโทรศัพท์',
        'password_hash'    => 'รหัสผ่าน (แฮช)',
        'password_expiry'  => 'วันหมดอายุรหัสผ่าน',
        'full_name'        => 'ชื่อ-นามสกุล',
        'avatar_path'      => 'รูปประจำตัว',
        'otp_mode'         => 'วิธีรับรหัส OTP',
        'account_status'   => 'สถานะบัญชี',
        'account_type'     => 'ประเภทบัญชี',
        'admin_notes'      => 'บันทึกของผู้ดูแลระบบ',
    ],
    'enum'   => [
        'otp_mode'       => ['E' => 'อีเมล', 'T' => 'SMS', 'X' => 'ไม่ใช้'],
        'account_status' => ['P' => 'รอดำเนินการ', 'A' => 'ใช้งานอยู่', 'B' => 'ถูกบล็อก', 'S' => 'ถูกระงับ'],
        'account_type'   => ['S' => 'พนักงาน', 'C' => 'ลูกค้า'],
    ],
];
