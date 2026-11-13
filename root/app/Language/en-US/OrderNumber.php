<?php

// App\Controllers\OrderNumbers: how the order numbers of a business look
return [
    'invalidRequest'   => 'The request could not be read. Please reload the page and try again.',
    'invalidFields'    => 'Please correct the highlighted fields.',
    'loadFailed'       => 'Could not load the order number settings. Please try again.',
    'saveFailed'       => 'Could not save. Please try again.',
    'slugRequired'     => 'Please choose a business.',
    'businessNotFound' => 'Business not found.',
    'managersOnly'     => 'Only the owners and managers of this business can change its order numbers.',
    'saved'            => 'Order number settings saved.',
    'order_prefix'     => 'The prefix can be up to 4 letters, A to Z.',
    'order_date_part'  => 'Please choose a date part.',
    'order_reset'      => 'Please choose when the numbers start again.',
    'order_digits'     => 'The running number must be 3 to 6 digits wide.',
    'resetTooOften'    => 'The numbers cannot start again more often than the date part shows: a daily reset needs the full date, a monthly reset the month.',
];
