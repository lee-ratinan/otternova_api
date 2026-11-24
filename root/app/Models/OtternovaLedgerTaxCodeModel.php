<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for the otternova_ledger_tax_code table: an entity's VAT and withholding tax codes. A ledger row with a tax
 * code is that tax (its amount), worked out on tax_base_amount; a 0% code goes on the sale or purchase itself.
 * A code in use keeps its kind, rate and account: a new rate is a new code, with effective dates.
 */
class OtternovaLedgerTaxCodeModel extends Model
{
    use FieldConfigurationTrait;
    use LedgerWriteTrait;

    // tax_kind
    public const KIND_VAT_OUTPUT     = 'VO'; // on sales, owed
    public const KIND_VAT_INPUT      = 'VI'; // on purchases, claimable
    public const KIND_WHT_DEDUCTED   = 'WD'; // withheld from a payee, owed to the revenue department
    public const KIND_WHT_SUFFERED   = 'WS'; // withheld by a customer, a credit against income tax

    /** Kinds that OtterNova owes: their tax is positive when it goes out of the tax account (a credit) */
    public const OWED_KINDS = ['VO', 'WD'];

    /** Columns /staff/ledger/tax-code/update writes */
    public const FORM_FIELDS   = ['entity_id', 'tax_code', 'tax_name', 'tax_kind', 'rate', 'account_id', 'effective_from', 'effective_to'];
    public const FORM_NULLABLE = ['account_id', 'effective_to'];

    /** Columns that stay once the code is on an entry */
    public const FIXED_WHEN_USED = ['entity_id', 'tax_kind', 'rate', 'account_id'];

    protected $table            = 'otternova_ledger_tax_code';
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
        'id'             => 'permit_empty|is_natural_no_zero',
        'entity_id'      => 'required|is_natural_no_zero|is_not_unique[otternova_ledger_entity.id]',
        'tax_code'       => 'required|regex_match[/^[A-Z0-9_-]{1,16}$/]',
        'tax_name'       => 'required|max_length[128]',
        'tax_kind'       => 'required|in_list[VO,VI,WD,WS]',
        'rate'           => 'required|regex_match[/^\d{1,3}(\.\d{1,2})?$/]|less_than_equal_to[100]',
        'account_id'     => 'permit_empty|is_natural_no_zero|is_not_unique[otternova_ledger_account.id]',
        'effective_from' => 'required|valid_date[Y-m-d]',
        'effective_to'   => 'permit_empty|valid_date[Y-m-d]',
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
            'entity_id'      => ['type' => 'select'],
            'tax_code'       => ['type' => 'text'],
            'tax_name'       => ['type' => 'text'],
            'tax_kind'       => ['type' => 'select'],
            'rate'           => ['type' => 'number', 'step' => '0.01'],
            'account_id'     => ['type' => 'select'],
            'effective_from' => ['type' => 'date'],
            'effective_to'   => ['type' => 'date'],
        ];

        return $this->buildConfiguration('OtternovaLedgerTaxCode', $fields, [
            'tax_kind' => ['VO' => 'primary', 'VI' => 'info', 'WD' => 'warning', 'WS' => 'secondary'],
        ]);
    }

    /**
     * Every tax code, by entity and code, with the entity and account they point at
     * @return list<array>
     */
    public function listForLookup(): array
    {
        return $this->db->table($this->table . ' x')
            ->select('x.public_id, n.public_id AS entity_public_id, n.entity_name, x.tax_code, x.tax_name, x.tax_kind, x.rate')
            ->select('a.public_id AS account_public_id, a.account_name, x.effective_from, x.effective_to')
            ->join('otternova_ledger_entity n', 'n.id = x.entity_id', 'inner')
            ->join('otternova_ledger_account a', 'a.id = x.account_id', 'left')
            ->orderBy('n.entity_name', 'ASC')
            ->orderBy('x.tax_code', 'ASC')
            ->get()
            ->getResultArray();
    }
}
