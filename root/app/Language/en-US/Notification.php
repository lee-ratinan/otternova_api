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

    'order' => [
        'cancelled' => [
            'title'   => 'Refund due on a cancelled order',
            'message' => 'Order {order_number} was cancelled by {by}: {reason}. {amount} was paid. Please refund it.',
        ],
        'booked' => [
            'title'   => 'New booking: {service}',
            'message' => '{customer} booked {service} with you on {when} at {store} (order {order_number}). Payment: {payment}.',
        ],
        'seats_booked' => [
            'title'   => '{seats} more taken: {session}',
            'message' => '{customer} took {seats} of {session} on {when} at {store}; {booked} of {capacity} are now taken (order {order_number}). Payment: {payment}.',
        ],
        'payment_reported' => [
            'title'   => 'Payment to check: {order_number}',
            'message' => '{customer} says they paid {amount} for order {order_number} by {method}{reference}. Please check it and mark it received.',
        ],
        'cancelled_unpaid' => [
            'title'   => 'Order cancelled',
            'message' => 'Order {order_number} was cancelled by {by}: {reason}. It was not paid.',
        ],
    ],

    // a booking's payment, for the people serving it
    'payment_state' => [
        'paid'     => 'paid',
        'at_store' => 'to be paid at the store',
        'awaiting' => 'not paid yet, so it may still be cancelled or not come',
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
