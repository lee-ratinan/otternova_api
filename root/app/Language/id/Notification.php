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
        'placed' => [
            'title'   => 'Pesanan baru: {order_number}',
            'message' => '{customer} membuat pesanan {order_number} sebesar {amount}. Silakan siapkan produknya. Pembayaran: {payment}.',
        ],
        'rescheduled' => [
            'title'   => 'Pemesanan dipindah: {service}',
            'message' => '{service} milik {customer} di {store} (pesanan {order_number}) dipindah dari {from} ke {to}.',
        ],
        'booking_cancelled' => [
            'title'   => 'Pemesanan dibatalkan: {service}',
            'message' => '{service} milik {customer} pada {when} di {store} (pesanan {order_number}) dibatalkan: {reason}.',
        ],
        'payment_failed' => [
            'title'   => 'Pembayaran gagal: {order_number}',
            'message' => 'Pembayaran {amount} melalui {method} untuk pesanan {order_number} tidak berhasil: {reason}. Silakan hubungi pelanggan.',
        ],
    ],

    'invoice' => [
        'issued' => [
            'title'   => 'Tagihan baru: {invoice_no}',
            'message' => 'Tagihan {invoice_no} sebesar {amount} untuk {business} telah diterbitkan. Silakan bayar sebelum {due}.',
        ],
    ],

    'subscription' => [
        'suspended' => [
            'title'   => 'Langganan ditangguhkan',
            'message' => 'Langganan {business} telah ditangguhkan. Silakan bayar tagihan yang jatuh tempo, atau hubungi kami, untuk mengaktifkannya kembali.',
        ],
        'expiring' => [
            'title'   => 'Langganan akan segera berakhir',
            'message' => 'Langganan {business} yang ditangguhkan akan berakhir pada {expires} dan tagihan yang belum dibayar akan dibatalkan. Silakan bayar untuk melanjutkannya.',
        ],
        'cancelled' => [
            'title'   => 'Langganan dibatalkan',
            'message' => 'Langganan {business} telah dibatalkan.',
        ],
    ],

    'withholding' => [
        'requested' => [
            'title'   => 'Pemotongan pajak perlu ditinjau',
            'message' => '{business} mengajukan pemotongan pajak berdasarkan {rule}. Silakan tinjau pengajuannya.',
        ],
        'approved' => [
            'title'   => 'Pemotongan pajak disetujui',
            'message' => '{business} sekarang dapat memotong pajak berdasarkan {rule}. Pajak akan dihitung pada tagihan Anda mulai sekarang.',
        ],
        'rejected' => [
            'title'   => 'Pemotongan pajak ditolak',
            'message' => 'Pengajuan pemotongan pajak {business} ditolak. Silakan hubungi kami jika ada pertanyaan.',
        ],
    ],

    'certificate' => [
        'submitted' => [
            'title'   => 'Bukti potong perlu diperiksa: {invoice_no}',
            'message' => '{business} mengirim bukti potong pajak sebesar {amount} untuk tagihan {invoice_no}. Silakan periksa.',
        ],
        'accepted' => [
            'title'   => 'Bukti potong diterima',
            'message' => 'Bukti potong pajak untuk tagihan {invoice_no} telah diterima. Terima kasih.',
        ],
        'rejected' => [
            'title'   => 'Bukti potong ditolak',
            'message' => 'Bukti potong pajak untuk tagihan {invoice_no} ditolak: {reason}. Silakan kirim kembali.',
        ],
    ],

    'invitation' => [
        'accepted' => [
            'title'   => '{name} telah bergabung',
            'message' => '{name} menerima undangan Anda dan bergabung sebagai {role}.',
        ],
    ],

    'review' => [
        'submitted' => [
            'title'   => 'Ulasan baru: {artifact}',
            'message' => '{customer} memberi {artifact} {rating} bintang. Silakan setujui atau sembunyikan ulasannya.',
        ],
    ],

    // order.payment_failed with no reason recorded
    'no_reason' => 'tanpa alasan',

    // a person's role in a business, for invitation.accepted
    'client_role' => [
        'O' => 'pemilik',
        'M' => 'manajer',
        'E' => 'karyawan',
    ],

    // a booking's payment, for the people serving it
    'payment_state' => [
        'paid'     => 'sudah dibayar',
        'at_store' => 'dibayar di toko',
        'awaiting' => 'belum dibayar, jadi masih dapat dibatalkan atau tidak datang',
    ],

    'moderation' => [
        'flagged' => [
            'title'   => 'Otternova menandai {subject}',
            'message' => 'Alasan: {reason}. {message} Silakan lakukan perubahan dan simpan; Otternova akan meninjaunya lagi.',
        ],
        'blocked' => [
            'title'   => '{subject} diblokir',
            'message' => 'Otternova menurunkan {subject} dari OtterNovaMart. Alasan: {reason}. {message} Silakan perbaiki dan simpan; dapat dijual lagi setelah Otternova membuka blokirnya.',
        ],
        'unblocked' => [
            'title'   => 'Blokir {subject} dibuka',
            'message' => 'Otternova telah membuka blokir {subject}; kembali seperti sebelum diblokir. {message}',
        ],
        'removed' => [
            'title'   => '{subject} dihapus',
            'message' => 'Otternova telah menghapus {subject} secara permanen. Alasan: {reason}. {message} Pesanan yang ada tetap dilayani.',
        ],
    ],

    // why Otternova flagged, blocked or removed something (ModerationEventModel::REASONS), for moderation.*
    'moderation_reason' => [
        'PROHIBITED'            => 'Barang terlarang atau dibatasi',
        'MISLEADING'            => 'Informasi menyesatkan atau tidak akurat',
        'INTELLECTUAL_PROPERTY' => 'Masalah kekayaan intelektual',
        'AUDIENCE'              => 'Tidak sesuai untuk audiensnya',
        'BUSINESS_TYPE'         => 'Tidak sesuai dengan jenis bisnis',
        'PRICING'               => 'Masalah harga',
        'OTHER'                 => 'Lainnya',
    ],

    'report' => [
        'submitted' => [
            'title'   => 'Laporan: {subject}',
            'message' => 'Seorang pengunjung melaporkan {subject} dari {business}: {reason}. Silakan tinjau.',
        ],
    ],

    // what a mart visitor reported (ModerationReportModel::REASONS), for report.submitted
    'report_reason' => [
        'PROHIBITED'            => 'Barang atau layanan terlarang atau ilegal',
        'SCAM'                  => 'Penipuan',
        'MISLEADING'            => 'Informasi menyesatkan',
        'INTELLECTUAL_PROPERTY' => 'Pelanggaran hak cipta atau merek dagang',
        'OFFENSIVE'             => 'Konten menyinggung atau kebencian',
        'CHILD_SAFETY'          => 'Keselamatan anak',
        'OTHER'                 => 'Lainnya',
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
