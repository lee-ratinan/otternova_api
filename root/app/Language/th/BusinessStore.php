<?php
return [
    'fields'             => [
        'store_name'             => 'ชื่อร้านค้า',
        'store_type'             => 'ประเภทร้านค้า',
        'store_timezone'         => 'เขตเวลา',
        'store_phone_number'     => 'หมายเลขโทรศัพท์',
        'store_address_line_1'   => 'ที่อยู่บรรทัดที่ 1',
        'store_address_line_2'   => 'ที่อยู่บรรทัดที่ 2',
        'store_city'             => 'เมือง / อำเภอ',
        'store_subdivision_code' => 'จังหวัด / รัฐ',
        'store_postal_code'      => 'รหัสไปรษณีย์',
        'receipt_format'         => 'เครื่องพิมพ์ใบเสร็จ',
        'receipt_footer'         => 'ข้อความท้ายใบเสร็จ',
    ],
    'enum'               => [
        'store_type'     => ['P' => 'หน้าร้าน', 'O' => 'ร้านค้าออนไลน์'],
        'receipt_format' => ['58mm' => 'กระดาษความร้อน 58 มม.', '80mm' => 'กระดาษความร้อน 80 มม.', 'A4' => 'กระดาษ A4'],
    ],
    'invalidSubdivision' => 'รัฐหรือจังหวัดนี้ไม่ได้อยู่ในประเทศของธุรกิจ',
    'invalidTimezone'    => 'เขตเวลานี้ไม่ได้อยู่ในประเทศของธุรกิจ',
];
