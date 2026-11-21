<?php
return [
    'fields' => [
        'auth_id'            => '帳號 ID',
        'legal_first_name'   => '法定名字',
        'legal_middle_name'  => '法定中間名',
        'legal_last_name'    => '法定姓氏',
        'display_name'       => '顯示名稱',
        'national_id_type'   => '身分證件類型',
        'national_id_number' => '身分證件號碼',
        'status_message'     => '狀態訊息',
        'status_emoji'       => '狀態表情符號',
        'gender'             => '性別',
        'date_of_birth'      => '出生日期',
        'nationality_code'   => '國籍',
        'preferred_language' => '偏好語言',
        'bio'                => '個人簡介',
        'marketing_opt_in'   => '接收行銷訊息',
        'internal_notes'     => '內部備註',
    ],
    'enum'   => [
        'national_id_type' => ['N' => '身分證', 'P' => '護照'],
        'gender'           => ['M' => '男', 'F' => '女', 'NB' => '非二元性別', 'X' => '其他', 'N' => '不願透露'],
    ],
];
