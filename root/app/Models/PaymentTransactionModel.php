<?php

namespace App\Models;

use App\Libraries\Money;
use CodeIgniter\Model;

/**
 * Model for the payment_transaction table: one try at paying an invoice. Its writes go through
 * App\Libraries\SubscriptionBilling.
 */
class PaymentTransactionModel extends Model
{
    use PublicIdTrait;

    /** txn_status */
    public const STATUS_PENDING   = 'P';
    public const STATUS_SUCCEEDED = 'S';
    public const STATUS_FAILED    = 'F';

    protected $table            = 'payment_transaction';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [];

    // created_at and updated_at are maintained by the database
    protected $useTimestamps = false;

    /** public_id: CHAR(12), see PublicIdTrait */
    protected int $publicIdLength = 12;
    protected $beforeInsert       = ['fillPublicId'];

    /**
     * Whether the invoice has a payment waiting for confirmation
     * @param int $invoiceId
     * @return bool
     */
    public function hasPending(int $invoiceId): bool
    {
        return $this->db->table($this->table)->where('invoice_id', $invoiceId)->where('txn_status', self::STATUS_PENDING)->countAllResults() > 0;
    }

    /**
     * The payments of the invoices as the API sends them, oldest first
     * @param list<int> $invoiceIds
     * @param bool      $forStaff   Adds who confirmed each one
     * @return array<int, list<array>> invoice id => payments
     */
    public function payloadsOfInvoices(array $invoiceIds, bool $forStaff): array
    {
        if ($invoiceIds === []) {
            return [];
        }
        $rows = $this->joinedBuilder($forStaff)->select('t.invoice_id AS _invoice_id')->whereIn('t.invoice_id', $invoiceIds)->orderBy('t.id', 'ASC')->get()->getResultArray();

        $byInvoice = [];
        foreach ($rows as $row) {
            $invoiceId = (int) $row['_invoice_id'];
            unset($row['_invoice_id']);
            $byInvoice[$invoiceId][] = self::toPayload($row);
        }

        return $byInvoice;
    }

    /**
     * One page of payments for the staff, oldest first, so that the slips waiting longest come up first
     * @param int                   $start
     * @param int                   $length
     * @param array<string, string> $filters txn_status, method_code
     * @return array{records_total: int, records_filtered: int, rows: list<array>}
     */
    public function listPayments(int $start, int $length, array $filters): array
    {
        $total   = $this->db->table($this->table)->countAllResults();
        $builder = $this->joinedBuilder(true)
            ->select('i.public_id AS invoice_public_id, i.invoice_no, i.amount_due, i.invoice_status, s.public_id AS subscription_public_id')
            ->select('b.business_slug, b.business_name')
            ->join('subscription_invoice i', 'i.id = t.invoice_id', 'inner')
            ->join('subscription_master s', 's.id = i.subscription_id', 'inner')
            ->join('business_master b', 'b.id = s.business_id', 'inner');
        if (isset($filters['txn_status'])) {
            $builder->where('t.txn_status', $filters['txn_status']);
        }
        if (isset($filters['method_code'])) {
            $builder->where('m.method_code', $filters['method_code']);
        }
        $filtered = $builder->countAllResults(false);
        $rows     = $builder->orderBy('t.id', 'ASC')->limit($length, $start)->get()->getResultArray();

        return ['records_total' => $total, 'records_filtered' => $filtered, 'rows' => array_map(self::toPayload(...), $rows)];
    }

    /**
     * @param bool $forStaff
     * @return \CodeIgniter\Database\BaseBuilder
     */
    private function joinedBuilder(bool $forStaff)
    {
        $builder = $this->db->table($this->table . ' t')
            ->select('t.public_id, m.method_code, t.currency_code, t.amount, t.txn_status, t.provider_ref, t.slip_path, t.failure_reason')
            ->select('t.confirmed_at, t.created_at')
            ->join('payment_method m', 'm.id = t.payment_method_id', 'inner');
        if ($forStaff) {
            $builder->select('a.full_name AS confirmed_by_name')->join('auth_master a', 'a.id = t.confirmed_by', 'left');
        }

        return $builder;
    }

    /**
     * The slip's file name stays inside; has_slip says whether there is one
     * @param array $row
     * @return array
     */
    private static function toPayload(array $row): array
    {
        $row['has_slip'] = $row['slip_path'] !== null && $row['slip_path'] !== '';
        $row['amount']   = Money::format($row['amount']);
        if (array_key_exists('amount_due', $row)) {
            $row['amount_due'] = Money::format($row['amount_due']);
        }
        unset($row['slip_path']);

        return $row;
    }
}
