<?php

namespace App\Models;

use App\Libraries\Money;
use CodeIgniter\Model;
use DateTimeImmutable;
use DateTimeZone;

/**
 * What the orders a business took in a period come to, for its owners: read only. An order belongs to the day of the
 * business's clock it was placed on (order_master.created_at). Sales are the totals of the orders not cancelled;
 * collected is what was paid on all of them, less what was given back (succeeded order_payment rows only), so money
 * still held for a cancelled order counts until it is refunded. Amounts are summed in cents and kept apart by
 * currency, which is the business's own unless it changed it.
 */
class RevenueModel extends Model
{
    protected $table      = 'order_master';
    protected $primaryKey = 'id';
    protected $returnType = 'array';

    /** Longer ranges are split into months rather than days */
    public const DAILY_UP_TO = 62;

    /** At most this many orders are listed, the latest first */
    public const MAX_ORDERS = 500;

    private const DB_FORMAT    = 'Y-m-d H:i:s';
    private const LOCAL_FORMAT = 'Y-m-d\TH:i';

    /**
     * @param int          $businessId
     * @param string       $from     'Y-m-d', the first day (of $timezone)
     * @param string       $to       'Y-m-d', the last day, included
     * @param DateTimeZone $timezone the business's
     * @return array{granularity: string, currencies: list<array>, orders: list<array>, order_count: int}
     *   granularity: day or month;
     *   currencies: [{ currency_code, orders, cancelled_orders, subtotal, discount, shipping, tax, sales, average,
     *   collected, refunded, outstanding, refund_due, periods: [{period ('Y-m-d' or 'Y-m'), orders, sales, collected}],
     *   kinds: [{kind (P, A, G), quantity, amount}], stores: [{store_name, amount}],
     *   methods: [{payment_method_name, orders, amount}] }], the business's currency first, always there;
     *   orders: [{ public_id, order_number, created_at (UTC), local_created_at, customer_name, currency_code,
     *   total_amount, net_paid, balance_due, order_status, financial_status, order_source, payment_method_name,
     *   cancelled }] the latest first, at most MAX_ORDERS; order_count: all of them
     */
    public function report(int $businessId, string $from, string $to, DateTimeZone $timezone): array
    {
        $utc     = new DateTimeZone('UTC');
        $first   = new DateTimeImmutable($from, $timezone);
        $last    = new DateTimeImmutable($to, $timezone);
        $fromUtc = $first->setTimezone($utc)->format(self::DB_FORMAT);
        $toUtc   = $last->modify('+1 day')->setTimezone($utc)->format(self::DB_FORMAT);
        $monthly = (int) $first->diff($last)->days + 1 > self::DAILY_UP_TO;

        $orders = $this->db->table('order_master o')
            ->select('o.id, o.public_id, o.order_number, o.created_at, o.first_name, o.last_name, o.currency_code, o.subtotal, o.discount_amount, '
                . 'o.shipping_fee, o.tax_included, o.tax_added, o.total_amount, o.payment_method_name, o.order_status, o.financial_status, '
                . 'o.order_source, o.cancelled_at, o.refund_due_amount')
            ->where('o.business_id', $businessId)->where('o.created_at >=', $fromUtc)->where('o.created_at <', $toUtc)
            ->orderBy('o.created_at', 'DESC')->orderBy('o.id', 'DESC')
            ->get()->getResultArray();
        $money = $this->paymentsOf($businessId, $fromUtc, $toUtc);

        $currency = (string) ($this->db->table('business_master')->select('currency_code')->where('id', $businessId)->get()->getRow()->currency_code ?? '');
        $sums     = [];
        $blank    = function () use ($first, $last, $monthly): array {
            $periods = [];
            for ($day = $first; $day <= $last; $day = $day->modify('+1 day')) {
                $periods[$day->format($monthly ? 'Y-m' : 'Y-m-d')] = ['orders' => 0, 'sales' => 0, 'collected' => 0];
            }

            return ['orders' => 0, 'cancelled_orders' => 0, 'subtotal' => 0, 'discount' => 0, 'shipping' => 0, 'tax' => 0, 'sales' => 0,
                'collected' => 0, 'refunded' => 0, 'outstanding' => 0, 'refund_due' => 0, 'periods' => $periods, 'kinds' => [], 'stores' => [], 'methods' => []];
        };
        if ($currency !== '') {
            $sums[$currency] = $blank();
        }
        $list = [];
        foreach ($orders as $order) {
            $code      = (string) $order['currency_code'];
            $sums[$code] ??= $blank();
            $sum       = &$sums[$code];
            $cents     = static fn (string $column): int => Money::toCents((string) Money::format($order[$column]));
            $paid      = $money[(int) $order['id']] ?? ['payment' => 0, 'refund' => 0];
            $netPaid   = $paid['payment'] - $paid['refund'];
            $cancelled = $order['cancelled_at'] !== null;
            $local     = (new DateTimeImmutable($order['created_at'], $utc))->setTimezone($timezone);
            $period    = $local->format($monthly ? 'Y-m' : 'Y-m-d');
            $total     = $cents('total_amount');
            $balance   = $cancelled ? 0 : max(0, $total - $paid['payment']);

            $sum['collected'] += $netPaid;
            $sum['refunded']  += $paid['refund'];
            if (isset($sum['periods'][$period])) {
                $sum['periods'][$period]['collected'] += $netPaid;
            }
            if ($cancelled) {
                $sum['cancelled_orders']++;
                $sum['refund_due'] += $cents('refund_due_amount');
            } else {
                $sum['orders']++;
                $sum['subtotal']    += $cents('subtotal');
                $sum['discount']    += $cents('discount_amount');
                $sum['shipping']    += $cents('shipping_fee');
                $sum['tax']         += $cents('tax_included') + $cents('tax_added');
                $sum['sales']       += $total;
                $sum['outstanding'] += $balance;
                if (isset($sum['periods'][$period])) {
                    $sum['periods'][$period]['orders']++;
                    $sum['periods'][$period]['sales'] += $total;
                }
                $method = (string) $order['payment_method_name'];
                $sum['methods'][$method] ??= ['orders' => 0, 'amount' => 0];
                $sum['methods'][$method]['orders']++;
                $sum['methods'][$method]['amount'] += $total;
            }
            unset($sum);
            if (count($list) < self::MAX_ORDERS) {
                $list[] = [
                    'public_id'           => $order['public_id'],
                    'order_number'        => $order['order_number'],
                    'created_at'          => $order['created_at'],
                    'local_created_at'    => $local->format(self::LOCAL_FORMAT),
                    'customer_name'       => trim($order['first_name'] . ' ' . $order['last_name']),
                    'currency_code'       => $code,
                    'total_amount'        => Money::fromCents($total),
                    'net_paid'            => Money::fromCents($netPaid),
                    'balance_due'         => Money::fromCents($balance),
                    'order_status'        => $order['order_status'],
                    'financial_status'    => $order['financial_status'],
                    'order_source'        => $order['order_source'],
                    'payment_method_name' => $order['payment_method_name'],
                    'cancelled'           => $cancelled,
                ];
            }
        }
        $this->addLines($sums, $businessId, $fromUtc, $toUtc);

        $currencies = [];
        foreach ($sums as $code => $sum) {
            $currencies[] = $this->shape($code, $sum);
        }

        return ['granularity' => $monthly ? 'month' : 'day', 'currencies' => $currencies, 'orders' => $list, 'order_count' => count($orders)];
    }

    /**
     * What was paid and given back on each order placed in the range, succeeded rows only
     * @return array<int, array{payment: int, refund: int}> in cents, by order_master.id
     */
    private function paymentsOf(int $businessId, string $fromUtc, string $toUtc): array
    {
        $rows = $this->db->table('order_payment p')
            ->select('p.order_id, p.transaction_type, p.amount')
            ->join('order_master o', 'o.id = p.order_id')
            ->where('o.business_id', $businessId)->where('o.created_at >=', $fromUtc)->where('o.created_at <', $toUtc)
            ->where('p.transaction_status', SalesOrderModel::TXN_SUCCEEDED)
            ->get()->getResultArray();
        $money = [];
        foreach ($rows as $row) {
            $money[(int) $row['order_id']] ??= ['payment' => 0, 'refund' => 0];
            $money[(int) $row['order_id']][$row['transaction_type']] += Money::toCents((string) Money::format($row['amount']));
        }

        return $money;
    }

    /**
     * Sales by kind of line (product, appointment, group session) and by store, from the lines of the orders not
     * cancelled: each line's total, after its share of the discount and with any tax added; shipping is apart
     * @param array<string, array> $sums by currency, changed in place
     */
    private function addLines(array &$sums, int $businessId, string $fromUtc, string $toUtc): void
    {
        $rows = $this->db->table('order_line l')
            ->select('o.currency_code, l.line_kind, l.quantity, l.line_total, s.store_name')
            ->join('order_master o', 'o.id = l.order_id')
            ->join('business_store s', 's.id = l.store_id', 'left')
            ->where('o.business_id', $businessId)->where('o.created_at >=', $fromUtc)->where('o.created_at <', $toUtc)
            ->where('o.cancelled_at', null)
            ->get()->getResultArray();
        foreach ($rows as $row) {
            $sum   = &$sums[(string) $row['currency_code']];
            $cents = Money::toCents((string) Money::format($row['line_total']));
            $kind  = (string) $row['line_kind'];
            $store = (string) ($row['store_name'] ?? '');
            $sum['kinds'][$kind] ??= ['quantity' => 0, 'amount' => 0];
            $sum['kinds'][$kind]['quantity'] += (int) $row['quantity'];
            $sum['kinds'][$kind]['amount']   += $cents;
            $sum['stores'][$store] ??= 0;
            $sum['stores'][$store] += $cents;
            unset($sum);
        }
    }

    /** One currency's sums as the API sends them: amounts as "299.00", breakdowns largest first */
    private function shape(string $code, array $sum): array
    {
        $amount = static fn (int $cents): string => Money::fromCents($cents);
        $kinds  = $sum['kinds'];
        uasort($kinds, static fn (array $a, array $b): int => $b['amount'] <=> $a['amount']);
        $stores = $sum['stores'];
        arsort($stores);
        $methods = $sum['methods'];
        uasort($methods, static fn (array $a, array $b): int => $b['amount'] <=> $a['amount']);

        return [
            'currency_code'    => $code,
            'orders'           => $sum['orders'],
            'cancelled_orders' => $sum['cancelled_orders'],
            'subtotal'         => $amount($sum['subtotal']),
            'discount'         => $amount($sum['discount']),
            'shipping'         => $amount($sum['shipping']),
            'tax'              => $amount($sum['tax']),
            'sales'            => $amount($sum['sales']),
            'average'          => $amount($sum['orders'] === 0 ? 0 : intdiv($sum['sales'], $sum['orders'])),
            'collected'        => $amount($sum['collected']),
            'refunded'         => $amount($sum['refunded']),
            'outstanding'      => $amount($sum['outstanding']),
            'refund_due'       => $amount($sum['refund_due']),
            'periods'          => array_map(static fn (string $period, array $p): array => ['period' => $period, 'orders' => $p['orders'],
                'sales' => $amount($p['sales']), 'collected' => $amount($p['collected'])], array_keys($sum['periods']), $sum['periods']),
            'kinds'            => array_map(static fn (string $kind, array $k): array => ['kind' => $kind, 'quantity' => $k['quantity'],
                'amount' => $amount($k['amount'])], array_keys($kinds), $kinds),
            'stores'           => array_map(static fn (string $name, int $cents): array => ['store_name' => $name, 'amount' => $amount($cents)],
                array_keys($stores), $stores),
            'methods'          => array_map(static fn (string $name, array $m): array => ['payment_method_name' => $name, 'orders' => $m['orders'],
                'amount' => $amount($m['amount'])], array_keys($methods), $methods),
        ];
    }
}
