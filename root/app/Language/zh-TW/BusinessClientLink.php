<?php
return [
    'fields' => [
        'business_id'         => '商家',
        'client_id'           => '客戶',
        'client_status'       => '狀態',
        'client_role'         => '角色',
        'is_default_business' => '預設商家',
        'start_date'          => '開始日期',
        'termination_date'    => '離職日期',
        'owner_notes'         => '負責人備註',
    ],
    'enum'   => [
        'client_status'       => ['A' => '在職', 'T' => '已離職'],
        'client_role'         => ['O' => '負責人', 'M' => '經理', 'E' => '員工'],
        'is_default_business' => ['Y' => '是', 'N' => '否'],
    ],
];
