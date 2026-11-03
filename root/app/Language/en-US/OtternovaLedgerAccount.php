<?php
return [
    'fields' => [
        'account_name'  => 'Account Name',
        'account_type'  => 'Account Type',
        'currency_code' => 'Currency',
        'account_class' => 'Account Class',
    ],
    'enum'   => [
        'account_type'  => ['I' => 'Internal', 'X' => 'External'],
        'account_class' => ['A' => 'Asset', 'L' => 'Liability', 'E' => 'Equity', 'R' => 'Revenue', 'X' => 'Expense'],
    ],
];
