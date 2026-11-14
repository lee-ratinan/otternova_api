<?php

namespace App\Models;

use App\Libraries\ActivityLog;
use App\Libraries\Money;
use CodeIgniter\Model;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;

/**
 * The orders of a business as the business sees and works on them (the Client app's sales pages; see
 * AddOrderStatusesAndPayments): listed, searched and filtered by when they were placed, shown with their lines and
 * payments, their order_status and fulfillment_status changed by hand, and their payments and refunds recorded.
 *
 * financial_status is never set by hand: refreshFinancial() works it out again from the succeeded rows of
 * order_payment after every change of them. The amounts are compared in cents (Money).
 *
 * What the mart's customers do with an order is MartOrderModel's.
 */
class SalesOrderModel extends Model
{
    use PublicIdTrait;
    use TransactionTrait;

    // order_status
    public const ORDER_OPEN        = 'open';
    public const ORDER_IN_PROGRESS = 'in_progress';
    public const ORDER_CLOSED      = 'closed';
    public const ORDER_STATUSES    = [self::ORDER_OPEN, self::ORDER_IN_PROGRESS, self::ORDER_CLOSED];

    // financial_status
    public const FINANCIAL_PENDING            = 'pending';
    public const FINANCIAL_PARTIALLY_PAID     = 'partially_paid';
    public const FINANCIAL_PAID               = 'paid';
    public const FINANCIAL_PARTIALLY_REFUNDED = 'partially_refunded';
    public const FINANCIAL_FULL_REFUNDED      = 'full_refunded';
    public const FINANCIAL_STATUSES           = [self::FINANCIAL_PENDING, self::FINANCIAL_PARTIALLY_PAID, self::FINANCIAL_PAID,
        self::FINANCIAL_PARTIALLY_REFUNDED, self::FINANCIAL_FULL_REFUNDED];

    // fulfillment_status; NULL for an order with no product line
    public const FULFILLMENT_OPEN      = 'open';
    public const FULFILLMENT_PARTIALLY = 'partially_fulfilled';
    public const FULFILLMENT_FULLY     = 'fully_fulfilled';
    public const FULFILLMENT_STATUSES  = [self::FULFILLMENT_OPEN, self::FULFILLMENT_PARTIALLY, self::FULFILLMENT_FULLY];

    // order_payment
    public const PAYMENTS      = 'order_payment';
    public const TYPE_PAYMENT  = 'payment';
    public const TYPE_REFUND   = 'refund';
    public const TYPES         = [self::TYPE_PAYMENT, self::TYPE_REFUND];
    public const TXN_PENDING   = 'pending';
    public const TXN_SUCCEEDED = 'succeeded';
    public const TXN_FAILED    = 'failed';
    public const TXN_VOIDED    = 'voided';
    public const TXN_STATUSES  = [self::TXN_PENDING, self::TXN_SUCCEEDED, self::TXN_FAILED, self::TXN_VOIDED];

    /** The search words looked for; more are left out */
    public const MAX_SEARCH_WORDS = 5;

    private const DB_FORMAT = 'Y-m-d H:i:s';

    protected $table      = 'order_master';
    protected $primaryKey = 'id';
    protected $returnType = 'array';

    /** public_id: CHAR(12), see PublicIdTrait */
    protected int $publicIdLength = 12;

    // created_at and updated_at are maintained by the database
    protected $useTimestamps = false;

    /**
     * One page of a business's orders, newest first
     * @param int    $businessId
     * @param int    $start
     * @param int    $length
     * @param array  $filters from, to (UTC "Y-m-d H:i:s": placed at or after from, before to), order_status,
     *                        financial_status, fulfillment_status (none: no product line); each only when set
     * @param string $search  Words each found in the order number, the customer's first or last name, email address
     *                        or telephone number
     * @return array{records_total: int, records_filtered: int, rows: list<array>} rows: { public_id, order_number,
     *   created_at (UTC), customer_name, email_address, telephone_number, currency_code, total_amount, line_count,
     *   order_status, financial_status, fulfillment_status, payment_method_name, shipping_method_name, cancelled_at }
     */
    public function listOfBusiness(int $businessId, int $start, int $length, array $filters = [], string $search = ''): array
    {
        $base     = fn () => $this->db->table($this->table . ' o')->where('o.business_id', $businessId);
        $total    = $base()->countAllResults();
        $builder  = $base();
        if (isset($filters['from'])) {
            $builder->where('o.created_at >=', $filters['from']);
        }
        if (isset($filters['to'])) {
            $builder->where('o.created_at <', $filters['to']);
        }
        foreach (['order_status', 'financial_status'] as $filter) {
            if (isset($filters[$filter])) {
                $builder->where('o.' . $filter, $filters[$filter]);
            }
        }
        if (isset($filters['fulfillment_status'])) {
            $filters['fulfillment_status'] === 'none' ? $builder->where('o.fulfillment_status', null) : $builder->where('o.fulfillment_status', $filters['fulfillment_status']);
        }
        foreach (self::searchWords($search) as $word) {
            $builder->groupStart()
                ->like('o.order_number', $word)
                ->orLike('o.first_name', $word)
                ->orLike('o.last_name', $word)
                ->orLike('o.email_address', $word)
                ->orLike('o.telephone_number', $word)
                ->groupEnd();
        }
        $filtered = $builder->countAllResults(false);
        $lines    = $this->db->prefixTable('order_line');
        $rows     = $builder
            ->select('o.public_id, o.order_number, o.created_at, o.first_name, o.last_name, o.email_address, o.telephone_number, o.currency_code, '
                . 'o.total_amount, o.order_status, o.financial_status, o.fulfillment_status, o.payment_method_name, o.shipping_method_name, o.cancelled_at, '
                . '(SELECT COUNT(*) FROM ' . $lines . ' ol WHERE ol.order_id = o.id) AS line_count', false)
            ->orderBy('o.created_at', 'DESC')
            ->orderBy('o.id', 'DESC')
            ->limit($length, $start)
            ->get()
            ->getResultArray();

        return ['records_total' => $total, 'records_filtered' => $filtered, 'rows' => array_map(static fn (array $row): array => [
            'public_id'            => $row['public_id'],
            'order_number'         => $row['order_number'],
            'created_at'           => $row['created_at'],
            'customer_name'        => trim($row['first_name'] . ' ' . $row['last_name']),
            'email_address'        => $row['email_address'],
            'telephone_number'     => $row['telephone_number'],
            'currency_code'        => $row['currency_code'],
            'total_amount'         => Money::format($row['total_amount']),
            'line_count'           => (int) $row['line_count'],
            'order_status'         => $row['order_status'],
            'financial_status'     => $row['financial_status'],
            'fulfillment_status'   => $row['fulfillment_status'],
            'payment_method_name'  => $row['payment_method_name'],
            'shipping_method_name' => $row['shipping_method_name'],
            'cancelled_at'         => $row['cancelled_at'],
        ], $rows)];
    }

    /**
     * An order as the business sees it
     * @param array $row order_master
     * @return array { public_id, order_number, created_at, updated_at, paid_at, cancelled_at (UTC "Y-m-d H:i:s"),
     *   language_code, currency_code, order_status, financial_status, fulfillment_status, has_products,
     *   customer: {first_name, last_name, email_address, telephone_number},
     *   lines: [{line_number, kind, artifact_public_id, artifact_name, variant_label, sku, quantity, unit_price,
     *   compare_price, discount_amount, tax: {calculation, percentage, amount}, line_total,
     *   store: {public_id, store_name, timezone} or null, starts_at, ends_at (UTC), participants}],
     *   shipping: {type, name, address: {...} or null, pickup_point, pickup_store: {...} or null} or null,
     *   payment_method: {code, handler_code, name}, discount_code,
     *   totals: {subtotal, discount, shipping, tax_included, tax_added, total},
     *   money: {paid, refunded, net_paid, balance_due, refundable},
     *   payments: [{public_id, transaction_type, transaction_status, amount, payment_method_name, reference, note,
     *   failure_reason, recorded_by_name, processed_at, created_at}] oldest first }
     */
    public function detail(array $row): array
    {
        $stores = [];
        foreach ($this->db->table('business_store')->select('id, public_id, store_name, store_timezone')->where('business_id', $row['business_id'])->get()->getResultArray() as $store) {
            $stores[(int) $store['id']] = ['public_id' => $store['public_id'], 'store_name' => $store['store_name'], 'timezone' => $store['store_timezone']];
        }
        $lines = $this->db->table('order_line l')->select('l.*, a.public_id AS artifact_public_id')
            ->join('artifact_master a', 'a.id = l.artifact_id')
            ->where('l.order_id', $row['id'])->orderBy('l.line_number')->get()->getResultArray();
        $people = [];
        if ($lines !== []) {
            foreach ($this->db->table('order_line_participant')->whereIn('line_id', array_column($lines, 'id'))->orderBy('seat_number')->get()->getResultArray() as $person) {
                $people[(int) $person['line_id']][] = $person['participant_name'];
            }
        }
        $money = $this->moneyOf($row);

        return [
            'public_id'          => $row['public_id'],
            'order_number'       => $row['order_number'],
            'created_at'         => $row['created_at'],
            'updated_at'         => $row['updated_at'],
            'paid_at'            => $row['paid_at'],
            'cancelled_at'       => $row['cancelled_at'],
            'language_code'      => $row['language_code'],
            'currency_code'      => $row['currency_code'],
            'order_status'       => $row['order_status'],
            'financial_status'   => $row['financial_status'],
            'fulfillment_status' => $row['fulfillment_status'],
            'has_products'       => in_array('P', array_column($lines, 'line_kind'), true),
            'customer'           => ['first_name' => $row['first_name'], 'last_name' => $row['last_name'], 'email_address' => $row['email_address'],
                'telephone_number' => $row['telephone_number']],
            'lines'              => array_map(static fn (array $line): array => [
                'line_number'        => (int) $line['line_number'],
                'kind'               => $line['line_kind'],
                'artifact_public_id' => $line['artifact_public_id'],
                'artifact_name'      => $line['artifact_name'],
                'variant_label'      => $line['variant_label'],
                'sku'                => $line['sku'],
                'quantity'           => (int) $line['quantity'],
                'unit_price'         => Money::format($line['unit_price']),
                'compare_price'      => Money::format($line['compare_price']),
                'discount_amount'    => Money::format($line['discount_amount']),
                'tax'                => ['calculation' => $line['tax_calculation'], 'percentage' => Money::format($line['tax_percentage']), 'amount' => Money::format($line['tax_amount'])],
                'line_total'         => Money::format($line['line_total']),
                'store'              => $stores[(int) $line['store_id']] ?? null,
                'starts_at'          => $line['starts_at'],
                'ends_at'            => $line['ends_at'],
                'participants'       => $people[(int) $line['id']] ?? [],
            ], $lines),
            'shipping'           => $row['shipping_method_type'] === null ? null : [
                'type'         => $row['shipping_method_type'],
                'name'         => $row['shipping_method_name'],
                'address'      => $row['shipping_method_type'] !== 'H' ? null : ['line_1' => $row['address_line_1'], 'line_2' => $row['address_line_2'], 'city' => $row['city'],
                    'subdivision_code' => $row['subdivision_code'], 'postal_code' => $row['postal_code'], 'country_code' => $row['country_code']],
                'pickup_point' => $row['pickup_point'],
                'pickup_store' => $row['pickup_store_id'] === null ? null : ($stores[(int) $row['pickup_store_id']] ?? null),
            ],
            'payment_method'     => ['code' => $row['payment_method_code'], 'handler_code' => $row['payment_handler_code'], 'name' => $row['payment_method_name']],
            'discount_code'      => $row['discount_code'],
            'totals'             => [
                'subtotal'     => Money::format($row['subtotal']),
                'discount'     => Money::format($row['discount_amount']),
                'shipping'     => Money::format($row['shipping_fee']),
                'tax_included' => Money::format($row['tax_included']),
                'tax_added'    => Money::format($row['tax_added']),
                'total'        => Money::format($row['total_amount']),
            ],
            'money'              => array_map([Money::class, 'fromCents'], $money),
            'payments'           => $this->paymentsOf((int) $row['id']),
        ];
    }

    /**
     * Change an order's order_status and fulfillment_status, logged
     * @param array $order   order_master
     * @param array $changes order_status, fulfillment_status: each only when set, checked by the caller
     * @param int   $actorId
     * @return bool False when nothing changed; nothing is written then
     * @throws DomainException 'noProducts' for a fulfillment_status of an order with nothing to send
     */
    public function setStatuses(array $order, array $changes, int $actorId): bool
    {
        $changes = array_intersect_key($changes, array_flip(['order_status', 'fulfillment_status']));
        if (isset($changes['fulfillment_status']) && $order['fulfillment_status'] === null) {
            throw new DomainException('noProducts');
        }
        $changes = array_filter($changes, static fn ($value, string $field): bool => $order[$field] !== $value, ARRAY_FILTER_USE_BOTH);
        if ($changes === []) {
            return false;
        }

        return $this->inTransaction(function () use ($order, $changes, $actorId): bool {
            $this->db->table($this->table)->where('id', $order['id'])->update($changes);
            (new ActivityLog())->updated($actorId, 'Changed the statuses of order ' . $order['order_number'], $this->table, (int) $order['id'], $order, $changes);

            return true;
        });
    }

    /**
     * Record a payment or a refund of an order, and work out its financial_status again
     * @param array    $order   order_master
     * @param array    $payment transaction_type, transaction_status (pending, succeeded or failed), amount ("1234.50"),
     *                          payment_method (business_payment_method row, or null for the order's own), reference,
     *                          note, failure_reason: checked by the caller
     * @param int      $actorId
     * @return string The public_id of the order_payment row
     * @throws DomainException 'overpaid' for a succeeded payment above the balance due, 'overRefunded' for a succeeded
     *                         refund above what is left to give back; nothing is written then
     */
    public function recordPayment(array $order, array $payment, int $actorId): string
    {
        return $this->inTransaction(function () use ($order, $payment, $actorId): string {
            $order = $this->locked((int) $order['id']);
            if ($payment['transaction_status'] === self::TXN_SUCCEEDED) {
                $this->checkAmount($order, $payment['transaction_type'], Money::toCents($payment['amount']));
            }
            $method   = $payment['payment_method'];
            $now      = gmdate(self::DB_FORMAT);
            $row      = [
                'public_id'            => $this->newPublicIdIn(self::PAYMENTS),
                'order_id'             => (int) $order['id'],
                'transaction_type'     => $payment['transaction_type'],
                'transaction_status'   => $payment['transaction_status'],
                'currency_code'        => $order['currency_code'],
                'amount'               => $payment['amount'],
                'payment_method_id'    => $method === null ? $order['payment_method_id'] : (int) $method['id'],
                'payment_method_code'  => $method === null ? $order['payment_method_code'] : $method['method_code'],
                'payment_handler_code' => $method === null ? $order['payment_handler_code'] : $method['handler_code'],
                'payment_method_name'  => $method === null ? $order['payment_method_name'] : $method['method_name'],
                'reference'            => $payment['reference'],
                'note'                 => $payment['note'],
                'failure_reason'       => $payment['transaction_status'] === self::TXN_FAILED ? $payment['failure_reason'] : null,
                'recorded_by'          => $actorId,
                'processed_at'         => $payment['transaction_status'] === self::TXN_PENDING ? null : $now,
            ];
            $this->db->table(self::PAYMENTS)->insert($row);
            (new ActivityLog())->inserted($actorId, 'Recorded a ' . $row['transaction_type'] . ' of order ' . $order['order_number'], self::PAYMENTS,
                (int) $this->db->insertID(), $row);
            $this->refreshFinancial($order, $actorId);

            return $row['public_id'];
        });
    }

    /**
     * Settle a pending payment or refund: succeeded, failed or voided; and work out its order's financial_status again
     * @param array       $order         order_master
     * @param array       $payment       order_payment, of that order
     * @param string      $status        TXN_SUCCEEDED, TXN_FAILED or TXN_VOIDED
     * @param string|null $failureReason For TXN_FAILED
     * @param string|null $reference     Kept when null
     * @param int         $actorId
     * @return void
     * @throws DomainException 'notPending' when it is settled already, 'overpaid' or 'overRefunded' as recordPayment()
     */
    public function settlePayment(array $order, array $payment, string $status, ?string $failureReason, ?string $reference, int $actorId): void
    {
        $this->inTransaction(function () use ($order, $payment, $status, $failureReason, $reference, $actorId): void {
            $order   = $this->locked((int) $order['id']);
            $payment = $this->db->table(self::PAYMENTS)->where('id', $payment['id'])->get()->getRowArray();
            if ($payment['transaction_status'] !== self::TXN_PENDING) {
                throw new DomainException('notPending');
            }
            if ($status === self::TXN_SUCCEEDED) {
                $this->checkAmount($order, $payment['transaction_type'], Money::toCents((string) Money::format($payment['amount'])));
            }
            $changes = ['transaction_status' => $status, 'processed_at' => $status === self::TXN_VOIDED ? null : gmdate(self::DB_FORMAT)];
            if ($status === self::TXN_FAILED) {
                $changes['failure_reason'] = $failureReason;
            }
            if ($reference !== null) {
                $changes['reference'] = $reference;
            }
            $this->db->table(self::PAYMENTS)->where('id', $payment['id'])->update($changes);
            (new ActivityLog())->updated($actorId, ucfirst($status) . ' ' . $payment['transaction_type'] . ' ' . $payment['public_id'] . ' of order ' . $order['order_number'],
                self::PAYMENTS, (int) $payment['id'], $payment, $changes);
            $this->refreshFinancial($order, $actorId);
        });
    }

    /**
     * The payments and refunds of an order, oldest first
     * @param int $orderId
     * @return list<array>
     */
    public function paymentsOf(int $orderId): array
    {
        $rows = $this->db->table(self::PAYMENTS . ' p')
            ->select('p.public_id, p.transaction_type, p.transaction_status, p.amount, p.payment_method_name, p.reference, p.note, p.failure_reason, '
                . 'p.processed_at, p.created_at, c.display_name, c.legal_first_name, c.legal_last_name')
            ->join('client_master c', 'c.auth_id = p.recorded_by', 'left')
            ->where('p.order_id', $orderId)
            ->orderBy('p.created_at')->orderBy('p.id')
            ->get()->getResultArray();

        return array_map(static function (array $row): array {
            $name = trim((string) ($row['display_name'] ?? ''));
            $name = $name !== '' ? $name : trim(($row['legal_first_name'] ?? '') . ' ' . ($row['legal_last_name'] ?? ''));
            unset($row['display_name'], $row['legal_first_name'], $row['legal_last_name']);
            $row['amount']           = Money::format($row['amount']);
            $row['recorded_by_name'] = $name === '' ? null : $name;

            return $row;
        }, $rows);
    }

    /**
     * What has been paid and given back of an order, in cents: paid and refunded (the succeeded rows), net_paid,
     * balance_due (what is left to pay, never below 0) and refundable (what may still be given back)
     * @param array $order order_master
     * @return array{paid: int, refunded: int, net_paid: int, balance_due: int, refundable: int}
     */
    public function moneyOf(array $order): array
    {
        $sums = ['payment' => 0, 'refund' => 0];
        $rows = $this->db->table(self::PAYMENTS)->select('transaction_type, amount')
            ->where('order_id', $order['id'])->where('transaction_status', self::TXN_SUCCEEDED)->get()->getResultArray();
        foreach ($rows as $row) {
            $sums[$row['transaction_type']] += Money::toCents((string) Money::format($row['amount']));
        }
        $total = Money::toCents((string) Money::format($order['total_amount']));

        return [
            'paid'        => $sums['payment'],
            'refunded'    => $sums['refund'],
            'net_paid'    => $sums['payment'] - $sums['refund'],
            'balance_due' => max(0, $total - $sums['payment']),
            'refundable'  => max(0, $sums['payment'] - $sums['refund']),
        ];
    }

    /**
     * The financial_status the succeeded payments and refunds of an order make
     * @param int $total    total_amount in cents
     * @param int $paid     in cents
     * @param int $refunded in cents
     * @return string
     */
    public static function financialStatus(int $total, int $paid, int $refunded): string
    {
        if ($refunded > 0) {
            return $refunded >= $paid ? self::FINANCIAL_FULL_REFUNDED : self::FINANCIAL_PARTIALLY_REFUNDED;
        }
        if ($paid >= $total) {
            return self::FINANCIAL_PAID;
        }

        return $paid > 0 ? self::FINANCIAL_PARTIALLY_PAID : self::FINANCIAL_PENDING;
    }

    /**
     * A day of the business's clock as the UTC time it starts at, for the filters of listOfBusiness()
     * @param string       $date     "Y-m-d"
     * @param DateTimeZone $timezone
     * @param int          $addDays  1 for the start of the next day: the end of an inclusive "to" date
     * @return string|null UTC "Y-m-d H:i:s"; null when $date is not a date
     */
    public static function dayStartUtc(string $date, DateTimeZone $timezone, int $addDays = 0): ?string
    {
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $timezone);
        if ($day === false || $day->format('Y-m-d') !== $date) {
            return null;
        }

        return $day->modify('+' . $addDays . ' day')->setTimezone(new DateTimeZone('UTC'))->format(self::DB_FORMAT);
    }

    /**
     * The words of a search, at most MAX_SEARCH_WORDS
     * @param string $search
     * @return list<string>
     */
    private static function searchWords(string $search): array
    {
        $words = preg_split('/\s+/u', trim($search), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_slice(array_values(array_unique($words)), 0, self::MAX_SEARCH_WORDS);
    }

    /**
     * The order row, locked for the transaction on MySQL, so two payments at once cannot both pass checkAmount()
     * @param int $orderId
     * @return array
     */
    private function locked(int $orderId): array
    {
        if ($this->db->DBDriver === 'MySQLi') {
            $this->db->query('SELECT id FROM ' . $this->db->escapeIdentifiers($this->db->prefixTable($this->table)) . ' WHERE id = ? FOR UPDATE', [$orderId]);
        }

        return $this->db->table($this->table)->where('id', $orderId)->get()->getRowArray();
    }

    /**
     * @throws DomainException 'overpaid' or 'overRefunded'
     */
    private function checkAmount(array $order, string $type, int $cents): void
    {
        $money = $this->moneyOf($order);
        if ($type === self::TYPE_PAYMENT && $cents > $money['balance_due']) {
            throw new DomainException('overpaid');
        }
        if ($type === self::TYPE_REFUND && $cents > $money['refundable']) {
            throw new DomainException('overRefunded');
        }
    }

    /**
     * Work financial_status (and paid_at, the first time it is paid) out again from the succeeded payments, logged
     * when it changes
     */
    private function refreshFinancial(array $order, int $actorId): void
    {
        $money   = $this->moneyOf($order);
        $status  = self::financialStatus(Money::toCents((string) Money::format($order['total_amount'])), $money['paid'], $money['refunded']);
        $changes = ['financial_status' => $status];
        if ($status === self::FINANCIAL_PAID && $order['paid_at'] === null) {
            $changes['paid_at'] = gmdate(self::DB_FORMAT);
        }
        $changes = array_filter($changes, static fn ($value, string $field): bool => $order[$field] !== $value, ARRAY_FILTER_USE_BOTH);
        if ($changes === []) {
            return;
        }
        $this->db->table($this->table)->where('id', $order['id'])->update($changes);
        (new ActivityLog())->updated($actorId, 'Worked out the financial status of order ' . $order['order_number'] . ' again', $this->table, (int) $order['id'], $order, $changes);
    }
}
