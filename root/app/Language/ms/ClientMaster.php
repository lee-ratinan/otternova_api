<?php
return [
    'fields' => [
        'auth_id'            => 'ID Akaun',
        'legal_first_name'   => 'Nama Pertama Sah',
        'legal_middle_name'  => 'Nama Tengah Sah',
        'legal_last_name'    => 'Nama Akhir Sah',
        'display_name'       => 'Nama Paparan',
        'national_id_type'   => 'Jenis Dokumen Pengenalan',
        'national_id_number' => 'Nombor Dokumen Pengenalan',
        'status_message'     => 'Mesej Status',
        'status_emoji'       => 'Emoji Status',
        'gender'             => 'Jantina',
        'date_of_birth'      => 'Tarikh Lahir',
        'nationality_code'   => 'Warganegara',
        'preferred_language' => 'Bahasa Pilihan',
        'bio'                => 'Bio',
        'marketing_opt_in'   => 'Setuju Menerima Pemasaran',
        'internal_notes'     => 'Nota Dalaman',
    ],
    'enum'   => [
        'national_id_type' => ['N' => 'Kad Pengenalan', 'P' => 'Pasport'],
        'gender'           => ['M' => 'Lelaki', 'F' => 'Perempuan', 'NB' => 'Bukan binari', 'X' => 'Lain-lain', 'N' => 'Memilih untuk tidak menyatakan'],
    ],
];
