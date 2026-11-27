<?php

/** The report form of otternova_mart (App\Controllers\MartReports) and the staff's review of reports (App\Controllers\ReportAdmin) */
return [
    'invalid'          => 'Please check the highlighted fields.',
    'formExpired'      => 'This form has expired. Please reload the page and try again.',
    'reasonRequired'   => 'Please choose a reason.',
    'detailsRequired'  => 'Please tell us what is wrong.',
    'detailsTooLong'   => 'Please keep it within {0} characters.',
    'emailInvalid'     => 'Please enter a valid email address, or leave it empty.',
    'localeInvalid'    => 'This language is not supported.',
    'artifactNotFound' => 'This product or service was not found.',
    'tooMany'          => 'You have sent too many reports. Please wait a while and try again.',
    'businessBusy'     => 'We are receiving many reports about this business right now. Please try again later.',
    'received'         => 'Thank you. Otternova will review your report.',
    'saveFailed'       => 'Your report could not be sent. Please try again.',
    'invalidRequest'   => 'The request is not valid.',
    'businessNotFound' => 'Business not found.',
    'loadFailed'       => 'Could not load the reports. Please try again.',
    'invalidStatus'    => 'Choose new, filtered, dismissed or actioned.',
    'noteTooLong'      => 'Please keep the note within {0} characters.',
    'invalidFields'    => 'Please check the fields marked.',
    'notFound'         => 'Report not found.',
    'resolved'         => [
        'N' => 'Marked as new.',
        'S' => 'Marked as spam.',
        'D' => 'Dismissed.',
        'A' => 'Marked as actioned.',
    ],
];
