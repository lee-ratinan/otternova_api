<?php
return [
    'fields' => [
        'entity_id'      => 'Entity',
        'tax_code'       => 'Tax Code',
        'tax_name'       => 'Name',
        'tax_kind'       => 'Kind',
        'rate'           => 'Rate (%)',
        'account_id'     => 'Tax Account',
        'effective_from' => 'Effective From',
        'effective_to'   => 'Effective To',
    ],
    'enum'   => [
        'tax_kind' => ['VO' => 'Output VAT', 'VI' => 'Input VAT', 'WD' => 'Withholding tax deducted', 'WS' => 'Withholding tax suffered'],
    ],
];
