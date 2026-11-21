<?php
return [
    'fields'             => [
        'store_name'             => '门店名称',
        'store_type'             => '门店类型',
        'store_timezone'         => '时区',
        'store_phone_number'     => '电话号码',
        'store_address_line_1'   => '地址第 1 行',
        'store_address_line_2'   => '地址第 2 行',
        'store_city'             => '城市',
        'store_subdivision_code' => '州 / 省',
        'store_postal_code'      => '邮政编码',
        'google_maps_url'        => 'Google 地图链接',
        'receipt_format'         => '收据打印机',
        'receipt_footer'         => '收据页脚',
    ],
    'enum'               => [
        'store_type'     => ['P' => '实体店', 'O' => '网店'],
        'receipt_format' => ['58mm' => '热敏 58mm', '80mm' => '热敏 80mm', 'A4' => 'A4 纸'],
    ],
    'invalidSubdivision' => '此州或省不在企业所在国家内。',
    'invalidTimezone'    => '此时区不在企业所在国家内。',
];
