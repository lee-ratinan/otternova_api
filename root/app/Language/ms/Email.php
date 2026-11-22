<?php
// Subject and body of each EmailService::TYPE_* email; {placeholders} come from the $data passed to send()
return [
    // Text around the content in Views/email/template-v1.php; company_address is left out of the footer while it is empty
    'template' => [
        'closing_message'     => 'Jika anda mempunyai sebarang pertanyaan, sila hubungi pasukan sokongan kami.',
        'best_regards'        => 'Salam hormat,',
        'team_name'           => 'Pasukan OtterNova',
        'company_name'        => 'OtterNova',
        'company_address'     => '##/## Thanon Song Prapha, Khwaeng Don Mueang, Khet Don Mueang, Bangkok, 10210 THAILAND',
        'contact_support'     => 'Hubungi Sokongan',
        'privacy_policy'      => 'Dasar Privasi',
        'all_rights_reserved' => 'Hak cipta terpelihara.',
    ],
    'login_otp' => [
        'subject' => 'Kod log masuk OtterNova anda',
        'body'    => "Hai {full_name},\n\nKod log masuk anda ialah {otp}. Ia tamat tempoh dalam {minutes} minit.\n\nJika anda tidak cuba log masuk, sila tukar kata laluan anda.",
    ],
    'account_activation' => [
        'subject' => 'Aktifkan akaun OtterNova anda',
        'body'    => "Hai {full_name},\n\nTerima kasih kerana mendaftar dengan OtterNova. Sila buka pautan ini untuk mengaktifkan akaun anda:\n\n{link}\n\nPautan ini tamat tempoh dalam {hours} jam. Jika anda tidak mendaftar, anda boleh abaikan e-mel ini.",
    ],
    'account_exists'     => [
        'subject' => 'Akaun OtterNova anda',
        'body'    => "Hai {full_name},\n\nSeseorang cuba mendaftar dengan OtterNova menggunakan alamat e-mel ini, tetapi ia sudah mempunyai akaun. Anda boleh log masuk di sini:\n\n{link}\n\nJika anda terlupa kata laluan, gunakan \"Lupa Kata Laluan\" di halaman log masuk. Jika itu bukan anda, anda boleh abaikan e-mel ini.",
    ],
    'business_invitation' => [
        'subject' => '{inviter} menjemput anda ke {business_name} di OtterNova',
        'body'    => "Hai,\n\n{inviter} menjemput anda untuk bekerja di {business_name} di OtterNova sebagai {role}. Sila buka pautan ini untuk mencipta akaun anda dengan alamat e-mel ini:\n\n{link}\n\nPautan ini tamat tempoh dalam {days} hari. Jika anda tidak menjangkakan ini, anda boleh abaikan e-mel ini.",
    ],
    'password_reset'     => [
        'subject' => 'Tetapkan semula kata laluan OtterNova anda',
        'body'    => "Hai {full_name},\n\nKami menerima permintaan untuk menetapkan semula kata laluan akaun OtterNova anda. Sila buka pautan ini untuk memilih kata laluan baharu:\n\n{link}\n\nPautan ini tamat tempoh dalam {minutes} minit dan hanya boleh digunakan sekali. Jika anda tidak memintanya, anda boleh abaikan e-mel ini: kata laluan anda kekal sama.",
    ],
    'order_cancelled'    => [
        'subject' => 'Pesanan {order_number} anda dengan {business_name} telah dibatalkan',
        'body'    => "Hai {first_name},\n\nPesanan {order_number} anda dengan {business_name} telah dibatalkan. Tiada bayaran dibuat untuknya, jadi tiada apa-apa untuk dipulangkan.\n\nAnda boleh melihat pesanan anda di sini:\n\n{link}\n\nJika anda mempunyai sebarang pertanyaan, sila hubungi {business_name}.",
    ],
    'order_cancelled_paid' => [
        'subject' => 'Pesanan {order_number} anda dengan {business_name} telah dibatalkan',
        'body'    => "Hai {first_name},\n\nPesanan {order_number} anda dengan {business_name} telah dibatalkan. Anda telah membayar {amount} untuknya: {business_name} akan memulangkannya kepada anda, dan mungkin menghubungi anda tentang caranya.\n\nAnda boleh melihat pesanan anda di sini:\n\n{link}\n\nJika anda mempunyai sebarang pertanyaan, sila hubungi {business_name}.",
    ],
    'order_rescheduled'  => [
        'subject' => 'Tempahan anda dengan {business_name} telah dipindahkan ke {to}',
        'body'    => "Hai {first_name},\n\nTempahan {service} anda dengan {business_name} (pesanan {order_number}) telah dipindahkan dari {from} ke {to}.\n\nAnda boleh melihat pesanan anda di sini:\n\n{link}\n\nJika masa ini tidak sesuai untuk anda, sila hubungi {business_name}.",
    ],
    'order_placed'       => [
        'subject'        => 'Pesanan {order_number} anda dengan {business_name}',
        'body'           => "Hai {first_name},\n\nTerima kasih atas pesanan anda dengan {business_name}. Nombor pesanan anda ialah {order_number}.\n\n{items}\n\nJumlah: {total}\n\nIa belum dibayar. Pembayaran: {method}\n{how}\n\nAnda boleh melihat pesanan anda, membayarnya dan memaklumkan kami selepas anda membayar di sini:\n\n{link}\n\nSehingga ia dibayar, pesanan anda mungkin dibatalkan.",
        'promptpay'      => 'Imbas kod QR PromptPay di halaman pesanan anda, dengan jumlah yang sudah dimasukkan.',
        'shipping'       => 'Penghantaran: {0}',
        'shipping-label' => 'Penghantaran',
        'no-instruction' => '{business_name} akan memberitahu anda cara untuk membayar.',
    ],
    'order_placed_paid'  => [
        'subject' => 'Pesanan {order_number} anda dengan {business_name}',
        'body'    => "Hai {first_name},\n\nTerima kasih atas pesanan anda dengan {business_name}. Nombor pesanan anda ialah {order_number}.\n\n{items}\n\nJumlah: {total}, dibayar melalui {method}.\n\nAnda boleh melihat pesanan anda di sini:\n\n{link}",
    ],
    'order_history_link' => [
        'subject' => 'Sejarah pesanan anda dengan {business_name}',
        'body'    => "Hai,\n\nSeseorang meminta untuk melihat pesanan yang dibuat dengan {business_name} menggunakan alamat e-mel ini ({email}). Buka pautan ini untuk melihatnya:\n\n{link}\n\nPautan ini berfungsi sekali sahaja, selama {minutes} minit. Jika anda tidak memintanya, anda boleh abaikan e-mel ini: tiada apa-apa telah ditunjukkan kepada sesiapa.",
    ],
];
