<?php
return [
    'fields' => [
        'auth_id'            => '账户 ID',
        'legal_first_name'   => '法定名字',
        'legal_middle_name'  => '法定中间名',
        'legal_last_name'    => '法定姓氏',
        'display_name'       => '显示名称',
        'national_id_type'   => '身份证件类型',
        'national_id_number' => '身份证件号码',
        'status_message'     => '状态信息',
        'status_emoji'       => '状态表情',
        'gender'             => '性别',
        'date_of_birth'      => '出生日期',
        'nationality_code'   => '国籍',
        'preferred_language' => '首选语言',
        'bio'                => '个人简介',
        'marketing_opt_in'   => '接收营销信息',
        'internal_notes'     => '内部备注',
    ],
    'enum'   => [
        'national_id_type' => ['N' => '身份证', 'P' => '护照'],
        'gender'           => ['M' => '男', 'F' => '女', 'NB' => '非二元性别', 'X' => '其他', 'N' => '不愿透露'],
    ],
];
