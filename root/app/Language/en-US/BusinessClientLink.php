<?php
return [
    'fields' => [
        'business_id'      => 'Business',
        'client_id'        => 'Client',
        'client_status'    => 'Status',
        'client_role'      => 'Role',
        'start_date'       => 'Start Date',
        'termination_date' => 'Termination Date',
        'owner_notes'      => 'Owner\'s Notes',
    ],
    'enum'   => [
        'client_status' => ['A' => 'Active', 'T' => 'Terminated'],
        'client_role'   => ['O' => 'Owner', 'M' => 'Manager', 'E' => 'Employee'],
    ],
];
