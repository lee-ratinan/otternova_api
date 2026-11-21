<?php
// Subject and body of each EmailService::TYPE_* email; {placeholders} come from the $data passed to send()
return [
    // Text around the content in Views/email/template-v1.php; company_address is left out of the footer while it is empty
    'template' => [
        'closing_message'     => 'Jika ada pertanyaan, silakan hubungi tim dukungan kami.',
        'best_regards'        => 'Salam hangat,',
        'team_name'           => 'Tim OtterNova',
        'company_name'        => 'OtterNova',
        'company_address'     => '##/## Thanon Song Prapha, Khwaeng Don Mueang, Khet Don Mueang, Bangkok, 10210 THAILAND',
        'contact_support'     => 'Hubungi Dukungan',
        'privacy_policy'      => 'Kebijakan Privasi',
        'all_rights_reserved' => 'Hak cipta dilindungi undang-undang.',
    ],
    'login_otp' => [
        'subject' => 'Kode masuk OtterNova Anda',
        'body'    => "Halo {full_name},\n\nKode masuk Anda adalah {otp}. Kode ini berlaku selama {minutes} menit.\n\nJika Anda tidak mencoba masuk, silakan ubah kata sandi Anda.",
    ],
    'account_activation' => [
        'subject' => 'Aktifkan akun OtterNova Anda',
        'body'    => "Halo {full_name},\n\nTerima kasih telah mendaftar di OtterNova. Silakan buka tautan ini untuk mengaktifkan akun Anda:\n\n{link}\n\nTautan ini berlaku selama {hours} jam. Jika Anda tidak mendaftar, abaikan email ini.",
    ],
    'account_exists'     => [
        'subject' => 'Akun OtterNova Anda',
        'body'    => "Halo {full_name},\n\nSeseorang mencoba mendaftar di OtterNova dengan alamat email ini, tetapi alamat ini sudah memiliki akun. Anda dapat masuk di sini:\n\n{link}\n\nJika Anda lupa kata sandi, gunakan \"Lupa Kata Sandi\" di halaman masuk. Jika itu bukan Anda, abaikan email ini.",
    ],
    'business_invitation' => [
        'subject' => '{inviter} mengundang Anda ke {business_name} di OtterNova',
        'body'    => "Halo,\n\n{inviter} mengundang Anda untuk bekerja di {business_name} di OtterNova sebagai {role}. Silakan buka tautan ini untuk membuat akun dengan alamat email ini:\n\n{link}\n\nTautan ini berlaku selama {days} hari. Jika Anda tidak mengharapkan ini, abaikan email ini.",
    ],
    'password_reset'     => [
        'subject' => 'Atur ulang kata sandi OtterNova Anda',
        'body'    => "Halo {full_name},\n\nKami menerima permintaan untuk mengatur ulang kata sandi akun OtterNova Anda. Silakan buka tautan ini untuk memilih kata sandi baru:\n\n{link}\n\nTautan ini berlaku selama {minutes} menit dan hanya dapat digunakan sekali. Jika Anda tidak memintanya, abaikan email ini: kata sandi Anda tidak berubah.",
    ],
    'order_cancelled'    => [
        'subject' => 'Pesanan {order_number} Anda di {business_name} dibatalkan',
        'body'    => "Halo {first_name},\n\nPesanan {order_number} Anda di {business_name} telah dibatalkan. Belum ada pembayaran untuk pesanan ini, jadi tidak ada yang perlu dikembalikan.\n\nAnda dapat melihat pesanan Anda di sini:\n\n{link}\n\nJika ada pertanyaan, silakan hubungi {business_name}.",
    ],
    'order_cancelled_paid' => [
        'subject' => 'Pesanan {order_number} Anda di {business_name} dibatalkan',
        'body'    => "Halo {first_name},\n\nPesanan {order_number} Anda di {business_name} telah dibatalkan. Anda telah membayar {amount}: {business_name} akan mengembalikan dana Anda, dan mungkin menghubungi Anda mengenai caranya.\n\nAnda dapat melihat pesanan Anda di sini:\n\n{link}\n\nJika ada pertanyaan, silakan hubungi {business_name}.",
    ],
    'order_rescheduled'  => [
        'subject' => 'Pemesanan Anda di {business_name} dipindahkan ke {to}',
        'body'    => "Halo {first_name},\n\nPemesanan {service} Anda di {business_name} (pesanan {order_number}) telah dipindahkan dari {from} ke {to}.\n\nAnda dapat melihat pesanan Anda di sini:\n\n{link}\n\nJika waktu ini tidak sesuai, silakan hubungi {business_name}.",
    ],
    'order_placed'       => [
        'subject'        => 'Pesanan {order_number} Anda di {business_name}',
        'body'           => "Halo {first_name},\n\nTerima kasih atas pesanan Anda di {business_name}. Nomor pesanan Anda adalah {order_number}.\n\n{items}\n\nTotal: {total}\n\nPesanan ini belum dibayar. Pembayaran: {method}\n{how}\n\nAnda dapat melihat pesanan, membayarnya, dan memberi tahu kami setelah membayar di sini:\n\n{link}\n\nSelama belum dibayar, pesanan Anda dapat dibatalkan.",
        'promptpay'      => 'Pindai kode QR PromptPay di halaman pesanan Anda, jumlahnya sudah tercantum.',
        'shipping'       => 'Pengiriman: {0}',
        'no-instruction' => '{business_name} akan memberi tahu Anda cara membayar.',
    ],
    'order_placed_paid'  => [
        'subject' => 'Pesanan {order_number} Anda di {business_name}',
        'body'    => "Halo {first_name},\n\nTerima kasih atas pesanan Anda di {business_name}. Nomor pesanan Anda adalah {order_number}.\n\n{items}\n\nTotal: {total}, dibayar dengan {method}.\n\nAnda dapat melihat pesanan Anda di sini:\n\n{link}",
    ],
    'order_history_link' => [
        'subject' => 'Riwayat pesanan Anda di {business_name}',
        'body'    => "Halo,\n\nSeseorang meminta untuk melihat pesanan di {business_name} yang dibuat dengan alamat email ini ({email}). Buka tautan ini untuk melihatnya:\n\n{link}\n\nTautan ini hanya dapat digunakan sekali, selama {minutes} menit. Jika Anda tidak memintanya, abaikan email ini: tidak ada yang ditampilkan kepada siapa pun.",
    ],
];
