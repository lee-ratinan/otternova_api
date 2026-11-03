<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for the otternova_ledger_counterparty table: the other side of a ledger entry with an external account
 */
class OtternovaLedgerCounterpartyModel extends Model
{
    use FieldConfigurationTrait;
    use LedgerWriteTrait;

    /** Columns /staff/ledger/counterparty/update writes */
    public const FORM_FIELDS   = ['counterparty_name', 'counterparty_description'];
    public const FORM_NULLABLE = ['counterparty_description'];

    protected $table            = 'otternova_ledger_counterparty';
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
        'id'                       => 'permit_empty|is_natural_no_zero',
        'counterparty_name'        => 'required|max_length[255]',
        'counterparty_description' => 'permit_empty|max_length[65535]',
    ];

    /** public_id: CHAR(8), see PublicIdTrait */
    protected int $publicIdLength = 8;
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
            'counterparty_name'        => ['type' => 'text'],
            'counterparty_description' => ['type' => 'textarea', 'rows' => 3, 'orderable' => false],
        ];

        return $this->buildConfiguration('OtternovaLedgerCounterparty', $fields);
    }

    /**
     * Every counterparty, by name, for the ledger's counterparty list and dropdowns
     * @return list<array{public_id: string, counterparty_name: string, counterparty_description: string|null}>
     */
    public function listForLookup(): array
    {
        return $this->db->table($this->table)
            ->select('public_id, counterparty_name, counterparty_description')
            ->orderBy('counterparty_name', 'ASC')
            ->orderBy('id', 'ASC')
            ->get()
            ->getResultArray();
    }
}
