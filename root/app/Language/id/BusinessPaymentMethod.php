<?php
return [
    'fields'              => [
        'business_id'    => 'Bisnis',
        'store_id'       => 'Toko',
        'method_code'    => 'Metode Pembayaran',
        'handler_code'   => 'Ditangani Oleh',
        'method_name'    => 'Nama',
        'instruction'    => 'Instruksi',
        'is_active'      => 'Aktif',
        'allow_shipping' => 'Untuk Pesanan yang Dikirim',
        'sort_order'     => 'Urutan',
    ],
    'enum'                => [
        'method_code'    => ['cash' => 'Tunai', 'bank_transfer' => 'Transfer Bank', 'promptpay' => 'PromptPay', 'other' => 'Lainnya'],
        'handler_code'   => ['instruction' => 'Hanya instruksi', 'promptpay_qr' => 'Kode QR PromptPay'],
        'is_active'      => ['Y' => 'Aktif', 'N' => 'Tidak Aktif'],
        'allow_shipping' => ['Y' => 'Bisa untuk pengiriman', 'N' => 'Hanya ambil di toko'],
    ],
    'invalidMethod'       => 'Silakan pilih metode pembayaran dari daftar.',
    'invalidHandler'      => 'Silakan pilih cara penanganan pembayaran ini dari daftar.',
    'handlerMismatch'     => 'Metode pembayaran ini tidak dapat ditangani dengan cara ini.',
    'instructionRequired' => 'Silakan beri tahu pelanggan cara membayar.',
    'invalidSettings'     => 'Silakan kirim pengaturan sebagai objek.',
    'invalidSetting'      => [
        'promptpay_id' => 'Silakan masukkan ID PromptPay: nomor ponsel (10 digit), nomor identitas nasional atau pajak (13 digit) atau ID e-wallet (15 digit).',
    ],
];
