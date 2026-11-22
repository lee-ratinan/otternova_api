<?php
return [
    'fields'              => [
        'business_id'    => 'Perniagaan',
        'store_id'       => 'Kedai',
        'method_code'    => 'Kaedah Pembayaran',
        'handler_code'   => 'Dikendalikan Oleh',
        'method_name'    => 'Nama',
        'instruction'    => 'Arahan',
        'is_active'      => 'Aktif',
        'allow_shipping' => 'Untuk Pesanan yang Dihantar',
        'sort_order'     => 'Susunan',
    ],
    'enum'                => [
        'method_code'    => ['cash' => 'Tunai', 'bank_transfer' => 'Pindahan Bank', 'promptpay' => 'PromptPay', 'other' => 'Lain-lain'],
        'handler_code'   => ['instruction' => 'Arahan sahaja', 'promptpay_qr' => 'Kod QR PromptPay'],
        'is_active'      => ['Y' => 'Aktif', 'N' => 'Tidak Aktif'],
        'allow_shipping' => ['Y' => 'Boleh untuk penghantaran', 'N' => 'Ambil di kedai sahaja'],
    ],
    'invalidMethod'       => 'Sila pilih kaedah pembayaran daripada senarai.',
    'invalidHandler'      => 'Sila pilih cara pembayaran ini dikendalikan daripada senarai.',
    'handlerMismatch'     => 'Kaedah pembayaran ini tidak boleh dikendalikan dengan cara ini.',
    'instructionRequired' => 'Sila beritahu pelanggan cara untuk membayar.',
    'invalidSettings'     => 'Sila hantar tetapan sebagai objek.',
    'invalidSetting'      => [
        'promptpay_id' => 'Sila masukkan ID PromptPay: nombor telefon bimbit (10 digit), nombor pengenalan kebangsaan atau cukai (13 digit) atau ID e-dompet (15 digit).',
    ],
];
