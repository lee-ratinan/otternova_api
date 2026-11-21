<?php
return [
    'fields' => [
        'business_id'         => 'Perniagaan',
        'client_id'           => 'Pelanggan',
        'client_status'       => 'Status',
        'client_role'         => 'Peranan',
        'is_default_business' => 'Perniagaan Lalai',
        'start_date'          => 'Tarikh Mula',
        'termination_date'    => 'Tarikh Tamat Perkhidmatan',
        'owner_notes'         => 'Nota Pemilik',
    ],
    'enum'   => [
        'client_status'       => ['A' => 'Aktif', 'T' => 'Ditamatkan'],
        'client_role'         => ['O' => 'Pemilik', 'M' => 'Pengurus', 'E' => 'Pekerja'],
        'is_default_business' => ['Y' => 'Ya', 'N' => 'Tidak'],
    ],
];
