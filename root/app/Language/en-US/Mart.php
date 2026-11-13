<?php

// App\Controllers\Mart: the public pages of a business on the marketplace
return [
    'slugRequired'     => 'Which business? business_slug is required.',
    'businessNotFound' => 'There is no such business.',
    'artifactNotFound' => 'There is no such product or service.',
    'categoryNotFound' => 'There is no such category.',
    'loadFailed'       => 'The page could not be loaded. Please try again.',
    'invalidQuery'     => 'Some of the filters are not valid.',
    'invalidIds'       => 'ids must be public IDs separated by commas.',
    'tooManyIds'       => 'At most {0} public IDs at once.',
    'invalidSort'      => 'sort must be name, price_asc, price_desc, rating or newest.',
    'invalidType'      => 'type must be P (products) or S (services).',
    'invalidPage'      => 'page must be a whole number from 1.',
    'invalidPerPage'   => 'per_page must be a whole number from 1 to {0}.',
    'variantNotBookable' => 'This service cannot be booked by time.',
    'invalidDate'        => 'date must be a date, YYYY-MM-DD.',
    'dateOutsideWindow'  => 'Bookings are taken from {0} to {1}.',
    'storeNotFound'      => 'There is no such store for this service.',
    'staffNotFound'      => 'There is no such person for this service.',
    'variantNotGroup'    => 'This service has no sessions to book.',
    'dateToBeforeFrom'   => 'The last start date must be on or after the first.',
];
