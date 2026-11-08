<?php
return [
    'fields' => [
        'auth_id'            => 'รหัสบัญชี',
        'legal_first_name'   => 'ชื่อจริง (ตามเอกสาร)',
        'legal_middle_name'  => 'ชื่อกลาง (ตามเอกสาร)',
        'legal_last_name'    => 'นามสกุล (ตามเอกสาร)',
        'display_name'       => 'ชื่อที่แสดง',
        'national_id_type'   => 'ประเภทเอกสารยืนยันตัวตน',
        'national_id_number' => 'เลขที่เอกสารยืนยันตัวตน',
        'status_message'     => 'ข้อความสถานะ',
        'status_emoji'       => 'อีโมจิสถานะ',
        'gender'             => 'เพศ',
        'date_of_birth'      => 'วันเกิด',
        'nationality_code'   => 'สัญชาติ',
        'preferred_language' => 'ภาษาที่ต้องการ',
        'bio'                => 'แนะนำตัว',
        'marketing_opt_in'   => 'รับข่าวสารการตลาด',
        'internal_notes'     => 'บันทึกภายใน',
    ],
    'enum'   => [
        'national_id_type' => ['N' => 'บัตรประจำตัวประชาชน', 'P' => 'หนังสือเดินทาง'],
        'gender'           => ['M' => 'ชาย', 'F' => 'หญิง', 'NB' => 'นอนไบนารี', 'X' => 'อื่น ๆ', 'N' => 'ไม่ระบุ'],
    ],
];
