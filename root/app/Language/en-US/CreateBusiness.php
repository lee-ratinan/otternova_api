<?php
return [
    'form'              => [
        'title'        => 'Create Your Business',
        'subtitle'     => 'You need a business to get started. Fields marked with * are required',
        'submit'       => 'Create Business',
        'types-failed' => 'Could not load the business types.',
        'locale-failed' => 'Could not load the currencies and states of this country. Please pick the country again.',
    ],
    'heading'           => [
        'business'     => 'Business',
        'audience'     => 'Audience',
        'charges'      => 'Tips and Service Charge',
        'contact'      => 'Contact',
        'address'      => 'Address',
        'billing'      => 'Billing',
        'translations' => 'Business Name in Other Languages',
    ],
    'help'              => [
        'business-slug'  => 'Made from the business name; you can change it. Lowercase letters, numbers and hyphens, e.g. otter-cafe-sg. It appears in your page addresses.',
        'mood-and-tone'  => 'How your business speaks to customers, e.g. friendly and playful.',
        'subdivision'    => 'Choose the country first.',
        'fixed'          => 'Cannot be changed once the business is created.',
        'audience'       => 'Who your business welcomes. Leave it as General public if it is open to everyone.',
        'age-restricted' => 'Adults only means customers must be of legal adult age in your country.',
        'tip-policy'     => 'Disabled: no tipping. Off-system: tips are welcome but paid outside checkout. Optional: customers may tip at checkout. Required: customers must tip at least the amount below.',
        'tip-value'      => 'A percentage of the order, or an amount in your currency. Suggested or minimum; when tipping is required it is the minimum.',
        'service-charge' => 'Added to each service in an order, not to products: a percentage of the service, or a fixed amount per service. Leave it at 0 for no service charge.',
    ],
    'error'             => [
        'business-slug' => 'Please use only lowercase letters, numbers and single hyphens between them.',
    ],
    'translation-label' => 'Name in {0}',
];
