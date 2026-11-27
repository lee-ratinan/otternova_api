<?php

/** The Admin app's review and moderation (App\Controllers\ModerationAdmin) */
return [
    'invalidRequest'   => 'Permintaan tidak dapat dibaca. Silakan muat ulang halaman dan coba lagi.',
    'invalidFields'    => 'Silakan periksa kolom yang ditandai.',
    'invalidSubject'   => 'Pilih bisnis, produk atau layanan, atau varian.',
    'invalidAction'    => 'Tindakan ini tidak dapat dilakukan padanya.',
    'invalidReason'    => 'Pilih salah satu alasan.',
    'reasonRequired'   => 'Silakan pilih alasan.',
    'messageRequired'  => 'Silakan beri tahu bisnis apa yang harus diperbaiki.',
    'tooLong'          => 'Maksimal {0} karakter.',
    'businessNotFound' => 'Bisnis tidak ditemukan.',
    'artifactNotFound' => 'Produk atau layanan tidak ditemukan.',
    'variantNotFound'  => 'Varian tidak ditemukan.',
    'removedAlready'   => 'Sudah dihapus permanen; tidak ada lagi yang dapat dilakukan.',
    'blockedAlready'   => 'Sudah diblokir.',
    'notBlocked'       => 'Tidak sedang diblokir.',
    'unblockFirst'     => 'Sedang diblokir: buka blokirnya untuk menyetujuinya.',
    'loadFailed'       => 'Riwayat moderasi tidak dapat dimuat. Silakan coba lagi.',
    'saveFailed'       => 'Tidak dapat menyimpan. Silakan coba lagi.',
    'done'               => [
        'AP' => 'Disetujui.',
        'FL' => 'Ditandai; bisnis telah diminta memperbaikinya.',
        'BL' => 'Diblokir; tidak tampil di marketplace.',
        'UB' => 'Blokir dibuka dan disetujui.',
        'RM' => 'Dihapus permanen.',
    ],
];
