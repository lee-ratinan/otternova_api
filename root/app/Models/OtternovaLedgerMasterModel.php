<?php

namespace App\Models;

use App\Libraries\ActivityLog;
use CodeIgniter\Model;
use Throwable;

/**
 * Model for the otternova_ledger_master table: one money movement per row, out of from_account_id and into
 * to_account_id. There is no credit/debit column: an entry is a credit to its from account and a debit to its to
 * account, so which one it is depends on the account looked at.
 */
class OtternovaLedgerMasterModel extends Model
{
    use FieldConfigurationTrait;
    use LedgerWriteTrait;

    /** Columns /staff/ledger/entry/update writes; receipt_file_path is set by the receipt upload, created_by on insert */
    public const FORM_FIELDS   = [
        'transaction_date',
        'transaction_detail',
        'reference_number',
        'category_id',
        'transaction_currency_code',
        'transaction_amount',
        'billed_currency_code',
        'billed_amount',
        'from_account_id',
        'to_account_id',
        'counterparty_id',
    ];
    public const FORM_NULLABLE = ['reference_number', 'counterparty_id'];

    /** Filters of the entry list: filter name => what it matches */
    public const LIST_FILTERS = ['date_from', 'date_to', 'account_id', 'category_id', 'counterparty_id'];

    /** Up to 15 digits before the point and 4 after, as DECIMAL(19,4) holds */
    public const AMOUNT_PATTERN = '/^\d{1,15}(\.\d{1,4})?$/';

    protected $table            = 'otternova_ledger_master';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        ...self::FORM_FIELDS,
        'receipt_file_path',
        'created_by',
    ];

    // created_at and updated_at are maintained by the database
    protected $useTimestamps = false;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $validationRules = [
        'id'                        => 'permit_empty|is_natural_no_zero',
        'transaction_date'          => 'required|valid_date[Y-m-d]',
        'transaction_detail'        => 'required|max_length[65535]',
        'reference_number'          => 'permit_empty|max_length[128]',
        'category_id'               => 'required|is_natural_no_zero|is_not_unique[otternova_ledger_category.id]',
        'transaction_currency_code' => 'required|regex_match[/^[A-Z]{3}$/]',
        'transaction_amount'        => 'required|regex_match[/^\d{1,15}(\.\d{1,4})?$/]|greater_than[0]',
        'billed_currency_code'      => 'required|regex_match[/^[A-Z]{3}$/]',
        'billed_amount'             => 'required|regex_match[/^\d{1,15}(\.\d{1,4})?$/]',
        'from_account_id'           => 'required|is_natural_no_zero|is_not_unique[otternova_ledger_account.id]',
        'to_account_id'             => 'required|is_natural_no_zero|is_not_unique[otternova_ledger_account.id]|differs[from_account_id]',
        'counterparty_id'           => 'permit_empty|is_natural_no_zero|is_not_unique[otternova_ledger_counterparty.id]',
    ];
    protected $validationMessages   = [];
    protected $skipValidation       = false;
    protected $cleanValidationRules = true;

    /**
     * Field configuration shared by the Form Builder (as 'fields') and the Table Builder (via toColumns()),
     * keyed by field name so a controller can unset() what it does not need.
     * The account, category and counterparty IDs take their options from the /staff/ledger lookups.
     * @return array
     */
    public function getConfiguration(): array
    {
        $fields = [
            'transaction_date'          => ['type' => 'date'],
            'transaction_detail'        => ['type' => 'textarea', 'rows' => 3, 'orderable' => false],
            'reference_number'          => ['type' => 'text'],
            'category_id'               => ['type' => 'select'],
            'transaction_currency_code' => ['type' => 'select', 'options' => listCurrencies(), 'searchable' => false],
            'transaction_amount'        => ['type' => 'number', 'step' => '0.0001'],
            'billed_currency_code'      => ['type' => 'select', 'options' => listCurrencies(), 'searchable' => false],
            'billed_amount'             => ['type' => 'number', 'step' => '0.0001'],
            'from_account_id'           => ['type' => 'select'],
            'to_account_id'             => ['type' => 'select'],
            'counterparty_id'           => ['type' => 'select'],
        ];

        return $this->buildConfiguration('OtternovaLedgerMaster', $fields);
    }

    /**
     * One page of entries, newest transaction first, with the names of what they point at.
     * The filters must already be checked against LIST_FILTERS.
     * @param int                   $start   Offset
     * @param int                   $length  Page size
     * @param string                $search  Matched in transaction_detail and reference_number; empty for no search
     * @param array<string, string> $filters LIST_FILTERS name => value; account_id matches either side
     * @return array{records_total: int, records_filtered: int, rows: list<array>}
     */
    public function listEntries(int $start, int $length, string $search, array $filters): array
    {
        $total   = $this->db->table($this->table)->countAllResults();
        $builder = $this->joinedBuilder();
        foreach ($filters as $name => $value) {
            match ($name) {
                'date_from'  => $builder->where('e.transaction_date >=', $value),
                'date_to'    => $builder->where('e.transaction_date <=', $value),
                'account_id' => $builder->groupStart()->where('e.from_account_id', $value)->orWhere('e.to_account_id', $value)->groupEnd(),
                default      => $builder->where('e.' . $name, $value),
            };
        }
        if ($search !== '') {
            // like() binds the term and adds ESCAPE '!', but leaves % and _ in it as wildcards, so escape those here
            $char = $this->db->likeEscapeChar;
            $term = str_replace([$char, '%', '_'], [$char . $char, $char . '%', $char . '_'], $search);
            $builder->groupStart()
                ->like('e.transaction_detail', $term, 'both', null, true)
                ->orLike('e.reference_number', $term, 'both', null, true)
                ->groupEnd();
        }
        $filtered = $builder->countAllResults(false);
        // a unique last key, so rows with equal dates never swap between pages
        $rows = $builder->orderBy('e.transaction_date', 'DESC')->orderBy('e.id', 'DESC')->limit($length, $start)->get()->getResultArray();

        return ['records_total' => $total, 'records_filtered' => $filtered, 'rows' => array_map(self::toPayload(...), $rows)];
    }

    /**
     * One entry with the names of what it points at, as the API sends it
     * @param int $id
     * @return array|null
     */
    public function findEntry(int $id): ?array
    {
        $row = $this->joinedBuilder()->where('e.id', $id)->get()->getRowArray();

        return $row === null ? null : self::toPayload($row);
    }

    /**
     * The stored row, for an update
     * @param int $id
     * @return array|null
     */
    public function findStored(int $id): ?array
    {
        return $this->db->table($this->table)->where('id', $id)->get()->getRowArray();
    }

    /**
     * Save the receipt path (null to clear it) with its log_activity row; $place puts the file in place, or deletes
     * it, inside the transaction, so the row and the file never disagree when it fails
     * @param array       $stored  The stored row
     * @param string|null $path    File name under Config\LedgerReceipt::$uploadPath
     * @param int         $actorId auth_master.id of whoever uploaded or deleted it
     * @param string      $summary
     * @param callable    $place   Throws when the file could not be put in place or deleted
     * @return void
     * @throws Throwable
     */
    public function setReceipt(array $stored, ?string $path, int $actorId, string $summary, callable $place): void
    {
        $this->inTransaction(function () use ($stored, $path, $actorId, $summary, $place) {
            $this->db->table($this->table)->where('id', $stored['id'])->update(['receipt_file_path' => $path]);
            (new ActivityLog())->updated($actorId, $summary, $this->table, (int) $stored['id'], $stored, ['receipt_file_path' => $path]);
            $place();
        });
    }

    /**
     * @return \CodeIgniter\Database\BaseBuilder
     */
    private function joinedBuilder()
    {
        return $this->db->table($this->table . ' e')
            ->select('e.id, e.transaction_date, e.transaction_detail, e.reference_number, e.category_id, c.category_code, c.category_name')
            ->select('e.transaction_currency_code, e.transaction_amount, e.billed_currency_code, e.billed_amount')
            ->select('e.from_account_id, f.account_name AS from_account_name, f.account_type AS from_account_type')
            ->select('e.to_account_id, t.account_name AS to_account_name, t.account_type AS to_account_type')
            ->select('e.counterparty_id, p.counterparty_name, e.receipt_file_path, e.created_by, a.full_name AS created_by_name')
            ->select('e.created_at, e.updated_at')
            ->join('otternova_ledger_category c', 'c.id = e.category_id', 'inner')
            ->join('otternova_ledger_account f', 'f.id = e.from_account_id', 'inner')
            ->join('otternova_ledger_account t', 't.id = e.to_account_id', 'inner')
            ->join('otternova_ledger_counterparty p', 'p.id = e.counterparty_id', 'left')
            ->join('auth_master a', 'a.id = e.created_by', 'left');
    }

    /**
     * IDs as integers; the receipt's file name stays inside, has_receipt says whether there is one
     * @param array $row
     * @return array
     */
    private static function toPayload(array $row): array
    {
        foreach (['id', 'category_id', 'from_account_id', 'to_account_id', 'counterparty_id', 'created_by'] as $field) {
            $row[$field] = $row[$field] === null ? null : (int) $row[$field];
        }
        $row['has_receipt'] = $row['receipt_file_path'] !== null && $row['receipt_file_path'] !== '';
        unset($row['receipt_file_path']);

        return $row;
    }
}
