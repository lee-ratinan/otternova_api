<?php
return [
    'fields'             => [
        'business_id'     => 'Business',
        'discount_code'   => 'Code',
        'description'     => 'Description',
        'discount_type'   => 'Discount Type',
        'discount_value'  => 'Discount',
        'applies_to'      => 'Applies To',
        'min_subtotal'    => 'Minimum Subtotal',
        'max_redemptions' => 'Maximum Uses',
        'valid_from'      => 'Valid From',
        'expires_at'      => 'Expires At',
        'is_active'       => 'Active',
    ],
    'invalidCode'        => 'Please enter a code of 3 to 30 letters, digits, dashes and underscores, starting with a letter or a digit.',
    'invalidValue'       => 'Please enter a discount above zero, with at most 2 decimals.',
    'percentTooHigh'     => 'A percentage discount can be at most 100.',
    'invalidAmount'      => 'Please enter an amount, with at most 2 decimals.',
    'invalidDatetime'    => 'Please enter a date and time.',
    'expiresBeforeValid' => 'The discount must expire after it becomes valid.',
    'belowRedeemed'      => 'This discount has already been used {0} times; the maximum cannot be lower.',
];
