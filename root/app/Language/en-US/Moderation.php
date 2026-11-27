<?php

/** The Admin app's review and moderation (App\Controllers\ModerationAdmin) */
return [
    'invalidRequest'   => 'The request could not be read. Please reload the page and try again.',
    'invalidFields'    => 'Please check the fields marked.',
    'invalidSubject'   => 'Choose a business, a product or service, or a variant.',
    'invalidAction'    => 'This action cannot be taken on it.',
    'invalidReason'    => 'Choose one of the reasons.',
    'reasonRequired'   => 'Please choose a reason.',
    'messageRequired'  => 'Please tell the business what to fix.',
    'tooLong'          => 'Please keep it within {0} characters.',
    'businessNotFound' => 'Business not found.',
    'artifactNotFound' => 'Product or service not found.',
    'variantNotFound'  => 'Variant not found.',
    'removedAlready'   => 'It has been removed for good; nothing more can be done to it.',
    'blockedAlready'   => 'It is blocked already.',
    'notBlocked'       => 'It is not blocked.',
    'unblockFirst'     => 'It is blocked: unblock it to approve it.',
    'loadFailed'       => 'Could not load the moderation history. Please try again.',
    'saveFailed'       => 'Could not save. Please try again.',
    'done'               => [
        'AP' => 'Approved.',
        'FL' => 'Flagged; the business has been asked to fix it.',
        'BL' => 'Blocked; it is off the marketplace.',
        'UB' => 'Unblocked and approved.',
        'RM' => 'Removed for good.',
    ],
];
