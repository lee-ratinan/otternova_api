<?php
return [
    'fields'             => [
        'store_name'             => 'Nama Kedai',
        'store_type'             => 'Jenis Kedai',
        'store_timezone'         => 'Zon Waktu',
        'store_phone_number'     => 'Nombor Telefon',
        'store_address_line_1'   => 'Alamat Baris 1',
        'store_address_line_2'   => 'Alamat Baris 2',
        'store_city'             => 'Bandar',
        'store_subdivision_code' => 'Negeri / Wilayah',
        'store_postal_code'      => 'Poskod',
        'google_maps_url'        => 'Pautan Google Maps',
        'receipt_format'         => 'Pencetak Resit',
        'receipt_footer'         => 'Nota Kaki Resit',
    ],
    'enum'               => [
        'store_type'     => ['P' => 'Kedai Fizikal', 'O' => 'Kedai Dalam Talian'],
        'receipt_format' => ['58mm' => 'Terma 58mm', '80mm' => 'Terma 80mm', 'A4' => 'Kertas A4'],
    ],
    'invalidSubdivision' => 'Negeri atau wilayah ini tiada dalam negara perniagaan.',
    'invalidTimezone'    => 'Zon waktu ini tiada dalam negara perniagaan.',
];
