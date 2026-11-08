<?php
return [
    'fields' => [
        'account_name'  => 'ชื่อบัญชี',
        'account_type'  => 'ประเภทบัญชี',
        'currency_code' => 'สกุลเงิน',
        'account_class' => 'หมวดบัญชี',
    ],
    'enum'   => [
        'account_type'  => ['I' => 'ภายใน', 'X' => 'ภายนอก'],
        'account_class' => ['A' => 'สินทรัพย์', 'L' => 'หนี้สิน', 'E' => 'ส่วนของเจ้าของ', 'R' => 'รายได้', 'X' => 'ค่าใช้จ่าย'],
    ],
];
