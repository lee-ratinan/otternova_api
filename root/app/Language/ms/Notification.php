<?php
// The notifications' titles and messages, by event_type (see App\Libraries\Notifier::EVENTS); {placeholders} come
// from notification_master.message_params. Then the endpoints' own messages.
return [
    'payment' => [
        'slip_submitted' => [
            'title'   => 'Pembayaran untuk disemak',
            'message' => '{business} telah membayar {amount} untuk invois {invoice_no}. Sila semak slip dan luluskannya.',
        ],
        'approved' => [
            'title'   => 'Pembayaran diluluskan',
            'message' => 'Pembayaran anda sebanyak {amount} untuk invois {invoice_no} telah diluluskan. Terima kasih.',
        ],
        'rejected' => [
            'title'   => 'Pembayaran ditolak',
            'message' => 'Pembayaran anda untuk invois {invoice_no} telah ditolak: {reason}. Sila bayar semula.',
        ],
    ],

    'order' => [
        'cancelled' => [
            'title'   => 'Bayaran balik untuk pesanan yang dibatalkan',
            'message' => 'Pesanan {order_number} telah dibatalkan oleh {by}: {reason}. Sebanyak {amount} telah dibayar. Sila pulangkannya.',
        ],
        'booked' => [
            'title'   => 'Tempahan baharu: {service}',
            'message' => '{customer} menempah {service} dengan anda pada {when} di {store} (pesanan {order_number}). Pembayaran: {payment}.',
        ],
        'seats_booked' => [
            'title'   => '{seats} lagi telah diambil: {session}',
            'message' => '{customer} mengambil {seats} tempat dalam {session} pada {when} di {store}; {booked} daripada {capacity} kini telah diambil (pesanan {order_number}). Pembayaran: {payment}.',
        ],
        'payment_reported' => [
            'title'   => 'Pembayaran untuk disemak: {order_number}',
            'message' => '{customer} menyatakan telah membayar {amount} untuk pesanan {order_number} melalui {method}{reference}. Sila semak dan tandakannya sebagai diterima.',
        ],
        'cancelled_unpaid' => [
            'title'   => 'Pesanan dibatalkan',
            'message' => 'Pesanan {order_number} telah dibatalkan oleh {by}: {reason}. Ia belum dibayar.',
        ],
    ],

    // a booking's payment, for the people serving it
    'payment_state' => [
        'paid'     => 'telah dibayar',
        'at_store' => 'akan dibayar di kedai',
        'awaiting' => 'belum dibayar, jadi ia masih mungkin dibatalkan atau tidak hadir',
    ],

    'invalidRequest' => 'Permintaan tidak dapat dibaca. Sila muat semula halaman dan cuba lagi.',
    'invalidStatus'  => 'Sila pilih belum dibaca atau telah dibaca.',
    'notFound'       => 'Pemberitahuan ini tidak ditemui.',
    'loadFailed'     => 'Tidak dapat memuatkan pemberitahuan. Sila cuba lagi.',
    'saveFailed'     => 'Tidak dapat mengemas kini pemberitahuan. Sila cuba lagi.',
    'allRead'        => 'Semua pemberitahuan ditandakan telah dibaca.',
    'deleted'        => 'Pemberitahuan dipadam.',
    'readDeleted'    => 'Pemberitahuan yang telah dibaca dipadam: {0}.',
];
