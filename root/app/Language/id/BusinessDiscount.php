<?php
return [
    'fields'             => [
        'business_id'     => 'Bisnis',
        'discount_code'   => 'Kode',
        'description'     => 'Deskripsi',
        'discount_type'   => 'Jenis Diskon',
        'discount_value'  => 'Diskon',
        'applies_to'      => 'Berlaku Untuk',
        'min_subtotal'    => 'Subtotal Minimum',
        'max_redemptions' => 'Penggunaan Maksimum',
        'valid_from'      => 'Berlaku Mulai',
        'expires_at'      => 'Berakhir Pada',
        'is_active'       => 'Aktif',
    ],
    'invalidCode'        => 'Silakan masukkan kode 3 hingga 30 karakter berupa huruf, angka, tanda hubung dan garis bawah, diawali dengan huruf atau angka.',
    'invalidValue'       => 'Silakan masukkan diskon lebih dari nol, dengan paling banyak 2 desimal.',
    'percentTooHigh'     => 'Diskon persentase maksimal 100.',
    'invalidAmount'      => 'Silakan masukkan jumlah, dengan paling banyak 2 desimal.',
    'invalidDatetime'    => 'Silakan masukkan tanggal dan waktu.',
    'expiresBeforeValid' => 'Diskon harus berakhir setelah mulai berlaku.',
    'belowRedeemed'      => 'Diskon ini sudah digunakan {0} kali; batas maksimum tidak boleh lebih rendah.',
];
