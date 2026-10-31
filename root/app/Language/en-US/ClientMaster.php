<?php
return [
    'fields' => [
        'auth_id'            => 'Account ID',
        'legal_first_name'   => 'Legal First Name',
        'legal_middle_name'  => 'Legal Middle Name',
        'legal_last_name'    => 'Legal Last Name',
        'display_name'       => 'Display Name',
        'national_id_type'   => 'ID Document Type',
        'national_id_number' => 'ID Document Number',
        'status_message'     => 'Status Message',
        'status_emoji'       => 'Status Emoji',
        'gender'             => 'Gender',
        'date_of_birth'      => 'Date of Birth',
        'nationality_code'   => 'Nationality',
        'preferred_language' => 'Preferred Language',
        'timezone'           => 'Timezone',
        'avatar_path'        => 'Avatar',
        'bio'                => 'Bio',
        'marketing_opt_in'   => 'Marketing Opt-in',
        'internal_notes'     => 'Internal Notes',
    ],
    'enum'   => [
        'national_id_type' => ['N' => 'National ID', 'P' => 'Passport'],
        'gender'           => ['M' => 'Male', 'F' => 'Female', 'NB' => 'Non-binary', 'X' => 'Other', 'N' => 'Prefer not to say'],
    ],
];
