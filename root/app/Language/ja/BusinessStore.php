<?php
return [
    'fields'             => [
        'store_name'             => '店舗名',
        'store_type'             => '店舗の種類',
        'store_timezone'         => 'タイムゾーン',
        'store_phone_number'     => '電話番号',
        'store_address_line_1'   => '住所1',
        'store_address_line_2'   => '住所2',
        'store_city'             => '市区町村',
        'store_subdivision_code' => '都道府県・州',
        'store_postal_code'      => '郵便番号',
        'google_maps_url'        => 'Googleマップのリンク',
        'receipt_format'         => 'レシートプリンター',
        'receipt_footer'         => 'レシートのフッター',
    ],
    'enum'               => [
        'store_type'     => ['P' => '実店舗', 'O' => 'オンラインストア'],
        'receipt_format' => ['58mm' => 'サーマル 58mm', '80mm' => 'サーマル 80mm', 'A4' => 'A4用紙'],
    ],
    'invalidSubdivision' => 'この都道府県・州はビジネスの国にありません。',
    'invalidTimezone'    => 'このタイムゾーンはビジネスの国にありません。',
];
