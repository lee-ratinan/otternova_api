<?php
return [
    'fields' => [
        'business_id'         => 'Bisnis',
        'client_id'           => 'Klien',
        'client_status'       => 'Status',
        'client_role'         => 'Peran',
        'is_default_business' => 'Bisnis Utama',
        'start_date'          => 'Tanggal Mulai',
        'termination_date'    => 'Tanggal Berhenti',
        'owner_notes'         => 'Catatan Pemilik',
    ],
    'enum'   => [
        'client_status'       => ['A' => 'Aktif', 'T' => 'Berhenti'],
        'client_role'         => ['O' => 'Pemilik', 'M' => 'Manajer', 'E' => 'Karyawan'],
        'is_default_business' => ['Y' => 'Ya', 'N' => 'Tidak'],
    ],
];
