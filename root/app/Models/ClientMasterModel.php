<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for the client_master table
 */
class ClientMasterModel extends Model
{
    use FieldConfigurationTrait;

    // national_id_type
    public const ID_NATIONAL_ID = 'N';
    public const ID_PASSPORT    = 'P';

    // gender
    public const GENDER_MALE          = 'M';
    public const GENDER_FEMALE        = 'F';
    public const GENDER_NON_BINARY    = 'NB';
    public const GENDER_OTHER         = 'X';
    public const GENDER_NOT_SPECIFIED = 'N';

    protected $table            = 'client_master';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'auth_id',
        'legal_first_name',
        'legal_middle_name',
        'legal_last_name',
        'display_name',
        'national_id_type',
        'national_id_number',
        'status_message',
        'status_emoji',
        'gender',
        'date_of_birth',
        'nationality_code',
        'preferred_language',
        'timezone',
        'avatar_path',
        'bio',
        'marketing_opt_in',
        'internal_notes',
    ];

    // created_at and updated_at are maintained by the database
    protected $useTimestamps = false;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $validationRules = [
        'id'                 => 'permit_empty|is_natural_no_zero',
        'auth_id'            => 'required|is_natural_no_zero|is_unique[client_master.auth_id,id,{id}]',
        'legal_first_name'   => 'permit_empty|max_length[128]',
        'legal_middle_name'  => 'permit_empty|max_length[128]',
        'legal_last_name'    => 'permit_empty|max_length[128]',
        'national_id_type'   => 'permit_empty|in_list[N,P]',
        'national_id_number' => 'permit_empty|max_length[64]',
        'status_message'     => 'permit_empty|max_length[255]',
        'status_emoji'       => 'permit_empty|max_length[16]',
        'gender'             => 'permit_empty|in_list[M,F,NB,X,N]',
        'date_of_birth'      => 'permit_empty|valid_date[Y-m-d]',
        'nationality_code'   => 'permit_empty|alpha|exact_length[2]',
        'preferred_language' => 'permit_empty|max_length[16]',
        'timezone'           => 'permit_empty|timezone',
        'avatar_path'        => 'permit_empty|max_length[255]',
        'marketing_opt_in'   => 'permit_empty|in_list[0,1]',
    ];
    protected $validationMessages   = [];
    protected $skipValidation       = false;
    protected $cleanValidationRules = true;

    /**
     * Field configuration shared by the Form Builder (as 'fields') and the Table Builder (via toColumns()),
     * keyed by field name so a controller can unset() what it does not need.
     * @return array
     */
    public function getConfiguration(): array
    {
        $fields = [
            'auth_id'            => ['type' => 'number', 'readonly_in' => ['edit']], // linked account is fixed once created
            'legal_first_name'   => ['type' => 'text'],
            'legal_middle_name'  => ['type' => 'text'],
            'legal_last_name'    => ['type' => 'text'],
            'display_name'       => ['type' => 'text'],
            'national_id_type'   => ['type' => 'select'],
            'national_id_number' => ['type' => 'text'],
            'status_message'     => ['type' => 'text'],
            'status_emoji'       => ['type' => 'text'],
            'gender'             => ['type' => 'select'],
            'date_of_birth'      => ['type' => 'date', 'max' => date('Y-m-d'), 'searchable' => false],
            'nationality_code'   => ['type' => 'select', 'options' => listCountries(), 'searchable' => false],
            'preferred_language' => ['type' => 'select', 'options' => listLanguages(), 'searchable' => false],
            'timezone'           => ['type' => 'select', 'options' => self::timezoneOptions(), 'searchable' => false],
            'avatar_path'        => ['type' => 'text', 'orderable' => false, 'searchable' => false],
            'bio'                => ['type' => 'textarea', 'rows' => 4, 'orderable' => false],
            'marketing_opt_in'   => [
                'type'          => 'switch',
                'checked_value' => '1',
                'off_value'     => '0',
                'searchable'    => false,
            ],
            'internal_notes'     => ['type' => 'textarea', 'rows' => 4, 'orderable' => false],
        ];
        $enums  = [
            'national_id_type' => [self::ID_NATIONAL_ID, self::ID_PASSPORT],
            'gender'           => [self::GENDER_MALE, self::GENDER_FEMALE, self::GENDER_NON_BINARY, self::GENDER_OTHER, self::GENDER_NOT_SPECIFIED],
        ];

        return $this->buildConfiguration('ClientMaster', $fields, $enums);
    }

    /**
     * Find a client record by its auth_master ID
     * @param int $authId
     * @return array|null
     */
    public function findByAuthId(int $authId): ?array
    {
        return $this->where('auth_id', $authId)->first();
    }
}
