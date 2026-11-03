<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for the business_master_translation table: a business's name in one language of listLanguages().
 * Optional; business_master.business_name is shown where a language has none. Written through BusinessMasterModel.
 */
class BusinessMasterTranslationModel extends Model
{
    protected $table            = 'business_master_translation';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'business_id',
        'language_code',
        'business_name',
    ];

    // created_at and updated_at are maintained by the database
    protected $useTimestamps = false;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    // language_code is completed in initialize(), from listLanguages()
    protected $validationRules = [
        'id'            => 'permit_empty|is_natural_no_zero',
        'business_id'   => 'required|is_natural_no_zero',
        'language_code' => 'required',
        'business_name' => 'required|max_length[255]',
    ];
    protected $validationMessages   = [];
    protected $skipValidation       = false;
    protected $cleanValidationRules = true;

    protected function initialize()
    {
        helper('list');
        $this->validationRules['language_code'] .= '|in_list[' . implode(',', array_keys(listLanguages())) . ']';
    }
}
