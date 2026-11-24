<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for the otternova_ledger_posting_rule table: which entity books the subscription invoices of a country's
 * businesses from a date on, and into which of its accounts (see App\Libraries\LedgerPosting). At most one rule of a
 * country is in effect on any day.
 */
class OtternovaLedgerPostingRuleModel extends Model
{
    use FieldConfigurationTrait;
    use LedgerWriteTrait;

    /** The rule's accounts, in the order the form shows them */
    public const ACCOUNT_FIELDS = ['receivable_account_id', 'deferred_revenue_account_id', 'revenue_account_id', 'cash_account_id', 'wht_receivable_account_id'];

    /** Columns /staff/ledger/posting-rule/update writes */
    public const FORM_FIELDS   = [
        'entity_id', 'country_code', 'effective_from', 'effective_to',
        'receivable_account_id', 'deferred_revenue_account_id', 'revenue_account_id', 'cash_account_id', 'wht_receivable_account_id',
        'wht_tax_code_id', 'category_id',
    ];
    public const FORM_NULLABLE = ['effective_to', 'wht_receivable_account_id', 'wht_tax_code_id'];

    protected $table            = 'otternova_ledger_posting_rule';
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
        'id'                          => 'permit_empty|is_natural_no_zero',
        'entity_id'                   => 'required|is_natural_no_zero|is_not_unique[otternova_ledger_entity.id]',
        'country_code'                => 'required|regex_match[/^[A-Z]{2}$/]',
        'effective_from'              => 'required|valid_date[Y-m-d]',
        'effective_to'                => 'permit_empty|valid_date[Y-m-d]',
        'receivable_account_id'       => 'required|is_natural_no_zero|is_not_unique[otternova_ledger_account.id]',
        'deferred_revenue_account_id' => 'required|is_natural_no_zero|is_not_unique[otternova_ledger_account.id]',
        'revenue_account_id'          => 'required|is_natural_no_zero|is_not_unique[otternova_ledger_account.id]',
        'cash_account_id'             => 'required|is_natural_no_zero|is_not_unique[otternova_ledger_account.id]',
        'wht_receivable_account_id'   => 'permit_empty|is_natural_no_zero|is_not_unique[otternova_ledger_account.id]',
        'wht_tax_code_id'             => 'permit_empty|is_natural_no_zero|is_not_unique[otternova_ledger_tax_code.id]',
        'category_id'                 => 'required|is_natural_no_zero|is_not_unique[otternova_ledger_category.id]',
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
        $fields = ['entity_id' => ['type' => 'select'], 'country_code' => ['type' => 'text'], 'effective_from' => ['type' => 'date'], 'effective_to' => ['type' => 'date']];
        foreach ([...self::ACCOUNT_FIELDS, 'wht_tax_code_id', 'category_id'] as $field) {
            $fields[$field] = ['type' => 'select', 'orderable' => false];
        }

        return $this->buildConfiguration('OtternovaLedgerPostingRule', $fields);
    }

    /**
     * Every rule, by country and date, with what it points at by public_id and name
     * @return list<array>
     */
    public function listForLookup(): array
    {
        $builder = $this->db->table($this->table . ' r')
            ->select('r.public_id, n.public_id AS entity_public_id, n.entity_name, r.country_code, r.effective_from, r.effective_to')
            ->select('x.public_id AS wht_tax_code_public_id, x.tax_code AS wht_tax_code, c.public_id AS category_public_id, c.category_name')
            ->join('otternova_ledger_entity n', 'n.id = r.entity_id', 'inner')
            ->join('otternova_ledger_tax_code x', 'x.id = r.wht_tax_code_id', 'left')
            ->join('otternova_ledger_category c', 'c.id = r.category_id', 'inner');
        foreach (self::ACCOUNT_FIELDS as $i => $column) {
            $name = str_replace('_id', '', $column);
            $builder->select('a' . $i . '.public_id AS ' . $name . '_public_id, a' . $i . '.account_name AS ' . $name . '_name')
                ->join('otternova_ledger_account a' . $i, 'a' . $i . '.id = r.' . $column, 'left');
        }

        return $builder->orderBy('r.country_code', 'ASC')->orderBy('r.effective_from', 'ASC')->get()->getResultArray();
    }
}
