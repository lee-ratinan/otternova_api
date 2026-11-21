<?php
return [
    'fields' => [
        'business_id'         => 'ビジネス',
        'client_id'           => 'クライアント',
        'client_status'       => 'ステータス',
        'client_role'         => '役割',
        'is_default_business' => 'デフォルトのビジネス',
        'start_date'          => '開始日',
        'termination_date'    => '退職日',
        'owner_notes'         => 'オーナーメモ',
    ],
    'enum'   => [
        'client_status'       => ['A' => '在籍中', 'T' => '退職済み'],
        'client_role'         => ['O' => 'オーナー', 'M' => 'マネージャー', 'E' => '従業員'],
        'is_default_business' => ['Y' => 'はい', 'N' => 'いいえ'],
    ],
];
