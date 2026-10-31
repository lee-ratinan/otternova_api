<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for the auth_master table
 */
class AuthMasterModel extends Model
{
    use FieldConfigurationTrait;

    // otp_mode
    public const OTP_EMAIL     = 'E';
    public const OTP_TELEPHONE = 'T';
    public const OTP_NONE      = 'X';

    // account_status
    public const STATUS_ACTIVE    = 'A';
    public const STATUS_PENDING   = 'P';
    public const STATUS_BLOCKED   = 'B';
    public const STATUS_SUSPENDED = 'S';

    // account_type
    public const TYPE_STAFF  = 'S';
    public const TYPE_CLIENT = 'C';

    protected $table            = 'auth_master';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'uid',
        'email_address',
        'telephone_number',
        'password_hash',
        'password_expiry',
        'full_name',
        'otp_mode',
        'account_status',
        'account_type',
        'admin_notes',
    ];

    // created_at and updated_at are maintained by the database
    protected $useTimestamps = false;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $validationRules = [
        'id'               => 'permit_empty|is_natural_no_zero',
        'uid'              => 'required|max_length[128]|is_unique[auth_master.uid,id,{id}]',
        'email_address'    => 'required|valid_email|max_length[128]|is_unique[auth_master.email_address,id,{id}]',
        'telephone_number' => 'permit_empty|max_length[32]|is_unique[auth_master.telephone_number,id,{id}]',
        'password_expiry'  => 'permit_empty|valid_date[Y-m-d]',
        'full_name'        => 'required|max_length[255]',
        'otp_mode'         => 'permit_empty|in_list[E,T,X]',
        'account_status'   => 'permit_empty|in_list[A,P,B,S]',
        'account_type'     => 'permit_empty|in_list[S,C]',
    ];
    protected $validationMessages   = [];
    protected $skipValidation       = false;
    protected $cleanValidationRules = true;

    /**
     * Field configuration shared by the Form Builder (as 'fields') and the Table Builder (via toColumns()),
     * keyed by field name so a controller can unset() what it does not need.
     * password_hash is left out on purpose: a hash is never shown or edited directly.
     * @return array
     */
    public function getConfiguration(): array
    {
        $fields = [
            'uid'              => [
                'type'     => 'text',
                'show_in'  => ['edit', 'view'], // generated from the email address on create
                'readonly' => true,
            ],
            'email_address'    => ['type' => 'email'],
            'telephone_number' => ['type' => 'tel'],
            'password_expiry'  => ['type' => 'date', 'searchable' => false],
            'full_name'        => ['type' => 'text'],
            'otp_mode'         => ['type' => 'select'],
            'account_status'   => ['type' => 'select'],
            'account_type'     => ['type' => 'select'],
            'admin_notes'      => ['type' => 'textarea', 'rows' => 4, 'orderable' => false],
        ];
        // value => badge colour; the order here is also the order of the select options
        $enums = [
            'otp_mode'       => [self::OTP_EMAIL => 'primary', self::OTP_TELEPHONE => 'success', self::OTP_NONE => 'secondary'],
            'account_status' => [self::STATUS_PENDING => 'warning', self::STATUS_ACTIVE => 'success', self::STATUS_BLOCKED => 'danger', self::STATUS_SUSPENDED => 'secondary'],
            'account_type'   => [self::TYPE_STAFF => 'success', self::TYPE_CLIENT => 'info'],
        ];

        return $this->buildConfiguration('AuthMaster', $fields, $enums);
    }

    /**
     * Generate a unique identifier for an email address
     * @param string $email
     * @return string
     */
    public function generateUid(string $email): string
    {
        $email = strtolower($email);
        return preg_replace('/[^a-z0-9]/', '', $email);
    }
}
