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
        'placed' => [
            'title'   => 'Pesanan baharu: {order_number}',
            'message' => '{customer} membuat pesanan {order_number} sebanyak {amount}. Sila sediakan produknya. Pembayaran: {payment}.',
        ],
        'rescheduled' => [
            'title'   => 'Tempahan dipindahkan: {service}',
            'message' => '{service} {customer} di {store} (pesanan {order_number}) telah dipindahkan dari {from} ke {to}.',
        ],
        'booking_cancelled' => [
            'title'   => 'Tempahan dibatalkan: {service}',
            'message' => '{service} {customer} pada {when} di {store} (pesanan {order_number}) telah dibatalkan: {reason}.',
        ],
        'payment_failed' => [
            'title'   => 'Pembayaran gagal: {order_number}',
            'message' => 'Pembayaran {amount} melalui {method} untuk pesanan {order_number} tidak berjaya: {reason}. Sila hubungi pelanggan.',
        ],
    ],

    'invoice' => [
        'issued' => [
            'title'   => 'Invois baharu: {invoice_no}',
            'message' => 'Invois {invoice_no} sebanyak {amount} untuk {business} telah dikeluarkan. Sila bayar sebelum {due}.',
        ],
    ],

    'subscription' => [
        'suspended' => [
            'title'   => 'Langganan digantung',
            'message' => 'Langganan {business} telah digantung. Sila bayar invois yang perlu dibayar, atau hubungi kami, untuk mengaktifkannya semula.',
        ],
        'expiring' => [
            'title'   => 'Langganan akan tamat',
            'message' => 'Langganan {business} yang digantung akan tamat pada {expires} dan invois yang belum dibayar akan dibatalkan. Sila bayar untuk meneruskannya.',
        ],
        'cancelled' => [
            'title'   => 'Langganan dibatalkan',
            'message' => 'Langganan {business} telah dibatalkan.',
        ],
    ],

    'withholding' => [
        'requested' => [
            'title'   => 'Cukai pegangan untuk disemak',
            'message' => '{business} memohon untuk memotong cukai pegangan di bawah {rule}. Sila semak permohonan itu.',
        ],
        'approved' => [
            'title'   => 'Cukai pegangan diluluskan',
            'message' => '{business} kini boleh memotong cukai pegangan di bawah {rule}. Ia akan dikira pada invois anda mulai sekarang.',
        ],
        'rejected' => [
            'title'   => 'Cukai pegangan tidak diluluskan',
            'message' => 'Permohonan cukai pegangan {business} tidak diluluskan. Sila hubungi kami jika ada sebarang pertanyaan.',
        ],
    ],

    'certificate' => [
        'submitted' => [
            'title'   => 'Sijil untuk disemak: {invoice_no}',
            'message' => '{business} telah menghantar sijil cukai pegangan sebanyak {amount} untuk invois {invoice_no}. Sila semak.',
        ],
        'accepted' => [
            'title'   => 'Sijil diterima',
            'message' => 'Sijil cukai pegangan untuk invois {invoice_no} telah diterima. Terima kasih.',
        ],
        'rejected' => [
            'title'   => 'Sijil ditolak',
            'message' => 'Sijil cukai pegangan untuk invois {invoice_no} telah ditolak: {reason}. Sila hantar semula.',
        ],
    ],

    'invitation' => [
        'accepted' => [
            'title'   => '{name} telah menyertai',
            'message' => '{name} menerima jemputan anda dan menyertai sebagai {role}.',
        ],
    ],

    'review' => [
        'submitted' => [
            'title'   => 'Ulasan baharu: {artifact}',
            'message' => '{customer} memberi {artifact} {rating} bintang. Sila luluskan atau sembunyikan ulasan itu.',
        ],
    ],

    // order.payment_failed with no reason recorded
    'no_reason' => 'tiada sebab diberikan',

    // a person's role in a business, for invitation.accepted
    'client_role' => [
        'O' => 'pemilik',
        'M' => 'pengurus',
        'E' => 'pekerja',
    ],

    // a booking's payment, for the people serving it
    'payment_state' => [
        'paid'     => 'telah dibayar',
        'at_store' => 'akan dibayar di kedai',
        'awaiting' => 'belum dibayar, jadi ia masih mungkin dibatalkan atau tidak hadir',
    ],

    'moderation' => [
        'flagged' => [
            'title'   => 'Otternova menanda {subject}',
            'message' => 'Sebab: {reason}. {message} Sila buat perubahan dan simpan; Otternova akan menyemaknya semula.',
        ],
        'blocked' => [
            'title'   => '{subject} disekat',
            'message' => 'Otternova telah menurunkan {subject} dari OtterNovaMart. Sebab: {reason}. {message} Sila betulkan dan simpan; ia boleh dijual semula selepas Otternova membuka sekatannya.',
        ],
        'unblocked' => [
            'title'   => 'Sekatan {subject} dibuka',
            'message' => 'Otternova telah membuka sekatan {subject}; ia kembali seperti sebelum disekat. {message}',
        ],
        'removed' => [
            'title'   => '{subject} telah dialih keluar',
            'message' => 'Otternova telah mengalih keluar {subject} secara kekal. Sebab: {reason}. {message} Pesanan sedia ada masih dihormati.',
        ],
    ],

    // why Otternova flagged, blocked or removed something (ModerationEventModel::REASONS), for moderation.*
    'moderation_reason' => [
        'PROHIBITED'            => 'Barang larangan atau terhad',
        'MISLEADING'            => 'Maklumat mengelirukan atau tidak tepat',
        'INTELLECTUAL_PROPERTY' => 'Isu harta intelek',
        'AUDIENCE'              => 'Tidak sesuai untuk khalayaknya',
        'BUSINESS_TYPE'         => 'Tidak sepadan dengan jenis perniagaan',
        'PRICING'               => 'Isu harga',
        'OTHER'                 => 'Lain-lain',
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
