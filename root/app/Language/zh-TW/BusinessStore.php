<?php
return [
    'fields'             => [
        'store_name'             => '門市名稱',
        'store_type'             => '門市類型',
        'store_timezone'         => '時區',
        'store_phone_number'     => '電話號碼',
        'store_address_line_1'   => '地址第 1 行',
        'store_address_line_2'   => '地址第 2 行',
        'store_city'             => '城市',
        'store_subdivision_code' => '州 / 省',
        'store_postal_code'      => '郵遞區號',
        'google_maps_url'        => 'Google 地圖連結',
        'receipt_format'         => '收據印表機',
        'receipt_footer'         => '收據頁尾',
    ],
    'enum'               => [
        'store_type'     => ['P' => '實體門市', 'O' => '網路商店'],
        'receipt_format' => ['58mm' => '感熱 58mm', '80mm' => '感熱 80mm', 'A4' => 'A4 紙'],
    ],
    'invalidSubdivision' => '此州或省不在商家所在國家內。',
    'invalidTimezone'    => '此時區不在商家所在國家內。',
];
