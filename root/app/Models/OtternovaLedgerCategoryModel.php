<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for the otternova_ledger_category table: what a ledger entry is for
 */
class OtternovaLedgerCategoryModel extends Model
{
    use FieldConfigurationTrait;
    use LedgerWriteTrait;

    /** Columns /staff/ledger/category/update writes */
    public const FORM_FIELDS   = ['category_code', 'category_name'];
    public const FORM_NULLABLE = [];

    protected $table            = 'otternova_ledger_category';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = self::FORM_FIELDS;

    // created_at and updated_at are maintained by the database
    protected $useTimestamps = false;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $validationRules = [
        'id'            => 'permit_empty|is_natural_no_zero',
        'category_code' => 'required|regex_match[/^[A-Z0-9]{3}$/]|is_unique[otternova_ledger_category.category_code,id,{id}]', // e.g. INF
        'category_name' => 'required|max_length[128]',
    ];

    /** public_id: CHAR(4), see PublicIdTrait */
    protected int $publicIdLength = 4;
    protected $beforeInsert       = ['fillPublicId'];
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
            'category_code' => ['type' => 'text'],
            'category_name' => ['type' => 'text'],
        ];

        return $this->buildConfiguration('OtternovaLedgerCategory', $fields);
    }

    /**
     * Every category, by name, for the ledger's category list and dropdowns
     * @return list<array{public_id: string, category_code: string, category_name: string}>
     */
    public function listForLookup(): array
    {
        return $this->db->table($this->table)
            ->select('public_id, category_code, category_name')
            ->orderBy('category_name', 'ASC')
            ->orderBy('id', 'ASC')
            ->get()
            ->getResultArray();
    }
}
