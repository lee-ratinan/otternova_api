<?php
return [
    'fields'              => [
        'business_id'  => 'ธุรกิจ',
        'store_id'     => 'ร้านค้า',
        'method_code'  => 'ช่องทางชำระเงิน',
        'handler_code' => 'วิธีดำเนินการ',
        'method_name'  => 'ชื่อ',
        'instruction'  => 'คำแนะนำ',
        'is_active'    => 'เปิดใช้งาน',
        'sort_order'   => 'ลำดับ',
    ],
    'enum'                => [
        'method_code'  => ['cash' => 'เงินสด', 'bank_transfer' => 'โอนเงินผ่านธนาคาร', 'promptpay' => 'พร้อมเพย์', 'other' => 'อื่น ๆ'],
        'handler_code' => ['instruction' => 'แสดงคำแนะนำเท่านั้น', 'promptpay_qr' => 'คิวอาร์โค้ดพร้อมเพย์'],
        'is_active'    => ['Y' => 'เปิดใช้งาน', 'N' => 'ปิดใช้งาน'],
    ],
    'invalidMethod'       => 'กรุณาเลือกช่องทางชำระเงินจากรายการ',
    'invalidHandler'      => 'กรุณาเลือกวิธีดำเนินการจากรายการ',
    'handlerMismatch'     => 'ช่องทางชำระเงินนี้ใช้วิธีดำเนินการนี้ไม่ได้',
    'instructionRequired' => 'กรุณาระบุวิธีชำระเงินให้ลูกค้า',
    'invalidSettings'     => 'กรุณาส่งการตั้งค่าเป็นออบเจกต์',
    'invalidSetting'      => [
        'promptpay_id' => 'กรุณากรอกพร้อมเพย์: เบอร์มือถือ (10 หลัก) เลขประจำตัวประชาชนหรือเลขผู้เสียภาษี (13 หลัก) หรือ e-Wallet ID (15 หลัก)',
    ],
];
