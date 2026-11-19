<?php

namespace App\Models;

use App\Libraries\Money;
use CodeIgniter\Model;

/**
 * The customers of a business, as its orders tell them: customer_master is shared by every business, so a business's
 * customers are the customer_master rows its order_master rows point to (guest orders, with no customer_id, are not
 * customers). What a business sees of one is what they gave it: the name, email address and telephone number of their
 * latest order with it, never customer_master's own values or last_activity, which come from every business.
 * Read only; the notes the business writes about them are BusinessCustomerNoteModel's.
 */
class BusinessCustomerModel extends Model
{
    protected $table      = 'order_master';
    protected $primaryKey = 'id';
    protected $returnType = 'array';

    /** The words of a search looked for at most */
    public const MAX_SEARCH_WORDS = 5;

    /** The orders show() lists, newest first */
    public const RECENT_ORDERS = 20;

    /**
     * One page of the customers of a business, the one who ordered last first
     * @param int    $businessId
     * @param int    $start
     * @param int    $length
     * @param string $search Words each found in the first or last name, email address or telephone number of any of
     *                       their orders with the business
     * @return array{records_total: int, records_filtered: int, rows: list<array>} rows: as summaries() gives them
     */
    public function listOfBusiness(int $businessId, int $start, int $length, string $search = ''): array
    {
        $count = function (string $search) use ($businessId): int {
            $row = $this->searched($businessId, $search)->select('COUNT(DISTINCT o.customer_id) AS customers', false)->get()->getRowArray();

            return (int) ($row['customers'] ?? 0);
        };
        $total    = $count('');
        $filtered = $search === '' ? $total : $count($search);
        $rows     = $this->searched($businessId, $search)
            ->select('o.customer_id, MAX(o.created_at) AS last_order_at, MAX(o.id) AS last_order_id', false)
            ->groupBy('o.customer_id')
            ->orderBy('last_order_at', 'DESC')
            ->orderBy('last_order_id', 'DESC')
            ->limit($length, $start)
            ->get()
            ->getResultArray();

        return ['records_total' => $total, 'records_filtered' => $filtered,
            'rows' => $this->summaries($businessId, array_map('intval', array_column($rows, 'customer_id')))];
    }

    /**
     * Whether the customer has ordered from the business: else they are none of its business
     * @param int $businessId
     * @param int $customerId
     * @return bool
     */
    public function isCustomerOf(int $businessId, int $customerId): bool
    {
        return $this->db->table($this->table)->where('business_id', $businessId)->where('customer_id', $customerId)->countAllResults() > 0;
    }

    /**
     * The customers as the business sees them, in the order of $customerIds
     * @param int       $businessId
     * @param list<int> $customerIds
     * @return list<array{public_id: string, customer_name: string, email_address: string, telephone_number: string,
     *   order_count: int, first_order_at: string, last_order_at: string, currency_code: string, net_paid: string,
     *   no_show_count: int, note_count: int}> net_paid: the succeeded payments of their orders less the refunds
     */
    public function summaries(int $businessId, array $customerIds): array
    {
        if ($customerIds === []) {
            return [];
        }
        $stats  = $this->db->table($this->table . ' o')
            ->select('o.customer_id, COUNT(*) AS order_count, MIN(o.created_at) AS first_order_at, MAX(o.created_at) AS last_order_at, MAX(o.id) AS last_order_id', false)
            ->where('o.business_id', $businessId)
            ->whereIn('o.customer_id', $customerIds)
            ->groupBy('o.customer_id')
            ->get()
            ->getResultArray();
        $stats = array_column($stats, null, 'customer_id');
        if ($stats === []) {
            return [];
        }
        // the contact details of each one's latest order
        $latest = $this->db->table($this->table . ' o')
            ->select('o.customer_id, o.first_name, o.last_name, o.email_address, o.telephone_number, o.currency_code, c.public_id')
            ->join('customer_master c', 'c.id = o.customer_id')
            ->whereIn('o.id', array_map('intval', array_column($stats, 'last_order_id')))
            ->get()
            ->getResultArray();
        $latest = array_column($latest, null, 'customer_id');
        $paid   = $this->db->table('order_payment p')
            ->select("o.customer_id, SUM(CASE WHEN p.transaction_type = 'refund' THEN -p.amount ELSE p.amount END) AS net_paid", false)
            ->join($this->table . ' o', 'o.id = p.order_id')
            ->where('o.business_id', $businessId)
            ->whereIn('o.customer_id', $customerIds)
            ->where('p.transaction_status', 'succeeded')
            ->groupBy('o.customer_id')
            ->get()
            ->getResultArray();
        $paid    = array_column($paid, 'net_paid', 'customer_id');
        $counted = fn (string $table, array $where = []): array => array_column($this->db->table($table)
            ->select('customer_id, COUNT(*) AS n')
            ->where('business_id', $businessId)
            ->whereIn('customer_id', $customerIds)
            ->where($where)
            ->groupBy('customer_id')
            ->get()
            ->getResultArray(), 'n', 'customer_id');
        $noShows = $counted(SalesOrderModel::FLAGS, ['flag_type' => SalesOrderModel::FLAG_NO_SHOW]);
        $notes   = $counted(BusinessCustomerNoteModel::TABLE);

        $out = [];
        foreach ($customerIds as $id) {
            if (! isset($stats[$id], $latest[$id])) {
                continue;
            }
            $order = $latest[$id];
            $out[] = [
                'public_id'        => $order['public_id'],
                'customer_name'    => trim($order['first_name'] . ' ' . $order['last_name']),
                'email_address'    => $order['email_address'],
                'telephone_number' => $order['telephone_number'],
                'order_count'      => (int) $stats[$id]['order_count'],
                'first_order_at'   => $stats[$id]['first_order_at'],
                'last_order_at'    => $stats[$id]['last_order_at'],
                'currency_code'    => $order['currency_code'],
                'net_paid'         => Money::format($paid[$id] ?? 0),
                'no_show_count'    => (int) ($noShows[$id] ?? 0),
                'note_count'       => (int) ($notes[$id] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * The customer's latest orders with the business, newest first
     * @param int $businessId
     * @param int $customerId
     * @param int $limit
     * @return list<array{public_id: string, order_number: string|null, created_at: string, currency_code: string,
     *   total_amount: string, order_status: string, financial_status: string|null, fulfillment_status: string|null,
     *   cancelled_at: string|null, no_show_at: string|null}>
     */
    public function recentOrders(int $businessId, int $customerId, int $limit = self::RECENT_ORDERS): array
    {
        $rows = $this->db->table($this->table)
            ->select('public_id, order_number, created_at, currency_code, total_amount, order_status, financial_status, fulfillment_status, cancelled_at, no_show_at')
            ->where('business_id', $businessId)
            ->where('customer_id', $customerId)
            ->orderBy('created_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->limit($limit)
            ->get()
            ->getResultArray();

        return array_map(static fn (array $row): array => ['total_amount' => Money::format($row['total_amount'])] + $row, $rows);
    }

    /**
     * The orders of the business with a customer, with the search's words each found in one of their contact columns
     */
    private function searched(int $businessId, string $search)
    {
        $builder = $this->db->table($this->table . ' o')->where('o.business_id', $businessId)->where('o.customer_id IS NOT NULL', null, false);
        $words   = preg_split('/\s+/u', trim($search), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach (array_slice(array_values(array_unique($words)), 0, self::MAX_SEARCH_WORDS) as $word) {
            $builder->groupStart()
                ->like('o.first_name', $word)
                ->orLike('o.last_name', $word)
                ->orLike('o.email_address', $word)
                ->orLike('o.telephone_number', $word)
                ->groupEnd();
        }

        return $builder;
    }
}
