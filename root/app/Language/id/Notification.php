<?php
// The notifications' titles and messages, by event_type (see App\Libraries\Notifier::EVENTS); {placeholders} come
// from notification_master.message_params. Then the endpoints' own messages.
return [
    'payment' => [
        'slip_submitted' => [
            'title'   => 'Pembayaran perlu diperiksa',
            'message' => '{business} membayar {amount} untuk tagihan {invoice_no}. Silakan periksa bukti pembayaran dan setujui.',
        ],
        'approved' => [
            'title'   => 'Pembayaran disetujui',
            'message' => 'Pembayaran Anda sebesar {amount} untuk tagihan {invoice_no} telah disetujui. Terima kasih.',
        ],
        'rejected' => [
            'title'   => 'Pembayaran ditolak',
            'message' => 'Pembayaran Anda untuk tagihan {invoice_no} ditolak: {reason}. Silakan bayar kembali.',
        ],
    ],

    'order' => [
        'cancelled' => [
            'title'   => 'Pengembalian dana untuk pesanan yang dibatalkan',
            'message' => 'Pesanan {order_number} dibatalkan oleh {by}: {reason}. Sebesar {amount} telah dibayar. Silakan kembalikan dananya.',
        ],
        'booked' => [
            'title'   => 'Pemesanan baru: {service}',
            'message' => '{customer} memesan {service} dengan Anda pada {when} di {store} (pesanan {order_number}). Pembayaran: {payment}.',
        ],
        'seats_booked' => [
            'title'   => '{seats} tempat lagi terisi: {session}',
            'message' => '{customer} mengambil {seats} tempat di {session} pada {when} di {store}; kini {booked} dari {capacity} telah terisi (pesanan {order_number}). Pembayaran: {payment}.',
        ],
        'payment_reported' => [
            'title'   => 'Pembayaran perlu diperiksa: {order_number}',
            'message' => '{customer} menyatakan telah membayar {amount} untuk pesanan {order_number} melalui {method}{reference}. Silakan periksa dan tandai sebagai diterima.',
        ],
        'cancelled_unpaid' => [
            'title'   => 'Pesanan dibatalkan',
            'message' => 'Pesanan {order_number} dibatalkan oleh {by}: {reason}. Pesanan ini belum dibayar.',
        ],
    ],

    // a booking's payment, for the people serving it
    'payment_state' => [
        'paid'     => 'sudah dibayar',
        'at_store' => 'dibayar di toko',
        'awaiting' => 'belum dibayar, jadi masih dapat dibatalkan atau tidak datang',
    ],

    'invalidRequest' => 'Permintaan tidak dapat dibaca. Silakan muat ulang halaman dan coba lagi.',
    'invalidStatus'  => 'Silakan pilih belum dibaca atau sudah dibaca.',
    'notFound'       => 'Notifikasi ini tidak ditemukan.',
    'loadFailed'     => 'Tidak dapat memuat notifikasi. Silakan coba lagi.',
    'saveFailed'     => 'Tidak dapat memperbarui notifikasi. Silakan coba lagi.',
    'allRead'        => 'Semua notifikasi ditandai sudah dibaca.',
    'deleted'        => 'Notifikasi dihapus.',
    'readDeleted'    => 'Notifikasi yang sudah dibaca dihapus: {0}.',
];
