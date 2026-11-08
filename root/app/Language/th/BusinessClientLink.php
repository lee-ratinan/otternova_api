<?php
return [
    'fields' => [
        'business_id'         => 'ธุรกิจ',
        'client_id'           => 'ลูกค้า',
        'client_status'       => 'สถานะ',
        'client_role'         => 'บทบาท',
        'is_default_business' => 'ธุรกิจเริ่มต้น',
        'start_date'          => 'วันที่เริ่ม',
        'termination_date'    => 'วันที่สิ้นสุด',
        'owner_notes'         => 'บันทึกของเจ้าของ',
    ],
    'enum'   => [
        'client_status'       => ['A' => 'ใช้งานอยู่', 'T' => 'สิ้นสุดแล้ว'],
        'client_role'         => ['O' => 'เจ้าของ', 'M' => 'ผู้จัดการ', 'E' => 'พนักงาน'],
        'is_default_business' => ['Y' => 'ใช่', 'N' => 'ไม่ใช่'],
    ],
];
