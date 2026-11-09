<?php
// The notifications' titles and messages, by event_type (see App\Libraries\Notifier::EVENTS); {placeholders} come
// from notification_master.message_params. Then the endpoints' own messages.
return [
    'payment' => [
        'slip_submitted' => [
            'title'   => 'Payment to check',
            'message' => '{business} paid {amount} for invoice {invoice_no}. Please check the slip and approve it.',
        ],
        'approved' => [
            'title'   => 'Payment approved',
            'message' => 'Your payment of {amount} for invoice {invoice_no} has been approved. Thank you.',
        ],
        'rejected' => [
            'title'   => 'Payment rejected',
            'message' => 'Your payment for invoice {invoice_no} was rejected: {reason}. Please pay again.',
        ],
    ],

    'invalidRequest' => 'The request could not be read. Please reload the page and try again.',
    'invalidStatus'  => 'Please choose unread or read.',
    'notFound'       => 'This notification could not be found.',
    'loadFailed'     => 'Could not load the notifications. Please try again.',
    'saveFailed'     => 'Could not update the notifications. Please try again.',
    'allRead'        => 'All notifications marked read.',
    'deleted'        => 'Notification deleted.',
    'readDeleted'    => 'Read notifications deleted: {0}.',
];
