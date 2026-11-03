<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for the business_type_translation table: a business type's name in one language of
 * listSupportedLanguages(). Written through BusinessTypeModel, which keeps a name for every language.
 */
class BusinessTypeTranslationModel extends Model
{
    protected $table            = 'business_type_translation';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'business_type_id',
        'language_code',
        'type_name',
    ];

    // created_at and updated_at are maintained by the database
    protected $useTimestamps = false;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    // language_code is completed in initialize(), from listSupportedLanguages()
    protected $validationRules = [
        'id'               => 'permit_empty|is_natural_no_zero',
        'business_type_id' => 'required|is_natural_no_zero',
        'language_code'    => 'required',
        'type_name'        => 'required|max_length[255]',
    ];
    protected $validationMessages   = [];
    protected $skipValidation       = false;
    protected $cleanValidationRules = true;

    protected function initialize()
    {
        helper('list');
        $this->validationRules['language_code'] .= '|in_list[' . implode(',', array_keys(listSupportedLanguages())) . ']';
    }
}
