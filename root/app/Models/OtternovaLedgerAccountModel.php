<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for the otternova_ledger_account table: where the money of a ledger entry comes from and goes to
 */
class OtternovaLedgerAccountModel extends Model
{
    use FieldConfigurationTrait;
    use LedgerWriteTrait;

    // account_type
    public const TYPE_INTERNAL = 'I';
    public const TYPE_EXTERNAL = 'X';

    /** Columns /staff/ledger/account/update writes */
    public const FORM_FIELDS   = ['account_name', 'account_type', 'currency_code', 'account_class'];
    public const FORM_NULLABLE = ['account_class'];

    protected $table            = 'otternova_ledger_account';
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
        'account_name'  => 'required|max_length[255]|is_unique[otternova_ledger_account.account_name,id,{id}]',
        'account_type'  => 'required|in_list[I,X]',
        'currency_code' => 'required|regex_match[/^[A-Z]{3}$/]', // any ISO 4217 code: bills come in currencies OtterNova does not sell in
        'account_class' => 'permit_empty|in_list[A,L,E,R,X]',
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
            'account_name'  => ['type' => 'text'],
            'account_type'  => ['type' => 'select'],
            'currency_code' => ['type' => 'select', 'options' => listCurrencies(), 'searchable' => false],
            'account_class' => ['type' => 'select'],
        ];
        $enums  = [
            'account_type'  => [self::TYPE_INTERNAL => 'primary', self::TYPE_EXTERNAL => 'secondary'],
            'account_class' => ['A', 'L', 'E', 'R', 'X'],
        ];

        return $this->buildConfiguration('OtternovaLedgerAccount', $fields, $enums);
    }

    /**
     * Every account, by name, for the ledger's account list and dropdowns
     * @return list<array{public_id: string, account_name: string, account_type: string, currency_code: string, account_class: string|null}>
     */
    public function listForLookup(): array
    {
        return $this->db->table($this->table)
            ->select('public_id, account_name, account_type, currency_code, account_class')
            ->orderBy('account_name', 'ASC')
            ->orderBy('id', 'ASC')
            ->get()
            ->getResultArray();
    }
}
