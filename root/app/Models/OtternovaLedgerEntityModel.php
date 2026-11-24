<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for the otternova_ledger_entity table: a legal entity whose books the ledger keeps. A country can have
 * several, such as a sole proprietorship and the company that later takes over from it. Every ledger account
 * belongs to one, and the financial reports are per entity, in its base currency, with its fiscal year.
 */
class OtternovaLedgerEntityModel extends Model
{
    use FieldConfigurationTrait;
    use LedgerWriteTrait;

    /** Columns /staff/ledger/entity/update writes */
    public const FORM_FIELDS   = [
        'entity_name',
        'legal_form',
        'country_code',
        'tax_id',
        'base_currency_code',
        'timezone',
        'fiscal_year_start_month',
        'vat_threshold_amount',
        'vat_registered_on',
        'registered_on',
        'closed_on',
    ];
    public const FORM_NULLABLE = ['legal_form', 'tax_id', 'vat_threshold_amount', 'vat_registered_on', 'registered_on', 'closed_on'];

    protected $table            = 'otternova_ledger_entity';
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
        'id'                      => 'permit_empty|is_natural_no_zero',
        'entity_name'             => 'required|max_length[255]|is_unique[otternova_ledger_entity.entity_name,id,{id}]',
        'legal_form'              => 'permit_empty|max_length[64]',
        'country_code'            => 'required|regex_match[/^[A-Z]{2}$/]',
        'tax_id'                  => 'permit_empty|max_length[32]',
        'base_currency_code'      => 'required|regex_match[/^[A-Z]{3}$/]',
        'timezone'                => 'required|timezone',
        'fiscal_year_start_month' => 'required|is_natural_no_zero|less_than_equal_to[12]',
        'vat_threshold_amount'    => 'permit_empty|regex_match[/^\d{1,15}(\.\d{1,4})?$/]',
        'vat_registered_on'       => 'permit_empty|valid_date[Y-m-d]',
        'registered_on'           => 'permit_empty|valid_date[Y-m-d]',
        'closed_on'               => 'permit_empty|valid_date[Y-m-d]',
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
        $months = [];
        foreach (range(1, 12) as $month) {
            $months[(string) $month] = date('F', mktime(0, 0, 0, $month, 1));
        }
        $fields = [
            'entity_name'             => ['type' => 'text'],
            'legal_form'              => ['type' => 'text'],
            'country_code'            => ['type' => 'text'],
            'tax_id'                  => ['type' => 'text', 'orderable' => false],
            'base_currency_code'      => ['type' => 'select', 'options' => listCurrencies(), 'searchable' => false],
            'timezone'                => ['type' => 'text'],
            'fiscal_year_start_month' => ['type' => 'select', 'options' => $months, 'labels' => $months, 'searchable' => false],
            'vat_threshold_amount'    => ['type' => 'number', 'step' => '0.01'],
            'vat_registered_on'       => ['type' => 'date'],
            'registered_on'           => ['type' => 'date'],
            'closed_on'               => ['type' => 'date'],
        ];

        return $this->buildConfiguration('OtternovaLedgerEntity', $fields);
    }

    /**
     * Every entity, by name, for the entity list and dropdowns
     * @return list<array>
     */
    public function listForLookup(): array
    {
        return $this->db->table($this->table)
            ->select('public_id, entity_name, legal_form, country_code, tax_id, base_currency_code, timezone, fiscal_year_start_month, vat_threshold_amount, vat_registered_on, registered_on, closed_on, closed_through')
            ->orderBy('entity_name', 'ASC')
            ->orderBy('id', 'ASC')
            ->get()
            ->getResultArray();
    }
}
