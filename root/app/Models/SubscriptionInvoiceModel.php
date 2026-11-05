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
