<?php

// The Terms of Service and Privacy Policy versions; see App\Controllers\Legal and App\Controllers\LegalAdmin
return [
    'documentNotFound'    => 'This document could not be found.',
    'loadFailed'          => 'Could not load the documents. Please try again.',
    'versionNotFound'     => 'This version could not be found.',
    'invalidRequest'      => 'The request is not valid.',
    'invalidFields'       => 'Please check the highlighted fields.',
    'invalidAudience'     => 'Please choose the Client app or the marketplace.',
    'invalidType'         => 'Please choose the Terms of Service or the Privacy Policy.',
    'invalidLabel'        => 'Use 1-32 letters, digits, dots, dashes or underscores, starting with a letter or digit, such as 2026-12-01.',
    'invalidTime'         => 'Please enter a valid date and time.',
    'timeInPast'          => 'A new version cannot take effect in the past.',
    'timeNotAfterLatest'  => 'A new version must take effect after the latest one set ({0} UTC).',
    'invalidReacceptance' => 'Please choose whether everyone must accept this version again.',
    'labelTaken'          => 'This document already has a version with this label.',
    'unknownLocale'       => 'This language is not supported.',
    'bodyRequired'        => 'Please enter the text of the document.',
    'bodyTooLong'         => 'The text can be at most {0} bytes.',
    'summaryTooLong'      => 'The summary of changes can be at most {0} characters.',
    'englishRequired'     => 'Every version needs its English (en-US) text.',
    'saveFailed'          => 'Could not save the version. Please try again.',
    'published'           => 'The version has been published.',
    'cannotCancel'        => 'Only a version that has not taken effect yet can be cancelled.',
    'cancelled'           => 'The version has been cancelled.',
];
