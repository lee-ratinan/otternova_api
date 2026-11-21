<?php
return [
    'fields'             => [
        'business_id'     => 'Perniagaan',
        'discount_code'   => 'Kod',
        'description'     => 'Penerangan',
        'discount_type'   => 'Jenis Diskaun',
        'discount_value'  => 'Diskaun',
        'applies_to'      => 'Terpakai Kepada',
        'min_subtotal'    => 'Subjumlah Minimum',
        'max_redemptions' => 'Penggunaan Maksimum',
        'valid_from'      => 'Sah Dari',
        'expires_at'      => 'Tamat Pada',
        'is_active'       => 'Aktif',
    ],
    'invalidCode'        => 'Sila masukkan kod 3 hingga 30 aksara terdiri daripada huruf, digit, sengkang dan garis bawah, bermula dengan huruf atau digit.',
    'invalidValue'       => 'Sila masukkan diskaun melebihi sifar, dengan paling banyak 2 tempat perpuluhan.',
    'percentTooHigh'     => 'Diskaun peratusan maksimum 100.',
    'invalidAmount'      => 'Sila masukkan jumlah, dengan paling banyak 2 tempat perpuluhan.',
    'invalidDatetime'    => 'Sila masukkan tarikh dan masa.',
    'expiresBeforeValid' => 'Diskaun mesti tamat selepas ia mula sah.',
    'belowRedeemed'      => 'Diskaun ini telah digunakan {0} kali; had maksimum tidak boleh lebih rendah.',
];
