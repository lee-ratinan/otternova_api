<?php
return [
    'fields' => [
        'auth_id'            => 'ID Akun',
        'legal_first_name'   => 'Nama Depan Resmi',
        'legal_middle_name'  => 'Nama Tengah Resmi',
        'legal_last_name'    => 'Nama Belakang Resmi',
        'display_name'       => 'Nama Tampilan',
        'national_id_type'   => 'Jenis Dokumen Identitas',
        'national_id_number' => 'Nomor Dokumen Identitas',
        'status_message'     => 'Pesan Status',
        'status_emoji'       => 'Emoji Status',
        'gender'             => 'Jenis Kelamin',
        'date_of_birth'      => 'Tanggal Lahir',
        'nationality_code'   => 'Kewarganegaraan',
        'preferred_language' => 'Bahasa Pilihan',
        'bio'                => 'Bio',
        'marketing_opt_in'   => 'Bersedia Menerima Pemasaran',
        'internal_notes'     => 'Catatan Internal',
    ],
    'enum'   => [
        'national_id_type' => ['N' => 'KTP / Identitas Nasional', 'P' => 'Paspor'],
        'gender'           => ['M' => 'Laki-laki', 'F' => 'Perempuan', 'NB' => 'Non-biner', 'X' => 'Lainnya', 'N' => 'Memilih tidak menjawab'],
    ],
];
