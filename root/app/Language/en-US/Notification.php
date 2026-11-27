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
        'placed' => [
            'title'   => 'New order: {order_number}',
            'message' => '{customer} placed order {order_number} for {amount}. Please get its products ready. Payment: {payment}.',
        ],
        'rescheduled' => [
            'title'   => 'Booking moved: {service}',
            'message' => '{customer}\'s {service} at {store} (order {order_number}) was moved from {from} to {to}.',
        ],
        'booking_cancelled' => [
            'title'   => 'Booking cancelled: {service}',
            'message' => '{customer}\'s {service} on {when} at {store} (order {order_number}) was cancelled: {reason}.',
        ],
        'payment_failed' => [
            'title'   => 'Payment failed: {order_number}',
            'message' => 'The payment of {amount} by {method} for order {order_number} did not go through: {reason}. Please follow up with the customer.',
        ],
    ],

    'invoice' => [
        'issued' => [
            'title'   => 'New invoice: {invoice_no}',
            'message' => 'Invoice {invoice_no} of {amount} for {business} has been issued. Please pay it by {due}.',
        ],
    ],

    'subscription' => [
        'suspended' => [
            'title'   => 'Subscription suspended',
            'message' => 'The subscription of {business} has been suspended. Please pay any invoice due, or contact us, to reactivate it.',
        ],
        'expiring' => [
            'title'   => 'Subscription about to expire',
            'message' => 'The suspended subscription of {business} will expire on {expires} and its unpaid invoices will be voided. Please pay to keep it.',
        ],
        'cancelled' => [
            'title'   => 'Subscription cancelled',
            'message' => 'The subscription of {business} has been cancelled.',
        ],
    ],

    'withholding' => [
        'requested' => [
            'title'   => 'Withholding tax to review',
            'message' => '{business} asked to withhold tax under {rule}. Please review the request.',
        ],
        'approved' => [
            'title'   => 'Withholding tax approved',
            'message' => '{business} may now withhold tax under {rule}. It is worked out on your invoices from now on.',
        ],
        'rejected' => [
            'title'   => 'Withholding tax turned down',
            'message' => 'The request of {business} to withhold tax was turned down. Please contact us if you have any questions.',
        ],
    ],

    'certificate' => [
        'submitted' => [
            'title'   => 'Certificate to check: {invoice_no}',
            'message' => '{business} sent the withholding tax certificate of {amount} for invoice {invoice_no}. Please check it.',
        ],
        'accepted' => [
            'title'   => 'Certificate accepted',
            'message' => 'The withholding tax certificate for invoice {invoice_no} has been accepted. Thank you.',
        ],
        'rejected' => [
            'title'   => 'Certificate rejected',
            'message' => 'The withholding tax certificate for invoice {invoice_no} was rejected: {reason}. Please send it again.',
        ],
    ],

    'invitation' => [
        'accepted' => [
            'title'   => '{name} has joined',
            'message' => '{name} accepted your invitation and joined as {role}.',
        ],
    ],

    'review' => [
        'submitted' => [
            'title'   => 'New review: {artifact}',
            'message' => '{customer} gave {artifact} {rating} stars. Please approve or hide the review.',
        ],
    ],

    // order.payment_failed with no reason recorded
    'no_reason' => 'no reason given',

    // a person's role in a business, for invitation.accepted
    'client_role' => [
        'O' => 'an owner',
        'M' => 'a manager',
        'E' => 'an employee',
    ],

    // a booking's payment, for the people serving it
    'payment_state' => [
        'paid'     => 'paid',
        'at_store' => 'to be paid at the store',
        'awaiting' => 'not paid yet, so it may still be cancelled or not come',
    ],

    'moderation' => [
        'flagged' => [
            'title'   => 'Otternova flagged {subject}',
            'message' => 'Reason: {reason}. {message} Please make the changes and save; Otternova will review it again.',
        ],
        'blocked' => [
            'title'   => '{subject} is blocked',
            'message' => 'Otternova took {subject} off OtterNovaMart. Reason: {reason}. {message} Please fix it and save; it can go back on sale once Otternova unblocks it.',
        ],
        'unblocked' => [
            'title'   => '{subject} is unblocked',
            'message' => 'Otternova has unblocked {subject}; it is back as it was before the block. {message}',
        ],
        'removed' => [
            'title'   => '{subject} was removed',
            'message' => 'Otternova has removed {subject} for good. Reason: {reason}. {message} Existing orders are still honoured.',
        ],
    ],

    // why Otternova flagged, blocked or removed something (ModerationEventModel::REASONS), for moderation.*
    'moderation_reason' => [
        'PROHIBITED'            => 'Prohibited or restricted item',
        'MISLEADING'            => 'Misleading or inaccurate information',
        'INTELLECTUAL_PROPERTY' => 'Intellectual property concern',
        'AUDIENCE'              => 'Not suitable for its audience',
        'BUSINESS_TYPE'         => 'Does not match the business type',
        'PRICING'               => 'Pricing concern',
        'OTHER'                 => 'Other',
    ],

    'report' => [
        'submitted' => [
            'title'   => 'Report: {subject}',
            'message' => 'A visitor reported {subject} of {business}: {reason}. Please review it.',
        ],
    ],

    // what a mart visitor reported (ModerationReportModel::REASONS), for report.submitted
    'report_reason' => [
        'PROHIBITED'            => 'Prohibited or illegal goods or services',
        'SCAM'                  => 'Scam or fraud',
        'MISLEADING'            => 'Misleading information',
        'INTELLECTUAL_PROPERTY' => 'Copyright or trademark infringement',
        'OFFENSIVE'             => 'Offensive or hateful content',
        'CHILD_SAFETY'          => 'Child safety',
        'OTHER'                 => 'Other',
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
