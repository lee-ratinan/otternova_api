<?php
return [
    'fields' => [
        'activity_summary' => 'Summary',
        'table_involved'   => 'Table',
        'table_id_updated' => 'Record ID',
        'activity_detail'  => 'Changes',
        'changed_by_cust'  => 'Changed by Customer',
        'changed_by_acc'   => 'Changed by Account',
    ],
    'actor'  => [
        'system'   => 'System',
        'customer' => 'Customer',
        'account'  => 'Account',
    ],
    'invalidRequest'   => 'The request could not be read. Please reload the page and try again.',
    'invalidTable'     => 'Please choose a table from the list.',
    'invalidRecordId'  => 'The record ID must be a whole number from 1.',
    'recordNeedsTable' => 'Please choose the table of the record ID.',
    'loadFailed'       => 'Could not load the activity log. Please try again.',
];
