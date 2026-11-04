<?php
return [
    'fields'             => [
        'store_name'             => 'Store Name',
        'store_type'             => 'Store Type',
        'store_phone_number'     => 'Phone Number',
        'store_address_line_1'   => 'Address Line 1',
        'store_address_line_2'   => 'Address Line 2',
        'store_city'             => 'City',
        'store_subdivision_code' => 'State / Province',
        'store_postal_code'      => 'Postal Code',
    ],
    'enum'               => [
        'store_type' => ['P' => 'Physical Store', 'O' => 'Online Store'],
    ],
    'invalidSubdivision' => 'This state or province is not in the business\'s country.',
];
