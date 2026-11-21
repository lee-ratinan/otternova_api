<?php
return [
    'fields'             => [
        'store_name'             => 'Nama Toko',
        'store_type'             => 'Jenis Toko',
        'store_timezone'         => 'Zona Waktu',
        'store_phone_number'     => 'Nomor Telepon',
        'store_address_line_1'   => 'Alamat Baris 1',
        'store_address_line_2'   => 'Alamat Baris 2',
        'store_city'             => 'Kota',
        'store_subdivision_code' => 'Provinsi / Negara Bagian',
        'store_postal_code'      => 'Kode Pos',
        'google_maps_url'        => 'Tautan Google Maps',
        'receipt_format'         => 'Printer Struk',
        'receipt_footer'         => 'Catatan Kaki Struk',
    ],
    'enum'               => [
        'store_type'     => ['P' => 'Toko Fisik', 'O' => 'Toko Online'],
        'receipt_format' => ['58mm' => 'Termal 58mm', '80mm' => 'Termal 80mm', 'A4' => 'Kertas A4'],
    ],
    'invalidSubdivision' => 'Provinsi atau negara bagian ini tidak berada di negara bisnis.',
    'invalidTimezone'    => 'Zona waktu ini tidak berada di negara bisnis.',
];
