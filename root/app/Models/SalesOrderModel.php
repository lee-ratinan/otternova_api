<?php

namespace App\Models;

use App\Libraries\ActivityLog;
use App\Libraries\CancelPolicy;
use App\Libraries\Money;
use App\Libraries\Notifier;
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
 * Cancelling (cancel()) is for anyone working in the business, and only before anything of the order has been sent or
 * has started: it gives back the stock, the appointments and the seats, and leaves refund_due_amount for an owner or
 * manager to refund (or settle without refunding, closeRefund()); they are notified. Once a booked service has
 * started the order can only be marked a no-show (noShow()), which flags the customer when nothing was paid.
 *
 * A private appointment may be moved to another time, or to other people (reschedule()), by anyone working in the
 * business until the deadline of the cancellation policy before it starts; the free times it may go to are those of
 * the counter (rescheduleSlots()), its own holds aside.
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

    /** business_customer_flag */
    public const FLAGS        = 'business_customer_flag';
    public const FLAG_NO_SHOW = 'no_show';

    /** The shortest and longest reason of a cancellation, or of settling a refund without refunding it all */
    public const REASON_MIN = 3;
    public const REASON_MAX = 255;

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
        if (! empty($filters['needs_refund'])) {
            $builder->where('o.cancelled_at IS NOT NULL', null, false)->where('o.refund_due_amount >', 0);
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
                . 'o.total_amount, o.order_status, o.financial_status, o.fulfillment_status, o.payment_method_name, o.shipping_method_name, o.cancelled_at, o.refund_due_amount, o.no_show_at, o.order_source, '
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
            'refund_due_amount'    => Money::format($row['refund_due_amount']),
            'no_show_at'           => $row['no_show_at'],
            'order_source'         => $row['order_source'],
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
     *   failure_reason, recorded_by_name, processed_at, created_at}] oldest first,
     *   receipt: {can_print, blocker, stores: [{public_id, store_name, receipt_format}]} as receiptStores() }
     */
    public function detail(array $row, ?string $now = null): array
    {
        $now ??= gmdate(self::DB_FORMAT);
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
        $business = $this->db->table('business_master')->where('id', $row['business_id'])->get()->getRowArray();
        $booked   = $this->bookedStaff(array_values(array_filter(array_map(static fn (array $l): ?int => $l['line_kind'] === 'A' && $l['session_id'] !== null
            ? (int) $l['session_id'] : null, $lines))));
        $money = $this->moneyOf($row);
        $flags = $row['customer_id'] === null ? 0 : $this->db->table(self::FLAGS)->where('business_id', $row['business_id'])
            ->where('customer_id', $row['customer_id'])->where('flag_type', self::FLAG_NO_SHOW)->countAllResults();
        $actors = $this->db->table('auth_master a')->select('a.id, a.full_name')
            ->whereIn('a.id', array_values(array_filter([$row['cancelled_by'], $row['no_show_by'], $row['created_by']]) ?: [0]))->get()->getResultArray();
        $names    = array_column($actors, 'full_name', 'id');
        $printing = $this->receiptStores($row, $now);

        return [
            'public_id'          => $row['public_id'],
            'order_number'       => $row['order_number'],
            'created_at'         => $row['created_at'],
            'updated_at'         => $row['updated_at'],
            'paid_at'            => $row['paid_at'],
            'cancelled_at'       => $row['cancelled_at'],
            'order_source'       => $row['order_source'],
            'created_by_name'    => $row['created_by'] === null ? null : ($names[$row['created_by']] ?? null),
            'counter_store'      => $row['counter_store_id'] === null ? null : ($stores[(int) $row['counter_store_id']] ?? null),
            'cancelled_by_name'  => $row['cancelled_by'] === null ? null : ($names[$row['cancelled_by']] ?? null),
            'cancel_reason'      => $row['cancel_reason'],
            'refund_due_amount'  => Money::format($row['refund_due_amount']),
            'refund_closed_reason' => $row['refund_closed_reason'],
            'no_show_at'         => $row['no_show_at'],
            'no_show_by_name'    => $row['no_show_by'] === null ? null : ($names[$row['no_show_by']] ?? null),
            'can_cancel'         => $this->cancelBlocker($row, $now) === null,
            'cancel_blocker'     => $this->cancelBlocker($row, $now),
            'can_mark_no_show'   => $this->noShowBlocker($row, $now) === null,
            'language_code'      => $row['language_code'],
            'currency_code'      => $row['currency_code'],
            'order_status'       => $row['order_status'],
            'financial_status'   => $row['financial_status'],
            'fulfillment_status' => $row['fulfillment_status'],
            'has_products'       => in_array('P', array_column($lines, 'line_kind'), true),
            'customer'           => ['first_name' => $row['first_name'], 'last_name' => $row['last_name'], 'email_address' => $row['email_address'],
                'telephone_number' => $row['telephone_number'], 'no_show_count' => $flags],
            'lines'              => array_map(fn (array $line): array => [
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
                'staff'              => $line['line_kind'] === 'A' ? ($booked[(int) $line['session_id']] ?? []) : [],
                'reschedule'         => $line['line_kind'] !== 'A' ? null : [
                    'allowed' => $this->rescheduleBlocker($row, $line, $business, $now) === null,
                    'blocker' => $this->rescheduleBlocker($row, $line, $business, $now),
                    'until'   => $line['starts_at'] === null ? null : $this->rescheduleUntil($business, $line)->format(self::DB_FORMAT),
                ],
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
            'receipt'            => [
                'can_print' => $printing['blocker'] === null,
                'blocker'   => $printing['blocker'],
                'stores'    => array_map(static fn (array $store): array => array_intersect_key($store, array_flip(['public_id', 'store_name', 'receipt_format'])), $printing['stores']),
            ],
        ];
    }

    /**
     * Where a receipt of the order may be printed now: once it is paid in full, not refunded, cancelled or a no-show,
     * on the day it was paid (the store's clock), at a store of the order that has a printer (receipt_format set). The
     * stores of an order are the one it was taken at (counter), or else the one it is picked up at, or else those of
     * its lines.
     * @param array  $row order_master
     * @param string $now UTC "Y-m-d H:i:s"
     * @return array{blocker: string|null, stores: list<array>} blocker: cancelled, noShow, refunded, notPaid,
     *   noPrinter or dayEnded (null when it may be printed); stores: the business_store rows (id, public_id,
     *   store_name, store_timezone, receipt_format) it may be printed at, by name, empty when blocked
     */
    public function receiptStores(array $row, string $now): array
    {
        $blocker = match (true) {
            $row['cancelled_at'] !== null => 'cancelled',
            $row['no_show_at'] !== null   => 'noShow',
            in_array($row['financial_status'], [self::FINANCIAL_PARTIALLY_REFUNDED, self::FINANCIAL_FULL_REFUNDED], true) => 'refunded',
            $row['financial_status'] !== self::FINANCIAL_PAID || $row['paid_at'] === null => 'notPaid',
            default => null,
        };
        if ($blocker !== null) {
            return ['blocker' => $blocker, 'stores' => []];
        }
        $storeIds = match (true) {
            $row['counter_store_id'] !== null => [(int) $row['counter_store_id']],
            $row['pickup_store_id'] !== null  => [(int) $row['pickup_store_id']],
            default => array_map('intval', array_column($this->db->table('order_line')->distinct()->select('store_id')->where('order_id', $row['id'])->get()->getResultArray(), 'store_id')),
        };
        $stores = $storeIds === [] ? [] : $this->db->table('business_store')
            ->select('id, public_id, store_name, store_timezone, receipt_format')
            ->where('business_id', $row['business_id'])->whereIn('id', $storeIds)
            ->where('store_type', BusinessStoreModel::TYPE_PHYSICAL)->where('receipt_format IS NOT NULL', null, false)
            ->orderBy('store_name')->orderBy('id')->get()->getResultArray();
        if ($stores === []) {
            return ['blocker' => 'noPrinter', 'stores' => []];
        }
        $utc     = new DateTimeZone('UTC');
        $paidAt  = new DateTimeImmutable($row['paid_at'], $utc);
        $nowAt   = new DateTimeImmutable($now, $utc);
        $sameDay = static function (array $store) use ($paidAt, $nowAt): bool {
            $timezone = new DateTimeZone($store['store_timezone']);

            return $paidAt->setTimezone($timezone)->format('Y-m-d') === $nowAt->setTimezone($timezone)->format('Y-m-d');
        };
        $stores = array_values(array_filter($stores, $sameDay));

        return $stores === [] ? ['blocker' => 'dayEnded', 'stores' => []] : ['blocker' => null, 'stores' => $stores];
    }

    /**
     * What a receipt of the order prints, in $language: the business's and the store's texts in it where they have
     * them, else their own; the lines' names likewise (else as they were ordered). Only the succeeded payments and
     * refunds are on it. Whether it may be printed is receiptStores()'s.
     * @param array  $row      order_master
     * @param array  $store    a store of receiptStores()
     * @param string $language a language of listLanguages()
     * @param int    $actorId  auth_master.id of whoever prints it
     * @return array { receipt: {format, language_code, timezone, footer, printed_by_name},
     *   business: {business_name, legal_name, tax_id, tax_branch_code, country_code},
     *   store: {store_name, phone_number, address_line_1, address_line_2, city, subdivision_code, postal_code},
     *   order: {order_number, order_source, created_at, paid_at (UTC), currency_code, customer_name, lines: [{
     *   line_number, kind, artifact_name, variant_label, sku, quantity, unit_price, discount_amount, tax: {calculation,
     *   percentage, amount}, line_total, starts_at, ends_at}], discount_code, shipping_method_name, totals: {subtotal,
     *   discount, shipping, tax_included, tax_added, total}, money: {paid, refunded, net_paid, balance_due, refundable},
     *   payments: [{transaction_type, payment_method_name, amount, reference, processed_at}] oldest first} }
     */
    public function receipt(array $row, array $store, string $language, int $actorId): array
    {
        $business  = model(BusinessMasterModel::class)->findDecrypted((int) $row['business_id']);
        $storeRow  = $this->db->table('business_store')->where('id', $store['id'])->get()->getRowArray();
        $storeText = $this->db->table(BusinessStoreModel::TRANSLATION_TABLE)->where('store_id', $store['id'])->where('language_code', $language)->get()->getRowArray() ?? [];
        $text      = static fn (string $field): ?string => ($storeText[$field] ?? null) !== null && $storeText[$field] !== '' ? $storeText[$field] : $storeRow[$field];
        $lines     = $this->db->table('order_line')->where('order_id', $row['id'])->orderBy('line_number')->get()->getResultArray();
        $names     = $lines === [] ? [] : array_column($this->db->table('artifact_master_translation')->select('artifact_id, artifact_name')
            ->whereIn('artifact_id', array_unique(array_map('intval', array_column($lines, 'artifact_id'))))->where('language_code', $language)
            ->get()->getResultArray(), 'artifact_name', 'artifact_id');
        $printer   = $this->db->table('client_master')->select('display_name, legal_first_name, legal_last_name')->where('auth_id', $actorId)->get()->getRowArray() ?? [];
        $printedBy = trim((string) ($printer['display_name'] ?? ''));
        $printedBy = $printedBy !== '' ? $printedBy : trim(($printer['legal_first_name'] ?? '') . ' ' . ($printer['legal_last_name'] ?? ''));
        $payments  = array_values(array_filter($this->paymentsOf((int) $row['id']), static fn (array $p): bool => $p['transaction_status'] === self::TXN_SUCCEEDED));

        return [
            'receipt'  => [
                'format'          => $storeRow['receipt_format'],
                'language_code'   => $language,
                'timezone'        => $storeRow['store_timezone'],
                'footer'          => $text('receipt_footer'),
                'printed_by_name' => $printedBy === '' ? null : $printedBy,
            ],
            'business' => [
                'business_name'   => model(BusinessMasterModel::class)->namesOf((int) $business['id'])[$language] ?? $business['business_name'],
                'legal_name'      => $business['legal_name'],
                'tax_id'          => $business['tax_id'],
                'tax_branch_code' => $business['tax_branch_code'],
                'country_code'    => $business['country_code'],
            ],
            'store'    => [
                'store_name'       => $text('store_name'),
                'phone_number'     => $storeRow['store_phone_number'],
                'address_line_1'   => $text('store_address_line_1'),
                'address_line_2'   => $text('store_address_line_2'),
                'city'             => $text('store_city'),
                'subdivision_code' => $storeRow['store_subdivision_code'],
                'postal_code'      => $storeRow['store_postal_code'],
            ],
            'order'    => [
                'order_number'         => $row['order_number'],
                'order_source'         => $row['order_source'],
                'created_at'           => $row['created_at'],
                'paid_at'              => $row['paid_at'],
                'currency_code'        => $row['currency_code'],
                'customer_name'        => trim($row['first_name'] . ' ' . $row['last_name']),
                'lines'                => array_map(static fn (array $line): array => [
                    'line_number'     => (int) $line['line_number'],
                    'kind'            => $line['line_kind'],
                    'artifact_name'   => $names[(int) $line['artifact_id']] ?? $line['artifact_name'],
                    'variant_label'   => $line['variant_label'],
                    'sku'             => $line['sku'],
                    'quantity'        => (int) $line['quantity'],
                    'unit_price'      => Money::format($line['unit_price']),
                    'discount_amount' => Money::format($line['discount_amount']),
                    'tax'             => ['calculation' => $line['tax_calculation'], 'percentage' => Money::format($line['tax_percentage']), 'amount' => Money::format($line['tax_amount'])],
                    'line_total'      => Money::format($line['line_total']),
                    'starts_at'       => $line['starts_at'],
                    'ends_at'         => $line['ends_at'],
                ], $lines),
                'discount_code'        => $row['discount_code'],
                'shipping_method_name' => $row['shipping_method_name'],
                'totals'               => [
                    'subtotal'     => Money::format($row['subtotal']),
                    'discount'     => Money::format($row['discount_amount']),
                    'shipping'     => Money::format($row['shipping_fee']),
                    'tax_included' => Money::format($row['tax_included']),
                    'tax_added'    => Money::format($row['tax_added']),
                    'total'        => Money::format($row['total_amount']),
                ],
                'money'                => array_map([Money::class, 'fromCents'], $this->moneyOf($row)),
                'payments'             => array_map(static fn (array $p): array => array_intersect_key($p, array_flip(['transaction_type', 'payment_method_name', 'amount', 'reference', 'processed_at'])), $payments),
            ],
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
        if ($changes !== [] && ($order['cancelled_at'] !== null || $order['no_show_at'] !== null)) {
            throw new DomainException('orderEnded');
        }
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
            if ($row['transaction_status'] === self::TXN_SUCCEEDED) {
                $this->moveRefundDue($order, $row['transaction_type'], Money::toCents($row['amount']), $actorId);
            }

            return $row['public_id'];
        });
    }

    /**
     * A customer says they paid an order (on the mart, with its key): their reference and slip go on its waiting
     * payment (one of the balance due is added when there is none), reported_at is set, and the people of its stores
     * are told to check it and mark it received; it stays pending until they do
     * @param array         $order     order_master
     * @param string|null   $reference
     * @param callable|null $saveSlip  fn(int $paymentId): string, saving the slip and giving its slip_path; null for none
     * @param DateTimeImmutable $now
     * @return string The public_id of the order_payment row
     * @throws DomainException 'nothingDue' when the order is paid, cancelled or a no-show; nothing is written then
     */
    public function reportPayment(array $order, ?string $reference, ?callable $saveSlip, DateTimeImmutable $now): string
    {
        return $this->inTransaction(function () use ($order, $reference, $saveSlip, $now): string {
            $order = $this->locked((int) $order['id']);
            $money = $this->moneyOf($order);
            if ($order['cancelled_at'] !== null || $order['no_show_at'] !== null || $money['balance_due'] <= 0) {
                throw new DomainException('nothingDue');
            }
            $log     = new ActivityLog();
            $at      = $now->setTimezone(new DateTimeZone('UTC'))->format(self::DB_FORMAT);
            $payment = $this->db->table(self::PAYMENTS)->where('order_id', $order['id'])->where('transaction_type', self::TYPE_PAYMENT)
                ->where('transaction_status', self::TXN_PENDING)->orderBy('id', 'DESC')->get(1)->getRowArray();
            if ($payment === null) {
                $payment = [
                    'public_id' => $this->newPublicIdIn(self::PAYMENTS), 'order_id' => (int) $order['id'], 'transaction_type' => self::TYPE_PAYMENT,
                    'transaction_status' => self::TXN_PENDING, 'currency_code' => $order['currency_code'], 'amount' => Money::fromCents($money['balance_due']),
                    'payment_method_id' => $order['payment_method_id'], 'payment_method_code' => $order['payment_method_code'],
                    'payment_handler_code' => $order['payment_handler_code'], 'payment_method_name' => $order['payment_method_name'],
                ];
                $this->db->table(self::PAYMENTS)->insert($payment);
                $payment['id'] = (int) $this->db->insertID();
                $log->inserted(null, 'The customer added a payment of order ' . $order['order_number'], self::PAYMENTS, $payment['id'], $payment);
            }
            $changes = ['reported_at' => $at]; // shown as reported by itself: no note needed
            if ($reference !== null) {
                $changes['reference'] = $reference;
            }
            if ($saveSlip !== null) {
                $changes['slip_path'] = $saveSlip((int) $payment['id']);
            }
            $this->db->table(self::PAYMENTS)->where('id', $payment['id'])->update($changes);
            $log->updated(null, 'The customer reported paying order ' . $order['order_number'], self::PAYMENTS, (int) $payment['id'], $payment, $changes);

            $stores = array_map('intval', array_column($this->db->table('order_line')->select('store_id')->where('order_id', $order['id'])->get()->getResultArray(), 'store_id'));
            $stores = array_values(array_unique(array_filter([...$stores, $order['pickup_store_id'], $order['counter_store_id']])));
            (new Notifier())->toStoreStaff((int) $order['business_id'], array_map('intval', $stores), Notifier::ORDER_PAYMENT_REPORTED, [
                'customer'     => trim($order['first_name'] . ' ' . $order['last_name']),
                'order_number' => $order['order_number'],
                'amount'       => $order['currency_code'] . ' ' . number_format((float) Money::format($payment['amount']), 2),
                'method'       => (string) $payment['payment_method_name'],
                'reference'    => ($changes['reference'] ?? $payment['reference'] ?? null) === null ? '' : ' (' . ($changes['reference'] ?? $payment['reference']) . ')',
            ], '/admin/sales/order/' . $order['public_id'], [self::PAYMENTS, (int) $payment['id']]);

            return $payment['public_id'];
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
            // checked: the report is handled for everyone told of it
            (new Notifier())->markSubjectRead(self::PAYMENTS, (int) $payment['id'], Notifier::ORDER_PAYMENT_REPORTED);
            $this->refreshFinancial($order, $actorId);
            if ($status === self::TXN_SUCCEEDED) {
                $this->moveRefundDue($order, $payment['transaction_type'], Money::toCents((string) Money::format($payment['amount'])), $actorId);
            }
        });
    }

    /**
     * Why the order cannot be cancelled now, or null when it can: ended (cancelled or a no-show already), fulfilled
     * (products sent, all or some) or started (a booked service has started: a no-show now)
     * @param array  $order order_master
     * @param string $now   UTC "Y-m-d H:i:s"
     * @return string|null
     */
    public function cancelBlocker(array $order, string $now): ?string
    {
        if ($order['cancelled_at'] !== null || $order['no_show_at'] !== null) {
            return 'ended';
        }
        if (! in_array($order['fulfillment_status'], [null, self::FULFILLMENT_OPEN], true)) {
            return 'fulfilled';
        }
        $started = $this->firstStart((int) $order['id']);

        return $started !== null && $started <= $now ? 'started' : null;
    }

    /**
     * Why the order cannot be marked a no-show now, or null when it can: ended, or not started (no booked service, or
     * none has started yet)
     * @param array  $order order_master
     * @param string $now   UTC "Y-m-d H:i:s"
     * @return string|null
     */
    public function noShowBlocker(array $order, string $now): ?string
    {
        if ($order['cancelled_at'] !== null || $order['no_show_at'] !== null) {
            return 'ended';
        }
        $started = $this->firstStart((int) $order['id']);

        return $started === null || $started > $now ? 'notStarted' : null;
    }

    /**
     * Cancel an order, in one transaction: closed, with who and why; its pending payments voided; its products back
     * in stock (unless $restock is false: damaged, lost), its appointments cancelled (their staff and resources free
     * again), its group seats given back and its discount code's use; refund_due_amount is what was paid and not
     * refunded. The owners and managers of the business are notified, but for $actorId.
     * @param array    $order   order_master
     * @param string   $reason  Checked by the caller
     * @param bool     $restock
     * @param int|null $actorId null for the customer or the system
     * @param string   $now     UTC "Y-m-d H:i:s"
     * @return array The order_master row as it is now
     * @throws DomainException With cancelBlocker()'s reason; nothing is written then
     */
    public function cancel(array $order, string $reason, bool $restock, ?int $actorId, string $now): array
    {
        return $this->inTransaction(function () use ($order, $reason, $restock, $actorId, $now): array {
            $order = $this->locked((int) $order['id']);
            if (($blocker = $this->cancelBlocker($order, $now)) !== null) {
                throw new DomainException($blocker);
            }
            $log      = new ActivityLog();
            $orderId  = (int) $order['id'];
            $label    = 'order ' . $order['order_number'];

            // the payments still waiting will not come
            foreach ($this->db->table(self::PAYMENTS)->where('order_id', $orderId)->where('transaction_status', self::TXN_PENDING)->get()->getResultArray() as $payment) {
                $this->db->table(self::PAYMENTS)->where('id', $payment['id'])->update(['transaction_status' => self::TXN_VOIDED]);
                $log->updated($actorId, 'Voided ' . $payment['transaction_type'] . ' ' . $payment['public_id'] . ' of cancelled ' . $label, self::PAYMENTS,
                    (int) $payment['id'], $payment, ['transaction_status' => self::TXN_VOIDED]);
                (new Notifier())->markSubjectRead(self::PAYMENTS, (int) $payment['id'], Notifier::ORDER_PAYMENT_REPORTED);
            }

            // what the order took, given back
            $sessions = model(ServiceSessionModel::class);
            foreach ($this->db->table('order_line')->where('order_id', $orderId)->get()->getResultArray() as $line) {
                if ($line['line_kind'] === 'P' && $restock) {
                    $updated = $this->db->table('artifact_variant_inventory')->set('quantity', 'quantity + ' . (int) $line['quantity'], false)
                        ->where('variant_id', $line['variant_id'])->where('store_id', $line['store_id'])->update();
                    if ($updated && $this->db->affectedRows() > 0) {
                        $log->updated($actorId, 'Restocked ' . $line['quantity'] . ' of variant ' . $line['variant_id'] . ' at store ' . $line['store_id'] . ' from cancelled ' . $label,
                            'artifact_variant_inventory', null, [], ['quantity' => '+' . $line['quantity']]);
                    }
                } elseif ($line['line_kind'] === 'A' && $line['session_id'] !== null) {
                    $session = $this->db->table('service_session')->where('id', $line['session_id'])->get()->getRowArray();
                    if ($session !== null && $session['session_status'] !== ServiceSessionModel::STATUS_CANCELLED) {
                        $sessions->updateSession($session, ['session_status' => ServiceSessionModel::STATUS_CANCELLED], null, null, (int) $actorId);
                    }
                } elseif ($line['line_kind'] === 'G' && $line['session_id'] !== null) {
                    $quantity = (int) $line['quantity'];
                    $this->db->table('service_session')->set('seats_booked', 'CASE WHEN seats_booked > ' . $quantity . ' THEN seats_booked - ' . $quantity . ' ELSE 0 END', false)
                        ->where('id', $line['session_id'])->update();
                    $log->updated($actorId, 'Gave back ' . $quantity . ' seats of session ' . $line['session_id'] . ' from cancelled ' . $label,
                        'service_session', (int) $line['session_id'], [], ['seats_booked' => '-' . $quantity]);
                }
            }
            if ($order['discount_id'] !== null) {
                $this->db->table('business_discount')->set('redemption_count', 'CASE WHEN redemption_count > 0 THEN redemption_count - 1 ELSE 0 END', false)
                    ->where('id', $order['discount_id'])->update();
            }

            $money   = $this->moneyOf($order);
            $changes = [
                'order_status'      => self::ORDER_CLOSED,
                'cancelled_at'      => $now,
                'cancelled_by'      => $actorId,
                'cancel_reason'     => $reason,
                'refund_due_amount' => Money::fromCents($money['refundable']),
            ];
            $this->db->table($this->table)->where('id', $orderId)->update($changes);
            $log->updated($actorId, 'Cancelled ' . $label, $this->table, $orderId, $order, $changes);

            $params = [
                'order_number' => $order['order_number'],
                'by'           => $actorId === null ? '' : (string) ($this->db->table('auth_master')->select('full_name')->where('id', $actorId)->get()->getRow()->full_name ?? ''),
                'amount'       => $order['currency_code'] . ' ' . number_format($money['refundable'] / 100, 2),
                'reason'       => $reason,
            ];
            (new Notifier())->toBusiness((int) $order['business_id'], [BusinessClientLinkModel::ROLE_OWNER, BusinessClientLinkModel::ROLE_MANAGER],
                $money['refundable'] > 0 ? Notifier::ORDER_CANCELLED : Notifier::ORDER_CANCELLED_UNPAID, $params,
                '/admin/sales/order/' . $order['public_id'], [$this->table, $orderId], $actorId);

            return $this->db->table($this->table)->where('id', $orderId)->get()->getRowArray();
        });
    }

    /**
     * Mark an order whose booked service started without the customer a no-show: closed; and when nothing of it was
     * paid, its customer flagged by the business. An appointment (one block of time) ends now, so its staff and
     * resources are free for the rest of it, a walk-in say; who was booked stays on record. Group seats stay taken.
     * @param array  $order   order_master
     * @param int    $actorId
     * @param string $now     UTC "Y-m-d H:i:s"
     * @return bool Whether the customer was flagged
     * @throws DomainException With noShowBlocker()'s reason; nothing is written then
     */
    public function noShow(array $order, int $actorId, string $now): bool
    {
        return $this->inTransaction(function () use ($order, $actorId, $now): bool {
            $order = $this->locked((int) $order['id']);
            if (($blocker = $this->noShowBlocker($order, $now)) !== null) {
                throw new DomainException($blocker);
            }
            $log     = new ActivityLog();
            $changes = ['order_status' => self::ORDER_CLOSED, 'no_show_at' => $now, 'no_show_by' => $actorId];
            $this->db->table($this->table)->where('id', $order['id'])->update($changes);
            $log->updated($actorId, 'Marked order ' . $order['order_number'] . ' a no-show', $this->table, (int) $order['id'], $order, $changes);
            foreach ($this->db->table('order_line')->where('order_id', $order['id'])->where('line_kind', 'A')->where('session_id IS NOT NULL', null, false)
                ->get()->getResultArray() as $line) {
                $this->endAppointmentAt((int) $line['session_id'], $now, $actorId, $log);
            }

            if ($order['customer_id'] === null || $this->moneyOf($order)['net_paid'] > 0) {
                return false;
            }
            $flag = ['business_id' => (int) $order['business_id'], 'customer_id' => (int) $order['customer_id'], 'order_id' => (int) $order['id'],
                'flag_type' => self::FLAG_NO_SHOW, 'flagged_by' => $actorId];
            $this->db->table(self::FLAGS)->insert($flag);
            $log->inserted($actorId, 'Flagged the customer of order ' . $order['order_number'] . ': unpaid no-show', self::FLAGS, (int) $this->db->insertID(), $flag);

            return true;
        });
    }

    /**
     * End an appointment's block of time at $now when it is still running: the block, its session, and its staff and
     * resource holds (with no turnover buffer: nobody used them), so they can be booked again for the rest
     * @param int         $sessionId service_session of the appointment
     * @param string      $now       UTC "Y-m-d H:i:s"
     * @param int         $actorId
     * @param ActivityLog $log
     */
    private function endAppointmentAt(int $sessionId, string $now, int $actorId, ActivityLog $log): void
    {
        $slots = $this->db->table(ServiceSessionModel::SLOTS)->where('session_id', $sessionId)
            ->where('slot_status', ServiceSessionModel::SLOT_SCHEDULED)->where('starts_at <=', $now)->where('ends_at >', $now)->get()->getResultArray();
        foreach ($slots as $slot) {
            $label = 'slot ' . $slot['slot_number'] . ' of session ' . $sessionId . ' at a no-show';
            $this->db->table(ServiceSessionModel::SLOTS)->where('id', $slot['id'])->update(['ends_at' => $now]);
            $log->updated($actorId, 'Ended ' . $label, ServiceSessionModel::SLOTS, (int) $slot['id'], $slot, ['ends_at' => $now]);
            foreach ($this->db->table(ServiceSessionModel::STAFF)->where('slot_id', $slot['id'])->get()->getResultArray() as $hold) {
                $this->db->table(ServiceSessionModel::STAFF)->where('id', $hold['id'])->update(['ends_at' => $now]);
                $log->updated($actorId, 'Freed the staff of ' . $label, ServiceSessionModel::STAFF, (int) $hold['id'], $hold, ['ends_at' => $now]);
            }
            foreach ($this->db->table(ServiceSessionModel::RESOURCES)->where('slot_id', $slot['id'])->get()->getResultArray() as $hold) {
                // nobody used it, so it needs no turnover
                $this->db->table(ServiceSessionModel::RESOURCES)->where('id', $hold['id'])->update(['held_until' => $now]);
                $log->updated($actorId, 'Freed the resource of ' . $label, ServiceSessionModel::RESOURCES, (int) $hold['id'], $hold, ['held_until' => $now]);
            }
        }
        if ($slots !== []) {
            $session = $this->db->table('service_session')->where('id', $sessionId)->get()->getRowArray();
            if ($session['ends_at'] !== null && $session['ends_at'] > $now) {
                $this->db->table('service_session')->where('id', $sessionId)->update(['ends_at' => $now]);
                $log->updated($actorId, 'Session ' . $session['public_id'] . ' ended at a no-show', 'service_session', $sessionId, $session, ['ends_at' => $now]);
            }
        }
    }

    /**
     * Why a line of an order cannot be moved now, or null when it can: ended (the order is cancelled or a no-show),
     * notAppointment (only a private appointment moves; a group's seats are in its session), started, or deadline
     * (past the cancellation policy's deadline before it)
     * @param array  $order    order_master
     * @param array  $line     order_line
     * @param array  $business business_master, for its cancellation policy
     * @param string $now      UTC "Y-m-d H:i:s"
     * @return string|null
     */
    public function rescheduleBlocker(array $order, array $line, array $business, string $now): ?string
    {
        if ($order['cancelled_at'] !== null || $order['no_show_at'] !== null) {
            return 'ended';
        }
        if ($line['line_kind'] !== 'A' || $line['session_id'] === null || $line['starts_at'] === null) {
            return 'notAppointment';
        }
        if ($line['starts_at'] <= $now) {
            return 'started';
        }

        return $now > $this->rescheduleUntil($business, $line)->format(self::DB_FORMAT) ? 'deadline' : null;
    }

    /**
     * The free times an appointment of the order could be moved to on $date (Y-m-d on its store's clock), as at the
     * counter, its own holds aside, at its store, for as long as it was booked
     * @param array             $order      order_master
     * @param int               $lineNumber
     * @param string            $date
     * @param string            $language
     * @param DateTimeImmutable $now
     * @return array{date: string, until: string, staff: list<array{public_id: string, name: string}>, slots: list<array>}
     * @throws DomainException With rescheduleBlocker()'s reason, or 'notFound'
     */
    public function rescheduleSlots(array $order, int $lineNumber, string $date, string $language, DateTimeImmutable $now): array
    {
        [$line, $business, $variant, $store] = $this->moving($order, $lineNumber, $language, $now);
        $out = ['date' => $date, 'until' => $this->rescheduleUntil($business, $line)->format(self::DB_FORMAT), 'staff' => [], 'slots' => []];
        if ($store === null) {
            return $out;
        }
        $out['staff'] = array_values(array_map(static fn (array $p): array => ['public_id' => $p['public_id'], 'name' => $p['name']], $store['staff']));
        $out['slots'] = model(MartSlotModel::class)->slotsOn($business, $variant, $store, $date, null, $now, true, (int) $line['session_id']);

        return $out;
    }

    /**
     * Move an appointment of the order to another start, with the people given (one of rescheduleSlots()'s), in one
     * transaction: its slot, its staff and resource holds (the free resources picked again), its session and its line
     * @param array             $order      order_master
     * @param int               $lineNumber
     * @param string            $startsAt   As rescheduleSlots() gave it (ISO 8601 with the store's offset)
     * @param list<string>      $staff      The people's public_ids, as it gave them
     * @param string            $language
     * @param int               $actorId
     * @param DateTimeImmutable $now
     * @return array{from: string, to: string, timezone: string, line: array} from and to: the old and new start (UTC)
     * @throws DomainException With rescheduleBlocker()'s reason, 'notFound', or 'taken' when that time is not free
     *                         with those people; nothing is written then
     */
    public function reschedule(array $order, int $lineNumber, string $startsAt, array $staff, string $language, int $actorId, DateTimeImmutable $now): array
    {
        return $this->inTransaction(function () use ($order, $lineNumber, $startsAt, $staff, $language, $actorId, $now): array {
            $order = $this->locked((int) $order['id']);
            [$line, $business, $variant, $store] = $this->moving($order, $lineNumber, $language, $now);
            $sessionId = (int) $line['session_id'];
            $slots     = model(MartSlotModel::class);
            $wanted    = array_values($staff);
            sort($wanted);
            $found = null;
            foreach ($store === null || ! preg_match('/^\d{4}-\d{2}-\d{2}T/', $startsAt) ? []
                : $slots->slotsOn($business, $variant, $store, substr($startsAt, 0, 10), null, $now, true, $sessionId) as $slot) {
                $team = $slot['staff'];
                sort($team);
                if ($slot['starts_at'] === $startsAt && $team === $wanted) {
                    $found = $slot;
                    break;
                }
            }
            $utc   = new DateTimeZone('UTC');
            $start = $found === null ? null : (new DateTimeImmutable($found['starts_at']))->setTimezone($utc);
            $end   = $found === null ? null : (new DateTimeImmutable($found['ends_at']))->setTimezone($utc);
            $held  = $found === null ? null : $slots->freeResources((int) $business['id'], $variant['artifact_id'], $store['id'], $start, $end, $variant['people'], $sessionId);
            if ($held === null) {
                throw new DomainException('taken');
            }
            $clients = [];
            foreach ($store['staff'] as $clientId => $person) {
                if (in_array($person['public_id'], $wanted, true)) {
                    $clients[] = $clientId;
                }
            }

            $sessions = model(ServiceSessionModel::class);
            $session  = $this->db->table('service_session')->where('id', $sessionId)->get()->getRowArray();
            $slot     = $this->db->table(ServiceSessionModel::SLOTS)->where('session_id', $sessionId)->where('slot_status', ServiceSessionModel::SLOT_SCHEDULED)
                ->orderBy('slot_number')->get(1)->getRowArray();
            try {
                $sessions->updateSession($session, [], null, [[
                    'index' => 0, 'slot_number' => $slot === null ? null : (int) $slot['slot_number'],
                    'starts_at' => $start->format(self::DB_FORMAT), 'ends_at' => $end->format(self::DB_FORMAT),
                    'slot_status' => ServiceSessionModel::SLOT_SCHEDULED, 'staff' => $clients, 'resources' => $held,
                ]], $actorId);
            } catch (SlotConflictException) {
                throw new DomainException('taken');
            }
            $changes = ['starts_at' => $start->format(self::DB_FORMAT), 'ends_at' => $end->format(self::DB_FORMAT)];
            $this->db->table('order_line')->where('id', $line['id'])->update($changes);
            (new ActivityLog())->updated($actorId, 'Moved line ' . $lineNumber . ' of order ' . $order['order_number'], 'order_line', (int) $line['id'], $line, $changes);

            return ['from' => $line['starts_at'], 'to' => $changes['starts_at'], 'timezone' => $store['timezone'], 'line' => $line];
        });
    }

    /**
     * The line to move and what moving it needs: [order_line, business_master, the variant as MartSlotModel takes it
     * (booked for as long as the line was), the store as MartSlotModel::storesFor() gives it, or null when nobody who
     * performs it works there now]
     * @throws DomainException With rescheduleBlocker()'s reason, or 'notFound'
     */
    private function moving(array $order, int $lineNumber, string $language, DateTimeImmutable $now): array
    {
        $line = $this->db->table('order_line')->where('order_id', $order['id'])->where('line_number', $lineNumber)->get()->getRowArray();
        if ($line === null) {
            throw new DomainException('notFound');
        }
        $business = $this->db->table('business_master')->where('id', $order['business_id'])->get()->getRowArray();
        if (($blocker = $this->rescheduleBlocker($order, $line, $business, $now->setTimezone(new DateTimeZone('UTC'))->format(self::DB_FORMAT))) !== null) {
            throw new DomainException($blocker);
        }
        $row     = $this->db->table('artifact_variant')->where('id', $line['variant_id'])->get()->getRowArray();
        $minutes = intdiv(strtotime($line['ends_at'] . ' UTC') - strtotime($line['starts_at'] . ' UTC'), 60);
        $variant = ['id' => (int) $row['id'], 'artifact_id' => (int) $row['artifact_id'], 'duration_minutes' => max(1, $minutes),
            'staff_required' => max(1, (int) $row['staff_required']), 'people' => max(1, (int) $row['capacity'])] + $row;
        $store   = current(array_filter(model(MartSlotModel::class)->storesFor((int) $order['business_id'], (int) $row['artifact_id'], $language),
            static fn (array $s): bool => $s['id'] === (int) $line['store_id'])) ?: null;

        return [$line, $business, $variant, $store];
    }

    /**
     * The last moment an appointment may be moved: its cancellation deadline, by the business's policy on its store's
     * clock (UTC)
     */
    private function rescheduleUntil(array $business, array $line): DateTimeImmutable
    {
        $zone = $this->db->table('business_store')->select('store_timezone')->where('id', $line['store_id'])->get()->getRow()->store_timezone ?? null;

        return CancelPolicy::freeUntil($business, new DateTimeImmutable($line['starts_at'], new DateTimeZone('UTC')), new DateTimeZone($zone ?: 'UTC'))
            ->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * The people each appointment's session holds, by name
     * @param list<int> $sessionIds
     * @return array<int, list<array{public_id: string, name: string}>>
     */
    private function bookedStaff(array $sessionIds): array
    {
        if ($sessionIds === []) {
            return [];
        }
        $out = [];
        foreach ($this->db->table(ServiceSessionModel::STAFF . ' h')
            ->select('sl.session_id, a.public_id, c.display_name, c.legal_first_name, a.full_name')
            ->join(ServiceSessionModel::SLOTS . ' sl', 'sl.id = h.slot_id')
            ->join('client_master c', 'c.id = h.client_id')->join('auth_master a', 'a.id = c.auth_id')
            ->whereIn('sl.session_id', $sessionIds)->where('sl.slot_status', ServiceSessionModel::SLOT_SCHEDULED)
            ->orderBy('h.id')->get()->getResultArray() as $row) {
            $out[(int) $row['session_id']][] = ['public_id' => $row['public_id'], 'name' => MartSlotModel::publicName($row)];
        }

        return $out;
    }

    /**
     * Settle the refund of a cancelled order without refunding what is left (a late-cancellation fee kept):
     * refund_due_amount 0, with why; its notification is marked read
     * @param array  $order   order_master
     * @param string $reason  Checked by the caller
     * @param int    $actorId
     * @return void
     * @throws DomainException 'noRefundDue' when the order is not cancelled or owes nothing; nothing is written then
     */
    public function closeRefund(array $order, string $reason, int $actorId): void
    {
        $this->inTransaction(function () use ($order, $reason, $actorId): void {
            $order = $this->locked((int) $order['id']);
            if ($order['cancelled_at'] === null || Money::isZero($order['refund_due_amount'])) {
                throw new DomainException('noRefundDue');
            }
            $changes = ['refund_due_amount' => '0.00', 'refund_closed_reason' => $reason];
            $this->db->table($this->table)->where('id', $order['id'])->update($changes);
            (new ActivityLog())->updated($actorId, 'Settled the refund of order ' . $order['order_number'] . ' without refunding the rest', $this->table,
                (int) $order['id'], $order, $changes);
            (new Notifier())->markSubjectRead($this->table, (int) $order['id'], Notifier::ORDER_CANCELLED);
        });
    }

    /**
     * When the first booked service of an order starts (UTC), null for one with none
     */
    private function firstStart(int $orderId): ?string
    {
        $row = $this->db->table('order_line')->selectMin('starts_at', 'first_start')->where('order_id', $orderId)
            ->whereIn('line_kind', ['A', 'G'])->get()->getRowArray();

        return $row['first_start'] ?? null;
    }

    /**
     * A succeeded payment or refund of a cancelled order: a payment raises what is owed back, a refund lowers it (never
     * below 0); once nothing is owed, the cancellation's notification is marked read
     */
    private function moveRefundDue(array $order, string $type, int $cents, int $actorId): void
    {
        $order = $this->db->table($this->table)->where('id', $order['id'])->get()->getRowArray();
        if ($order['cancelled_at'] === null) {
            return;
        }
        $due = Money::toCents((string) Money::format($order['refund_due_amount']));
        $new = $type === self::TYPE_PAYMENT ? $due + $cents : max(0, $due - $cents);
        if ($new === $due) {
            return;
        }
        $changes = ['refund_due_amount' => Money::fromCents($new)];
        $this->db->table($this->table)->where('id', $order['id'])->update($changes);
        (new ActivityLog())->updated($actorId, 'Worked out the refund due of order ' . $order['order_number'] . ' again', $this->table, (int) $order['id'], $order, $changes);
        if ($new === 0) {
            (new Notifier())->markSubjectRead($this->table, (int) $order['id'], Notifier::ORDER_CANCELLED);
        }
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
                . 'p.reported_at, p.slip_path, p.processed_at, p.created_at, c.display_name, c.legal_first_name, c.legal_last_name')
            ->join('client_master c', 'c.auth_id = p.recorded_by', 'left')
            ->where('p.order_id', $orderId)
            ->orderBy('p.created_at')->orderBy('p.id')
            ->get()->getResultArray();

        return array_map(static function (array $row): array {
            $name = trim((string) ($row['display_name'] ?? ''));
            $name = $name !== '' ? $name : trim(($row['legal_first_name'] ?? '') . ' ' . ($row['legal_last_name'] ?? ''));
            unset($row['display_name'], $row['legal_first_name'], $row['legal_last_name']);
            $row['amount']           = Money::format($row['amount']);
            $row['has_slip']         = $row['slip_path'] !== null;
            unset($row['slip_path']);
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
