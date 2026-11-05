<?php

namespace App\Models;

use App\Libraries\Money;
use CodeIgniter\Model;

/**
 * Model for the subscription_invoice table: what a subscription owes for one billing period. Its writes go through
 * App\Libraries\SubscriptionBilling.
 */
class SubscriptionInvoiceModel extends Model
{
    use PublicIdTrait;

    /** invoice_status */
    public const STATUS_PENDING = 'P';
    public const STATUS_PAID    = 'D';
    public const STATUS_VOID    = 'V';

    protected $table            = 'subscription_invoice';
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
     * The subscription's pending invoices, as stored
     * @param int $subscriptionId
     * @return list<array>
     */
    public function pendingOf(int $subscriptionId): array
    {
        return $this->db->table($this->table)
            ->where('subscription_id', $subscriptionId)
            ->where('invoice_status', self::STATUS_PENDING)
            ->orderBy('id', 'ASC')
            ->get()
            ->getResultArray();
    }

    /**
     * The subscription's invoices as the API sends them, newest first, each with its payments
     * @param int  $subscriptionId
     * @param bool $forStaff       Adds who confirmed each payment
     * @return list<array>
     */
    public function payloadsOfSubscription(int $subscriptionId, bool $forStaff): array
    {
        $rows = $this->joinedBuilder()->where('i.subscription_id', $subscriptionId)->orderBy('i.id', 'DESC')->get()->getResultArray();

        return $this->withPayments($rows, $forStaff);
    }

    /**
     * One page of the business's invoices, of every subscription it has had, newest first, each with its payments
     * @param int $businessId
     * @param int $start
     * @param int $length
     * @return array{records_total: int, rows: list<array>}
     */
    public function pageOfBusiness(int $businessId, int $start, int $length): array
    {
        $builder = $this->joinedBuilder()->join('subscription_master s', 's.id = i.subscription_id', 'inner')->where('s.business_id', $businessId);
        $total   = $builder->countAllResults(false);
        $rows    = $builder->select('s.public_id AS subscription_public_id')->orderBy('i.id', 'DESC')->limit($length, $start)->get()->getResultArray();

        return ['records_total' => $total, 'rows' => $this->withPayments($rows, false)];
    }

    /**
     * One page of every business's invoices for the staff, each with its business, its subscription and how many of
     * its payments wait for review (pending_payments). The unpaid ones (invoice_status P) come oldest due first, so
     * the longest waiting are seen first; any other list comes newest first.
     * @param int                   $start
     * @param int                   $length
     * @param string                $search  Matched in the invoice number and the business's name and slug
     * @param array<string, string> $filters invoice_status, plan_code, awaiting_review (Y: only those with a payment to review)
     * @return array{records_total: int, records_filtered: int, rows: list<array>}
     */
    public function listForStaff(int $start, int $length, string $search, array $filters): array
    {
        $total   = $this->db->table($this->table)->countAllResults();
        $builder = $this->staffBuilder();
        if (isset($filters['invoice_status'])) {
            $builder->where('i.invoice_status', $filters['invoice_status']);
        }
        if (isset($filters['plan_code'])) {
            $builder->where('p.plan_code', $filters['plan_code']);
        }
        if (($filters['awaiting_review'] ?? '') === 'Y') {
            $builder->where('EXISTS (SELECT 1 FROM payment_transaction w WHERE w.invoice_id = i.id AND w.txn_status = '
                . $this->db->escape(PaymentTransactionModel::STATUS_PENDING) . ')', null, false);
        }
        if ($search !== '') {
            $char = $this->db->likeEscapeChar;
            $term = str_replace([$char, '%', '_'], [$char . $char, $char . '%', $char . '_'], $search);
            $builder->groupStart()
                ->like('i.invoice_no', $term, 'both', null, true)
                ->orLike('b.business_name', $term, 'both', null, true)
                ->orLike('b.business_slug', $term, 'both', null, true)
                ->groupEnd();
        }
        $filtered = $builder->countAllResults(false);
        if (($filters['invoice_status'] ?? '') === self::STATUS_PENDING) {
            $builder->orderBy('i.due_at', 'ASC');
        }
        $rows = $builder->orderBy('i.id', 'DESC')->limit($length, $start)->get()->getResultArray();

        return ['records_total' => $total, 'records_filtered' => $filtered, 'rows' => array_map(self::staffRow(...), $rows)];
    }

    /**
     * One invoice for the staff, with its business, its subscription and its payments (oldest first, with who
     * confirmed them)
     * @param mixed $publicId From the request
     * @return array|null
     */
    public function findForStaff(mixed $publicId): ?array
    {
        if (! is_string($publicId) || $publicId === '') {
            return null;
        }
        $row = $this->staffBuilder()->where('i.public_id', $publicId)->get()->getRowArray();
        if ($row === null) {
            return null;
        }
        $row = $this->withPayments([$row], true)[0];
        $row['pending_payments'] = (int) $row['pending_payments'];

        return $row;
    }

    /**
     * The invoices joined with their subscription and business, and the count of payments waiting for review
     * @return \CodeIgniter\Database\BaseBuilder
     */
    private function staffBuilder()
    {
        return $this->joinedBuilder()
            ->select('s.public_id AS subscription_public_id, s.subscription_status, b.business_slug, b.business_name, b.country_code AS business_country_code')
            ->select('(SELECT COUNT(*) FROM payment_transaction w WHERE w.invoice_id = i.id AND w.txn_status = '
                . $this->db->escape(PaymentTransactionModel::STATUS_PENDING) . ') AS pending_payments', false)
            ->join('subscription_master s', 's.id = i.subscription_id', 'inner')
            ->join('business_master b', 'b.id = s.business_id', 'inner');
    }

    /**
     * A row of listForStaff() as the API sends it: no internal id, amounts written out
     * @param array $row
     * @return array
     */
    private static function staffRow(array $row): array
    {
        foreach (['list_price', 'discount_amount', 'amount_due'] as $field) {
            $row[$field] = Money::format($row[$field]);
        }
        $row['pending_payments'] = (int) $row['pending_payments'];
        unset($row['_id']);

        return $row;
    }

    /**
     * @param list<array> $rows Invoice payloads, with the internal id as _id
     * @param bool        $forStaff
     * @return list<array>
     */
    private function withPayments(array $rows, bool $forStaff): array
    {
        $payments = model(PaymentTransactionModel::class)->payloadsOfInvoices(array_column($rows, '_id'), $forStaff);
        foreach ($rows as &$row) {
            $row['payments'] = $payments[(int) $row['_id']] ?? [];
            foreach (['list_price', 'discount_amount', 'amount_due'] as $field) {
                $row[$field] = Money::format($row[$field]);
            }
            unset($row['_id']);
        }

        return $rows;
    }

    /**
     * @return \CodeIgniter\Database\BaseBuilder
     */
    private function joinedBuilder()
    {
        return $this->db->table($this->table . ' i')
            ->select('i.id AS _id, i.public_id, i.invoice_no, p.plan_code, v.billing_cycle, i.currency_code, i.list_price')
            ->select('d.discount_code, i.discount_amount, i.amount_due, i.invoice_status, i.due_at, i.paid_at, i.voided_at')
            ->select('i.period_start, i.period_end, i.created_at')
            ->join('plan_version v', 'v.id = i.plan_version_id', 'inner')
            ->join('plan_master p', 'p.id = v.plan_id', 'inner')
            ->join('discount_master d', 'd.id = i.discount_id', 'left');
    }
}
