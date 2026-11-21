<?php
return [
    'fields' => [
        'business_id'         => '企业',
        'client_id'           => '客户',
        'client_status'       => '状态',
        'client_role'         => '角色',
        'is_default_business' => '默认企业',
        'start_date'          => '开始日期',
        'termination_date'    => '离职日期',
        'owner_notes'         => '业主备注',
    ],
    'enum'   => [
        'client_status'       => ['A' => '在职', 'T' => '已离职'],
        'client_role'         => ['O' => '业主', 'M' => '经理', 'E' => '员工'],
        'is_default_business' => ['Y' => '是', 'N' => '否'],
    ],
];
