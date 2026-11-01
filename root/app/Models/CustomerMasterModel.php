<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for the customer_master table: the essential customer data only
 */
class CustomerMasterModel extends Model
{
    use FieldConfigurationTrait;

    protected $table            = 'customer_master';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'email_address',
        'telephone_number',
        'customer_name',
        'last_activity',
    ];

    // created_at and updated_at are maintained by the database
    protected $useTimestamps = false;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $validationRules = [
        'id'               => 'permit_empty|is_natural_no_zero',
        'email_address'    => 'required|valid_email|max_length[64]|is_unique[customer_master.email_address,id,{id}]',
        'telephone_number' => 'permit_empty|max_length[24]',
        'customer_name'    => 'required|max_length[255]',
        'last_activity'    => 'permit_empty|valid_date[Y-m-d H:i:s]',
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
            'email_address'    => ['type' => 'email'],
            'telephone_number' => ['type' => 'tel'],
            'customer_name'    => ['type' => 'text'],
            'last_activity'    => [
                'type'       => 'text',
                'show_in'    => ['view'], // set by the system, not by hand
                'readonly'   => true,
                'searchable' => false,
            ],
        ];

        return $this->buildConfiguration('CustomerMaster', $fields);
    }

    /**
     * Find a customer by email address
     * @param string $email
     * @return array|null
     */
    public function findByEmail(string $email): ?array
    {
        return $this->where('email_address', $email)->first();
    }

    /**
     * Find customers by telephone number; it is not unique, so there can be more than one
     * @param string $telephone
     * @return array
     */
    public function findByTelephone(string $telephone): array
    {
        return $this->where('telephone_number', $telephone)->findAll();
    }

    /**
     * Set last_activity to now, without going through validation
     * @param int $id
     * @return bool
     */
    public function touchLastActivity(int $id): bool
    {
        return $this->builder()
            ->set('last_activity', date('Y-m-d H:i:s'))
            ->where('id', $id)
            ->update();
    }
}
