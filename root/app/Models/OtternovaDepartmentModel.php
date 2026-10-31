<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for the staff_master table
 */
class OtternovaDepartmentModel extends Model
{
    use FieldConfigurationTrait;

    protected $table            = 'otternova_department';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'department_code',
        'department_name',
        'country_code',
    ];

    // created_at and updated_at are maintained by the database
    protected $useTimestamps = false;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $validationRules = [
        'department_code' => 'required|max_length[3]|is_unique[otternova_department.department_code,id,{id}]',
        'department_name' => 'required|max_length[255]',
        'country_code'    => 'required|alpha|exact_length[2]',
    ];
    protected $validationMessages   = [];
    protected $skipValidation       = false;
    protected $cleanValidationRules = true;

    /**
     * Field configuration shared by the Form Builder (as 'fields') and the Table Builder (via toColumns()),
     * keyed by field name so a controller can unset() what it does not need.
     * department_id and manager_staff_id are plain IDs until there is a department/staff lookup to build options from.
     * @return array
     */
    public function getConfiguration(): array
    {
        $countries = listCountries();
        $fields    = [
            'department_code' => ['type' => 'text', 'readonly_in' => ['edit']],
            'department_name' => ['type' => 'text'],
            'country_code'    => ['type' => 'select', 'options' => $countries, 'searchable' => false],
        ];
        $enums     = [];

        return $this->buildConfiguration('StaffMaster', $fields, $enums);
    }

}