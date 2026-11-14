<?php
return [
    'fields'             => [
        'store_name'             => 'Store Name',
        'store_type'             => 'Store Type',
        'store_timezone'         => 'Timezone',
        'store_phone_number'     => 'Phone Number',
        'store_address_line_1'   => 'Address Line 1',
        'store_address_line_2'   => 'Address Line 2',
        'store_city'             => 'City',
        'store_subdivision_code' => 'State / Province',
        'store_postal_code'      => 'Postal Code',
        'receipt_format'         => 'Receipt Printer',
        'receipt_footer'         => 'Receipt Footer',
    ],
    'enum'               => [
        'store_type'     => ['P' => 'Physical Store', 'O' => 'Online Store'],
        'receipt_format' => ['58mm' => 'Thermal 58mm', '80mm' => 'Thermal 80mm', 'A4' => 'A4 paper'],
    ],
    'invalidSubdivision' => 'This state or province is not in the business\'s country.',
    'invalidTimezone'    => 'This timezone is not in the business\'s country.',
];
