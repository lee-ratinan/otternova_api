<?php

namespace App\Models;

use App\Libraries\ActivityLog;
use App\Libraries\Money;
use App\Libraries\PromptPay;
use CodeIgniter\Model;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * The orders customers place on the mart (see CreateOrderTables). The mart keeps the cart; this prices it and places
 * it, from what is stored now, never from the prices or stock the mart saw.
 *
 * A cart line, as MartOrder's endpoints take it: { key (the mart's id of the line), kind P|A|G, variant (public_id),
 * quantity, and for A: store, starts_at (ISO 8601 with the store's offset, as /mart/slots sent it), staff
 * (public_ids); for G: session (public_id); for A and G: participants (one name per seat, for place()) }.
 * The choices: { shipping (public_id), pickup_store (public_id, for a store pickup offered at every store), discount
 * (a code), payment (public_id) }.
 *
 * Which store a product comes out of: for a delivery (H, L) one store that has every product line in stock, an online
 * store (O) before a physical one, then the one with the most of what is ordered; only when no store has it all does
 * each line come from the store with the most of it. A method of one store (store_id) sends only from it, a store
 * pickup only from the store picked up at. A variant no store keeps count of (no artifact_variant_inventory row) is
 * never short.
 *
 * Money is counted in cents. The discount is shared out over the lines it applies to by their subtotals (the last
 * takes what rounding leaves); a line's tax is on its subtotal less its share: included in it (I), added to it (E),
 * or none (X). Shipping fees are neither discounted nor taxed.
 */
class MartOrderModel extends Model
{
    use PublicIdTrait;
    use TransactionTrait;

    public const STATUS_PLACED    = 'P';
    public const STATUS_CONFIRMED = 'C';
    public const STATUS_CANCELLED = 'X';

    public const KIND_PRODUCT     = 'P';
    public const KIND_APPOINTMENT = 'A';
    public const KIND_SESSION     = 'G';

    public const LINES     = 'order_line';
    public const PEOPLE    = 'order_line_participant';
    public const DISCOUNTS = 'business_discount';

    private const DB_FORMAT    = 'Y-m-d H:i:s';
    private const LOCAL_FORMAT = 'Y-m-d\TH:i:sP';

    protected $table      = 'order_master';
    protected $primaryKey = 'id';
    protected $returnType = 'array';

    /** public_id: CHAR(12), see PublicIdTrait */
    protected int $publicIdLength = 12;

    // created_at and updated_at are maintained by the database
    protected $useTimestamps = false;

    /**
     * What the cart would cost now, and what may be chosen for it
     * @param array             $business business_master
     * @param string            $language
     * @param list<array>       $cart
     * @param array             $choice
     * @param DateTimeImmutable $now
     * @return array { currency, lines: [{key, kind, variant, artifact, quantity, unit_price, compare_price, subtotal,
     *   discount, tax: {calculation, percentage, amount}, total, store: {public_id, name} or null, starts_at, ends_at,
     *   problem: {code, available} or null}], problems (count), needs_shipping,
     *   shipping_methods: [{public_id, type, name, description, eta_days_min, eta_days_max, fee, available,
     *   unavailable (why not: stock or weight), pickup_stores: [{public_id, name, address}] (S offered at every store)}],
     *   shipping: {public_id, type, name, fee, pickup_store} or null, shipping_error,
     *   discount: {code, description, amount} or null, discount_error: {code, amount} or null,
     *   payment_methods: [{public_id, method_code, handler_code, name, instruction}], payment (public_id or null),
     *   totals: {subtotal, discount, shipping, tax_included, tax_added, total} }
     *   Amounts are strings, 1234.50; the lines with a problem count in no total.
     */
    public function quote(array $business, string $language, array $cart, array $choice, DateTimeImmutable $now): array
    {
        return $this->view($this->priced($business, $language, $cart, $choice, $now));
    }

    /**
     * Place the order: the cart priced again with its rows locked, the stock taken, the seats and appointments booked,
     * the discount counted, in one transaction
     * @param array $customer { first_name, last_name, email, telephone }, checked
     * @param array $delivery { line_1, line_2, city, subdivision_code, postal_code, pickup_point }, trimmed
     * @return array{id: int, public_id: string, key: string} key: what shows the order (order()), given only now
     * @throws OrderRefusedException When it cannot be placed as it is; nothing is written
     * @throws Throwable When a write fails; nothing is written
     */
    public function place(array $business, string $language, array $cart, array $choice, array $customer, array $delivery, DateTimeImmutable $now): array
    {
        $businessId = (int) $business['id'];

        return $this->inTransaction(function () use ($business, $businessId, $language, $cart, $choice, $customer, $delivery, $now): array {
            $this->lockRows($businessId, $cart, $choice);
            $priced = $this->priced($business, $language, $cart, $choice, $now);
            $refuse = fn (string $reason, array $errors = []) => new OrderRefusedException($reason, $errors, $this->view($priced));

            if ($priced['problems'] > 0) {
                throw $refuse('problems');
            }
            if ($priced['needs_shipping'] && $priced['shipping'] === null) {
                throw $refuse('shipping', ['shipping' => $priced['shipping_error'] ?? 'required']);
            }
            if ($priced['discount_error'] !== null) {
                throw $refuse('discount', ['discount' => $priced['discount_error']['code']]);
            }
            $payment = $priced['payment'];
            if ($payment === null) {
                throw $refuse('payment', ['payment' => 'required']);
            }
            $errors = self::deliveryErrors($priced['shipping'], $delivery);
            foreach ($priced['lines'] as $index => $line) {
                if ($line['kind'] === self::KIND_PRODUCT) {
                    continue;
                }
                $names = $cart[$index]['participants'] ?? [];
                if (count($names) !== $line['quantity'] || in_array('', $names, true)) {
                    $errors['lines.' . $line['key'] . '.participants'] = 'required';
                }
            }
            if ($errors !== []) {
                throw $refuse('invalid', $errors);
            }

            $log      = new ActivityLog();
            $sessions = model(ServiceSessionModel::class);
            $name     = trim($customer['first_name'] . ' ' . $customer['last_name']);
            foreach ($priced['lines'] as $index => &$line) {
                if ($line['kind'] === self::KIND_PRODUCT) {
                    if ($line['tracked']) {
                        $this->db->table('artifact_variant_inventory')->set('quantity', 'quantity - ' . $line['quantity'], false)
                            ->where('variant_id', $line['variant_id'])->where('store_id', $line['store_id'])
                            ->where('quantity >=', $line['quantity'])->update();
                        if ($this->db->affectedRows() !== 1) {
                            throw $refuse('problems', ['lines.' . $line['key'] => 'out_of_stock']);
                        }
                        $log->updated(null, 'Took ' . $line['quantity'] . ' of variant ' . $line['variant_id'] . ' out of store ' . $line['store_id'] . ' for an order',
                            'artifact_variant_inventory', null, [], ['quantity' => '-' . $line['quantity']]);
                    }
                } elseif ($line['kind'] === self::KIND_SESSION) {
                    $this->db->table('service_session')->set('seats_booked', 'seats_booked + ' . $line['quantity'], false)
                        ->where('id', $line['session_id'])->where('seats_booked + ' . $line['quantity'] . ' <= capacity', null, false)->update();
                    if ($this->db->affectedRows() !== 1) {
                        throw $refuse('problems', ['lines.' . $line['key'] => 'session_full']);
                    }
                    $log->updated(null, 'Booked ' . $line['quantity'] . ' seats of session ' . $line['session_id'] . ' for an order',
                        'service_session', $line['session_id'], [], ['seats_booked' => '+' . $line['quantity']]);
                } else {
                    // an appointment is a session of its own, closed to anyone else, holding the people and resources
                    try {
                        $line['session_id'] = $sessions->writeNewSession($sessions->newPublicId(), [
                            'business_id'    => $businessId,
                            'store_id'       => $line['store_id'],
                            'artifact_id'    => $line['artifact_id'],
                            'variant_id'     => $line['variant_id'],
                            'session_name'   => mb_substr($line['artifact_name'] . ' - ' . $name, 0, 255),
                            'capacity'       => 1,
                            'seats_booked'   => 1,
                            'session_status' => ServiceSessionModel::STATUS_CLOSED,
                        ], [], [[
                            'index' => 0, 'starts_at' => $line['starts_at'], 'ends_at' => $line['ends_at'],
                            'slot_status' => ServiceSessionModel::SLOT_SCHEDULED, 'staff' => $line['staff'], 'resources' => $line['resources'],
                        ]], null);
                    } catch (SlotConflictException) {
                        throw $refuse('problems', ['lines.' . $line['key'] => 'slot_taken']);
                    }
                }
            }
            unset($line);

            if ($priced['discount'] !== null) {
                $this->db->table(self::DISCOUNTS)->set('redemption_count', 'redemption_count + 1', false)->where('id', $priced['discount']['id'])->update();
            }

            $customerId = $this->customerId($customer, $name, $now);
            $key        = bin2hex(random_bytes(24));
            $publicId   = $this->newPublicId();
            $shipping   = $priced['shipping'];
            $home       = ($shipping['type'] ?? null) === 'H';
            $totals     = $priced['totals'];
            $row        = [
                'public_id'            => $publicId,
                'access_key'           => hash('sha256', $key),
                'business_id'          => $businessId,
                'customer_id'          => $customerId,
                'order_status'         => self::STATUS_PLACED,
                'language_code'        => $language,
                'currency_code'        => $business['currency_code'],
                'first_name'           => $customer['first_name'],
                'last_name'            => $customer['last_name'],
                'email_address'        => $customer['email'],
                'telephone_number'     => $customer['telephone'],
                'shipping_method_id'   => $shipping['id'] ?? null,
                'shipping_method_type' => $shipping['type'] ?? null,
                'shipping_method_name' => $shipping['name'] ?? null,
                'address_line_1'       => $home ? $delivery['line_1'] : null,
                'address_line_2'       => $home && $delivery['line_2'] !== '' ? $delivery['line_2'] : null,
                'city'                 => $home ? $delivery['city'] : null,
                'subdivision_code'     => $home && $delivery['subdivision_code'] !== '' ? $delivery['subdivision_code'] : null,
                'postal_code'          => $home ? $delivery['postal_code'] : null,
                'country_code'         => $home ? $business['country_code'] : null,
                'pickup_point'         => ($shipping['type'] ?? null) === 'L' ? $delivery['pickup_point'] : null,
                'pickup_store_id'      => ($shipping['type'] ?? null) === 'S' ? $shipping['pickup_store_id'] : null,
                'payment_method_id'    => $payment['id'],
                'payment_method_code'  => $payment['method_code'],
                'payment_handler_code' => $payment['handler_code'],
                'payment_method_name'  => $payment['name'],
                'discount_id'          => $priced['discount']['id'] ?? null,
                'discount_code'        => $priced['discount']['code'] ?? null,
                'subtotal'             => Money::fromCents($totals['subtotal']),
                'discount_amount'      => Money::fromCents($totals['discount']),
                'shipping_fee'         => Money::fromCents($totals['shipping']),
                'tax_included'         => Money::fromCents($totals['tax_included']),
                'tax_added'            => Money::fromCents($totals['tax_added']),
                'total_amount'         => Money::fromCents($totals['total']),
            ];
            $this->db->table($this->table)->insert($row);
            $orderId = (int) $this->db->insertID();

            foreach (array_values($priced['lines']) as $number => $line) {
                $this->db->table(self::LINES)->insert([
                    'order_id'        => $orderId,
                    'line_number'     => $number + 1,
                    'line_kind'       => $line['kind'],
                    'artifact_id'     => $line['artifact_id'],
                    'variant_id'      => $line['variant_id'],
                    'artifact_name'   => mb_substr($line['artifact_name'], 0, 255),
                    'variant_label'   => $line['label'] === '' ? null : mb_substr($line['label'], 0, 255),
                    'sku'             => $line['sku'],
                    'store_id'        => $line['store_id'],
                    'session_id'      => $line['session_id'],
                    'starts_at'       => $line['starts_at'],
                    'ends_at'         => $line['ends_at'],
                    'unit_price'      => Money::fromCents($line['unit']),
                    'compare_price'   => $line['compare'] === null ? null : Money::fromCents($line['compare']),
                    'quantity'        => $line['quantity'],
                    'discount_amount' => Money::fromCents($line['discount']),
                    'tax_calculation' => $line['tax_calculation'],
                    'tax_percentage'  => $line['tax_percentage'],
                    'tax_amount'      => Money::fromCents($line['tax']),
                    'line_total'      => Money::fromCents($line['total']),
                ]);
                $lineId = (int) $this->db->insertID();
                if ($line['kind'] !== self::KIND_PRODUCT) {
                    foreach (array_values($cart[array_search($line['key'], array_column($cart, 'key'), true)]['participants']) as $seat => $person) {
                        $this->db->table(self::PEOPLE)->insert(['line_id' => $lineId, 'seat_number' => $seat + 1, 'participant_name' => $person]);
                    }
                }
            }

            return ['id' => $orderId, 'public_id' => $publicId, 'key' => $key];
        });
    }

    /**
     * An order as its customer sees it, when $key is the one place() gave
     * @return array|null { public_id, status, created_at (UTC ISO 8601), currency, customer: {first_name, last_name,
     *   email, telephone}, lines: [{kind, artifact_public_id, name, label, quantity, unit_price, compare_price,
     *   discount, tax: {calculation, percentage, amount}, total, store: {public_id, name, timezone}, starts_at, ends_at,
     *   participants}], shipping: {type, name, address: {...} or null, pickup_point, pickup_store: {...} or null} or
     *   null, payment: {method_code, handler_code, name, instruction, promptpay_payload}, discount_code,
     *   totals: {subtotal, discount, shipping, tax_included, tax_added, total} }
     */
    public function order(int $businessId, string $publicId, string $key, string $language): ?array
    {
        $row = $this->findByPublicId($publicId);
        if ($row === null || (int) $row['business_id'] !== $businessId || $key === '' || ! hash_equals($row['access_key'], hash('sha256', $key))) {
            return null;
        }
        $stores = $this->storesOf($businessId, $language);
        $store  = static fn (?int $id): ?array => $id === null || ! isset($stores[$id]) ? null
            : ['public_id' => $stores[$id]['public_id'], 'name' => $stores[$id]['name'], 'timezone' => $stores[$id]['timezone'], 'address' => $stores[$id]['address']];
        $lines  = $this->db->table(self::LINES . ' l')->select('l.*, a.public_id AS artifact_public_id')
            ->join('artifact_master a', 'a.id = l.artifact_id')
            ->where('l.order_id', $row['id'])->orderBy('l.line_number')->get()->getResultArray();
        $people = [];
        if ($lines !== []) {
            foreach ($this->db->table(self::PEOPLE)->whereIn('line_id', array_column($lines, 'id'))->orderBy('seat_number')->get()->getResultArray() as $person) {
                $people[(int) $person['line_id']][] = $person['participant_name'];
            }
        }

        $payment = ['method_code' => $row['payment_method_code'], 'handler_code' => $row['payment_handler_code'], 'name' => $row['payment_method_name'],
            'instruction' => null, 'promptpay_payload' => null];
        if ($row['payment_method_id'] !== null) {
            $method = $this->paymentMethods($businessId, null, $language, (int) $row['payment_method_id'])[0] ?? null;
            if ($method !== null) {
                $payment['instruction'] = $method['instruction'];
                $promptPayId            = $method['settings']['promptpay_id'] ?? null;
                if ($row['payment_handler_code'] === 'promptpay_qr' && is_string($promptPayId) && $row['currency_code'] === 'THB') {
                    $payment['promptpay_payload'] = PromptPay::payload($promptPayId, Money::format($row['total_amount']));
                }
            }
        }

        return [
            'public_id'  => $row['public_id'],
            'status'     => $row['order_status'],
            'created_at' => (new DateTimeImmutable($row['created_at'], new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
            'currency'   => $row['currency_code'],
            'customer'   => ['first_name' => $row['first_name'], 'last_name' => $row['last_name'], 'email' => $row['email_address'], 'telephone' => $row['telephone_number']],
            'lines'      => array_map(function (array $line) use ($store, $people): array {
                $at = $store((int) $line['store_id']);

                return [
                    'kind'               => $line['line_kind'],
                    'artifact_public_id' => $line['artifact_public_id'],
                    'name'               => $line['artifact_name'],
                    'label'              => $line['variant_label'] ?? '',
                    'quantity'           => (int) $line['quantity'],
                    'unit_price'         => Money::format($line['unit_price']),
                    'compare_price'      => Money::format($line['compare_price']),
                    'discount'           => Money::format($line['discount_amount']),
                    'tax'                => ['calculation' => $line['tax_calculation'], 'percentage' => Money::format($line['tax_percentage']), 'amount' => Money::format($line['tax_amount'])],
                    'total'              => Money::format($line['line_total']),
                    'store'              => $at,
                    'starts_at'          => self::local($line['starts_at'], $at['timezone'] ?? 'UTC'),
                    'ends_at'            => self::local($line['ends_at'], $at['timezone'] ?? 'UTC'),
                    'participants'       => $people[(int) $line['id']] ?? [],
                ];
            }, $lines),
            'shipping'   => $row['shipping_method_type'] === null ? null : [
                'type'         => $row['shipping_method_type'],
                'name'         => $row['shipping_method_name'],
                'address'      => $row['shipping_method_type'] !== 'H' ? null : ['line_1' => $row['address_line_1'], 'line_2' => $row['address_line_2'], 'city' => $row['city'],
                    'subdivision_code' => $row['subdivision_code'], 'postal_code' => $row['postal_code'], 'country_code' => $row['country_code']],
                'pickup_point' => $row['pickup_point'],
                'pickup_store' => $row['pickup_store_id'] === null ? null : $store((int) $row['pickup_store_id']),
            ],
            'payment'       => $payment,
            'discount_code' => $row['discount_code'],
            'totals'        => [
                'subtotal'     => Money::format($row['subtotal']),
                'discount'     => Money::format($row['discount_amount']),
                'shipping'     => Money::format($row['shipping_fee']),
                'tax_included' => Money::format($row['tax_included']),
                'tax_added'    => Money::format($row['tax_added']),
                'total'        => Money::format($row['total_amount']),
            ],
        ];
    }

    // --- pricing -----------------------------------------------------------------------------------------------

    /**
     * The cart priced, with the ids place() needs; quote() is its public view
     */
    private function priced(array $business, string $language, array $cart, array $choice, DateTimeImmutable $now): array
    {
        $businessId = (int) $business['id'];
        $stores     = $this->storesOf($businessId, $language);
        $variants   = $this->variantsOf($businessId, array_column($cart, 'variant'), $language);
        $inventory  = $this->inventoryOf(array_map(static fn (array $v): int => $v['id'], array_filter($variants, static fn (array $v): bool => $v['artifact_type'] === 'P')));

        $lines = [];
        foreach ($cart as $index => $item) {
            $variant = $variants[$item['variant']] ?? null;
            $line    = [
                'key' => $item['key'], 'kind' => $item['kind'], 'variant' => $item['variant'], 'quantity' => (int) $item['quantity'],
                'variant_id' => $variant['id'] ?? null, 'artifact_id' => $variant['artifact_id'] ?? null,
                'artifact_public_id' => $variant['artifact_public_id'] ?? null, 'artifact_name' => $variant['artifact_name'] ?? '',
                'label' => $variant['label'] ?? '', 'sku' => $variant['sku'] ?? null,
                'unit' => $variant === null ? 0 : self::cents($variant['price_active']),
                'compare' => $variant === null || $variant['price_compare'] === null ? null : self::cents($variant['price_compare']),
                'tax_calculation' => $variant['tax_calculation'] ?? 'X', 'tax_percentage' => $variant['tax_percentage'] ?? null,
                'weight' => (int) ($variant['weight_grams'] ?? 0), 'tracked' => false,
                'store_id' => null, 'session_id' => null, 'starts_at' => null, 'ends_at' => null, 'staff' => [], 'resources' => [],
                'discount' => 0, 'tax' => 0, 'problem' => null,
            ];
            $wanted = ['P' => 'P', 'A' => 'S', 'G' => 'S'][$item['kind']];
            if ($variant === null || $variant['artifact_type'] !== $wanted || ($item['kind'] !== 'P' && $variant['booking_type'] !== $item['kind'])) {
                $line['problem'] = ['code' => 'not_found', 'available' => null];
            } elseif ($item['kind'] === self::KIND_PRODUCT) {
                $stock = $inventory[$line['variant_id']] ?? null;
                $line['tracked'] = $stock !== null;
                $total = $stock === null ? null : array_sum($stock);
                if ($total !== null && $total < 1) {
                    $line['problem'] = ['code' => 'out_of_stock', 'available' => 0];
                } elseif ($total !== null && $total < $line['quantity']) {
                    $line['problem'] = ['code' => 'only_left', 'available' => $total];
                }
            } elseif ($item['kind'] === self::KIND_APPOINTMENT) {
                $this->checkAppointment($business, $item, $line, $language, $now);
            } else {
                $this->checkSession($businessId, $item, $line, $language, $now);
            }
            $lines[$index] = $line;
        }

        // the products: where they come from, and how they get to the customer
        $products      = array_filter($lines, static fn (array $l): bool => $l['kind'] === 'P' && $l['problem'] === null);
        $needsShipping = array_filter($lines, static fn (array $l): bool => $l['kind'] === 'P') !== [];
        $methods       = [];
        $shipping      = null;
        $shippingError = null;
        if ($needsShipping) {
            $productCents = array_sum(array_map(static fn (array $l): int => $l['unit'] * $l['quantity'], $products));
            $grams        = array_sum(array_map(static fn (array $l): int => $l['weight'] * $l['quantity'], $products));
            $methods      = $this->shippingMethods($businessId, $language, $stores, $products, $inventory, $productCents, $grams);
            if (($choice['shipping'] ?? '') !== '') {
                $method = current(array_filter($methods, static fn (array $m): bool => $m['public_id'] === $choice['shipping'])) ?: null;
                if ($method === null) {
                    $shippingError = 'not_offered';
                } elseif (! $method['available']) {
                    $shippingError = $method['unavailable'];
                } elseif ($method['pickup_stores'] !== null) {
                    $pickup = $choice['pickup_store'] ?? '';
                    $store  = current(array_filter($stores, static fn (array $s): bool => $s['public_id'] === $pickup)) ?: null;
                    if ($store === null || ! isset($method['pickup_stores'][$store['id']])) {
                        $shippingError = 'pickup_store';
                    } else {
                        $shipping = $method + ['pickup_store_id' => $store['id'], 'from' => $method['pickup_stores'][$store['id']]];
                    }
                } else {
                    $shipping = $method + ['pickup_store_id' => $method['type'] === 'S' ? $method['store_id'] : null];
                }
            }
            $from = $shipping['from'] ?? $this->fulfil($products, array_keys($stores), $inventory, $stores);
            if ($from === null) {
                // stock enough in all, but no store has a line's whole quantity
                foreach ($products as $index => $line) {
                    $most = $line['tracked'] ? max($inventory[$line['variant_id']]) : $line['quantity'];
                    if ($most < $line['quantity']) {
                        $lines[$index]['problem'] = ['code' => 'only_left', 'available' => $most];
                    }
                }
                $from = $this->fulfil(array_filter($products, static fn (array $l, int $i): bool => $lines[$i]['problem'] === null, ARRAY_FILTER_USE_BOTH), array_keys($stores), $inventory, $stores) ?? [];
            }
            foreach ($from as $index => $storeId) {
                $lines[$index]['store_id'] = $storeId;
            }
            if ($products !== [] && array_filter($methods, static fn (array $m): bool => $m['available']) === []) {
                $shippingError ??= 'none';
            }
        }

        // the discount
        $discount      = null;
        $discountError = null;
        $code          = strtoupper(trim((string) ($choice['discount'] ?? '')));
        $good          = array_filter($lines, static fn (array $l): bool => $l['problem'] === null);
        if ($code !== '') {
            $row = $this->db->table(self::DISCOUNTS)->where('business_id', $businessId)->where('discount_code', $code)->get()->getRowArray();
            $at  = $now->setTimezone(new DateTimeZone('UTC'))->format(self::DB_FORMAT);
            if ($row === null || $row['is_active'] !== 'Y' || ($row['valid_from'] !== null && $row['valid_from'] > $at)) {
                $discountError = ['code' => 'not_found', 'amount' => null];
            } elseif ($row['expires_at'] !== null && $row['expires_at'] <= $at) {
                $discountError = ['code' => 'expired', 'amount' => null];
            } elseif ($row['max_redemptions'] !== null && (int) $row['redemption_count'] >= (int) $row['max_redemptions']) {
                $discountError = ['code' => 'used_up', 'amount' => null];
            } else {
                $eligible = array_filter($good, static fn (array $l): bool => $row['applies_to'] === 'A'
                    || ($row['applies_to'] === 'P' ? $l['kind'] === 'P' : $l['kind'] !== 'P'));
                $base = array_sum(array_map(static fn (array $l): int => $l['unit'] * $l['quantity'], $eligible));
                if ($eligible === [] || $base === 0) {
                    $discountError = ['code' => 'not_applicable', 'amount' => null];
                } elseif ($row['min_subtotal'] !== null && $base < self::cents($row['min_subtotal'])) {
                    $discountError = ['code' => 'min_subtotal', 'amount' => Money::format($row['min_subtotal'])];
                } else {
                    $amount = $row['discount_type'] === 'P'
                        ? (int) round($base * (float) $row['discount_value'] / 100)
                        : min(self::cents($row['discount_value']), $base);
                    $discount = ['id' => (int) $row['id'], 'code' => $row['discount_code'], 'description' => $row['description'], 'amount' => $amount];
                    $left     = $amount;
                    $keys     = array_keys($eligible);
                    foreach ($keys as $n => $index) {
                        $share = $n === count($keys) - 1 ? $left
                            : intdiv($amount * $lines[$index]['unit'] * $lines[$index]['quantity'], $base);
                        $lines[$index]['discount'] = $share;
                        $left -= $share;
                    }
                }
            }
        }

        // the tax of each line, and the totals
        $totals = ['subtotal' => 0, 'discount' => 0, 'shipping' => $shipping === null ? 0 : $shipping['fee'], 'tax_included' => 0, 'tax_added' => 0, 'total' => 0];
        foreach ($lines as &$line) {
            $sub  = $line['unit'] * $line['quantity'];
            $base = $sub - $line['discount'];
            $rate = (float) ($line['tax_percentage'] ?? 0);
            $line['tax'] = match ($line['tax_calculation']) {
                'I'     => (int) round($base * $rate / (100 + $rate)),
                'E'     => (int) round($base * $rate / 100),
                default => 0,
            };
            $line['total'] = $base + ($line['tax_calculation'] === 'E' ? $line['tax'] : 0);
            if ($line['problem'] !== null) {
                continue;
            }
            $totals['subtotal'] += $sub;
            $totals['discount'] += $line['discount'];
            $totals[$line['tax_calculation'] === 'E' ? 'tax_added' : 'tax_included'] += $line['tax_calculation'] === 'X' ? 0 : $line['tax'];
        }
        unset($line);
        $totals['total'] = $totals['subtotal'] - $totals['discount'] + $totals['shipping'] + $totals['tax_added'];

        // how it may be paid: at every store, or at the one store everything is at
        $at       = array_values(array_unique(array_filter(array_column($good, 'store_id'))));
        $payments = $this->paymentMethods($businessId, count($at) === 1 ? $at[0] : null, $language);
        if ($business['currency_code'] !== 'THB') {
            $payments = array_values(array_filter($payments, static fn (array $p): bool => $p['handler_code'] !== 'promptpay_qr'));
        }
        $payment = current(array_filter($payments, static fn (array $p): bool => $p['public_id'] === ($choice['payment'] ?? ''))) ?: null;

        return [
            'currency'         => $business['currency_code'],
            'stores'           => $stores,
            'lines'            => $lines,
            'problems'         => count(array_filter($lines, static fn (array $l): bool => $l['problem'] !== null)) + ($shippingError === 'none' ? 1 : 0),
            'needs_shipping'   => $needsShipping,
            'shipping_methods' => $methods,
            'shipping'         => $shipping,
            'shipping_error'   => $shippingError,
            'discount'         => $discount,
            'discount_error'   => $discountError,
            'payment_methods'  => $payments,
            'payment'          => $payment,
            'totals'           => $totals,
        ];
    }

    /** Whether the appointment can still be booked as the cart has it; sets the line's store, times, people and resources */
    private function checkAppointment(array $business, array $item, array &$line, string $language, DateTimeImmutable $now): void
    {
        $slots   = model(MartSlotModel::class);
        $variant = $slots->bookableVariant((int) $business['id'], $item['variant']);
        $store   = null;
        if ($variant !== null) {
            foreach ($slots->storesFor((int) $business['id'], $variant['artifact_id'], $language) as $candidate) {
                if ($candidate['public_id'] === ($item['store'] ?? '')) {
                    $store = $candidate;
                }
            }
        }
        $startsAt = (string) ($item['starts_at'] ?? '');
        if ($variant === null || $store === null || ! preg_match('/^\d{4}-\d{2}-\d{2}T/', $startsAt)) {
            $line['problem'] = ['code' => 'slot_taken', 'available' => null];

            return;
        }
        $wanted = $item['staff'] ?? [];
        sort($wanted);
        $found = null;
        foreach ($slots->slotsOn($business, $variant, $store, substr($startsAt, 0, 10), null, $now) as $slot) {
            $staff = $slot['staff'];
            sort($staff);
            if ($slot['starts_at'] === $startsAt && $staff === $wanted) {
                $found = $slot;
                break;
            }
        }
        $start = $found === null ? null : (new DateTimeImmutable($found['starts_at']))->setTimezone(new DateTimeZone('UTC'));
        $end   = $found === null ? null : (new DateTimeImmutable($found['ends_at']))->setTimezone(new DateTimeZone('UTC'));
        $held  = $found === null ? null : $slots->freeResources((int) $business['id'], $variant['artifact_id'], $store['id'], $start, $end);
        if ($held === null) {
            $line['problem'] = ['code' => 'slot_taken', 'available' => null];

            return;
        }
        $clients = [];
        foreach ($store['staff'] as $clientId => $person) {
            if (in_array($person['public_id'], $wanted, true)) {
                $clients[] = $clientId;
            }
        }
        $line['quantity']  = 1;
        $line['store_id']  = $store['id'];
        $line['starts_at'] = $start->format(self::DB_FORMAT);
        $line['ends_at']   = $end->format(self::DB_FORMAT);
        $line['staff']     = $clients;
        $line['resources'] = $held;
    }

    /** Whether the seats can still be booked; sets the line's session, store and times */
    private function checkSession(int $businessId, array $item, array &$line, string $language, DateTimeImmutable $now): void
    {
        $model   = model(MartSessionModel::class);
        $variant = $model->groupVariant($businessId, $item['variant']);
        $session = null;
        if ($variant !== null) {
            foreach ($model->bookable($businessId, $variant['id'], $language, $now)['sessions'] as $candidate) {
                if ($candidate['public_id'] === ($item['session'] ?? '')) {
                    $session = $candidate;
                }
            }
        }
        if ($session === null) {
            $line['problem'] = ['code' => 'session_gone', 'available' => null];

            return;
        }
        if ($session['seats_available'] < $line['quantity']) {
            $line['problem'] = ['code' => 'seats_left', 'available' => $session['seats_available']];
        }
        $row = $this->db->table('service_session')->select('id, starts_at, ends_at')->where('business_id', $businessId)
            ->where('public_id', $session['public_id'])->get()->getRowArray();
        $line['session_id'] = (int) $row['id'];
        $line['store_id']   = (int) $session['store_id'];
        $line['starts_at']  = $row['starts_at'];
        $line['ends_at']    = $row['ends_at'];
    }

    /**
     * The business's active shipping methods for the products, each with its fee and whether it can take them
     * @return list<array>
     */
    private function shippingMethods(int $businessId, string $language, array $stores, array $products, array $inventory, int $productCents, int $grams): array
    {
        $rows = $this->db->table('business_shipping_method m')
            ->select('m.*, t.method_name AS t_name, t.description AS t_description')
            ->join('business_shipping_method_translation t', 't.shipping_method_id = m.id AND t.language_code = ' . $this->db->escape($language), 'left')
            ->where('m.business_id', $businessId)->where('m.is_active', 'Y')
            ->orderBy('m.sort_order')->orderBy('m.id')->get()->getResultArray();
        if ($rows === []) {
            return [];
        }
        $rates = [];
        foreach ($this->db->table('business_shipping_rate')->whereIn('shipping_method_id', array_column($rows, 'id'))->orderBy('range_from')->get()->getResultArray() as $rate) {
            $rates[(int) $rate['shipping_method_id']][] = $rate;
        }

        $out = [];
        foreach ($rows as $row) {
            $storeId = $row['store_id'] === null ? null : (int) $row['store_id'];
            if ($storeId !== null && ! isset($stores[$storeId])) {
                continue;
            }
            $fee         = self::fee($row, $rates[(int) $row['id']] ?? [], $productCents, $grams);
            $from        = null;
            $pickup      = null;
            $unavailable = null;
            if ($row['method_type'] === 'S' && $storeId === null) {
                // picked up at a store the customer picks: one that has everything
                $pickup = [];
                foreach (array_keys($stores) as $id) {
                    if (($one = $this->fulfil($products, [$id], $inventory, $stores)) !== null) {
                        $pickup[$id] = $one;
                    }
                }
                if ($pickup === []) {
                    $unavailable = 'stock';
                }
            } else {
                $from = $this->fulfil($products, $storeId === null ? array_keys($stores) : [$storeId], $inventory, $stores);
                if ($from === null) {
                    $unavailable = 'stock';
                }
            }
            if ($fee === null) {
                $unavailable ??= 'weight';
            }
            $out[] = [
                'id'            => (int) $row['id'],
                'public_id'     => $row['public_id'],
                'type'          => $row['method_type'],
                'store_id'      => $storeId,
                'name'          => (string) ($row['t_name'] ?? '') !== '' ? $row['t_name'] : $row['method_name'],
                'description'   => (string) ($row['t_description'] ?? '') !== '' ? $row['t_description'] : $row['description'],
                'eta_days_min'  => $row['eta_days_min'] === null ? null : (int) $row['eta_days_min'],
                'eta_days_max'  => $row['eta_days_max'] === null ? null : (int) $row['eta_days_max'],
                'fee'           => $fee ?? 0,
                'available'     => $unavailable === null,
                'unavailable'   => $unavailable,
                'from'          => $from,
                'pickup_stores' => $pickup,
            ];
        }

        return $out;
    }

    /**
     * A method's fee for the order, in cents; null when none of its brackets takes it
     * @param array $method business_shipping_method
     * @param list<array> $rates its business_shipping_rate rows, by range_from
     */
    private static function fee(array $method, array $rates, int $productCents, int $grams): ?int
    {
        if ($rates === []) {
            return 0;
        }
        if ($method['free_over_amount'] !== null && $productCents >= self::cents($method['free_over_amount'])) {
            return 0;
        }
        $byAmount = $method['fee_basis'] === 'P';
        $value    = $byAmount ? $productCents : $grams * 100; // grams in hundredths, so both compare as the ranges' cents
        $bracket  = null;
        foreach ($rates as $rate) {
            if (self::cents($rate['range_from']) <= $value && ($rate['range_to'] === null || $value < self::cents($rate['range_to']))) {
                $bracket = $rate;
            }
        }
        if ($bracket === null) {
            return null;
        }
        $fee = self::cents($bracket['fee_amount']);
        if ($bracket['step_size'] !== null && $bracket['step_fee'] !== null && self::cents($bracket['step_size']) > 0) {
            $steps = (int) ceil(($value - self::cents($bracket['range_from'])) / self::cents($bracket['step_size']));
            $fee  += $steps * self::cents($bracket['step_fee']);
        }

        return $fee;
    }

    /**
     * The store each product line comes out of, among $storeIds (see the class's comment)
     * @param array<int, array> $lines     index => priced line, products only
     * @param list<int>         $storeIds
     * @return array<int, int>|null index => store_id; null when a line's quantity is in none of them
     */
    private function fulfil(array $lines, array $storeIds, array $inventory, array $stores): ?array
    {
        if ($lines === []) {
            return [];
        }
        if ($storeIds === []) {
            return null;
        }
        $has = static fn (array $line, int $storeId): int => $line['tracked'] ? (int) ($inventory[$line['variant_id']][$storeId] ?? 0) : PHP_INT_MAX;
        $rank = static function (array $ids, callable $stock) use ($stores): array {
            usort($ids, static fn (int $a, int $b): int => [($stores[$a]['type'] === 'O' ? 0 : 1), -$stock($a), $stores[$a]['name'], $a]
                <=> [($stores[$b]['type'] === 'O' ? 0 : 1), -$stock($b), $stores[$b]['name'], $b]);

            return $ids;
        };

        $whole = array_values(array_filter($storeIds, static function (int $storeId) use ($lines, $has): bool {
            foreach ($lines as $line) {
                if ($has($line, $storeId) < $line['quantity']) {
                    return false;
                }
            }

            return true;
        }));
        if ($whole !== []) {
            $total = static fn (int $storeId): int => array_sum(array_map(static fn (array $l): int => $l['tracked'] ? (int) ($inventory[$l['variant_id']][$storeId] ?? 0) : 0, $lines));
            $best  = $rank($whole, $total)[0];

            return array_map(static fn (): int => $best, $lines);
        }

        // no store has it all: each line from the one with the most of it
        $out = [];
        foreach ($lines as $index => $line) {
            $enough = array_values(array_filter($storeIds, static fn (int $id): bool => $has($line, $id) >= $line['quantity']));
            if ($enough === []) {
                return null;
            }
            $out[$index] = $rank($enough, static fn (int $id): int => $line['tracked'] ? $has($line, $id) : 0)[0];
        }

        return $out;
    }

    // --- reading ------------------------------------------------------------------------------------------------

    /**
     * The business's stores, name in the language where it has one
     * @return array<int, array{id: int, public_id: string, name: string, type: string, timezone: string, address: array}>
     */
    private function storesOf(int $businessId, string $language): array
    {
        $out = [];
        foreach ($this->db->table('business_store s')
            ->select('s.*, t.store_name AS t_name')
            ->join('business_store_translation t', 't.store_id = s.id AND t.language_code = ' . $this->db->escape($language), 'left')
            ->where('s.business_id', $businessId)->orderBy('s.store_name')->orderBy('s.id')->get()->getResultArray() as $row) {
            $out[(int) $row['id']] = [
                'id'        => (int) $row['id'],
                'public_id' => $row['public_id'],
                'name'      => (string) ($row['t_name'] ?? '') !== '' ? $row['t_name'] : $row['store_name'],
                'type'      => $row['store_type'],
                'timezone'  => $row['store_timezone'],
                'address'   => ['line_1' => $row['store_address_line_1'], 'line_2' => $row['store_address_line_2'], 'city' => $row['store_city'],
                    'subdivision_code' => $row['store_subdivision_code'], 'postal_code' => $row['store_postal_code']],
            ];
        }

        return $out;
    }

    /**
     * The variants of the cart that are the business's, active, of an active artifact
     * @param list<string> $publicIds
     * @return array<string, array> public_id => variant with its artifact's type, tax and name, and its label
     */
    private function variantsOf(int $businessId, array $publicIds, string $language): array
    {
        $publicIds = array_values(array_unique(array_filter($publicIds, 'is_string')));
        if ($publicIds === []) {
            return [];
        }
        $rows = $this->db->table('artifact_variant v')
            ->select('v.id, v.public_id, v.artifact_id, v.sku, v.price_active, v.price_compare, v.weight_grams, v.booking_type, '
                . 'a.public_id AS artifact_public_id, a.artifact_type, a.artifact_name, a.tax_calculation, a.tax_percentage, t.artifact_name AS t_name')
            ->join('artifact_master a', 'a.id = v.artifact_id')
            ->join('artifact_master_translation t', 't.artifact_id = a.id AND t.language_code = ' . $this->db->escape($language), 'left')
            ->where('a.business_id', $businessId)->where('a.artifact_status', 'A')->where('v.variant_status', 'A')
            ->whereIn('v.public_id', $publicIds)->get()->getResultArray();
        $labels = [];
        if ($rows !== []) {
            foreach ($this->db->table('artifact_variant_attribute_value l')
                ->select('l.variant_id, x.attribute_value, t.attribute_value AS t_name')
                ->join('artifact_attribute_value x', 'x.id = l.attribute_value_id')
                ->join('artifact_attribute_value_translation t', 't.attribute_value_id = x.id AND t.language_code = ' . $this->db->escape($language), 'left')
                ->whereIn('l.variant_id', array_column($rows, 'id'))->orderBy('l.id')->get()->getResultArray() as $value) {
                $labels[(int) $value['variant_id']][] = (string) ($value['t_name'] ?? '') !== '' ? $value['t_name'] : $value['attribute_value'];
            }
        }
        $out = [];
        foreach ($rows as $row) {
            $out[$row['public_id']] = [
                'id'          => (int) $row['id'],
                'artifact_id' => (int) $row['artifact_id'],
                'artifact_name' => (string) ($row['t_name'] ?? '') !== '' ? $row['t_name'] : $row['artifact_name'],
                'label'       => implode(' / ', $labels[(int) $row['id']] ?? []),
                'tax_percentage' => $row['tax_percentage'] === null ? null : Money::format($row['tax_percentage']),
            ] + $row;
        }

        return $out;
    }

    /**
     * The stock of the variants that some store keeps count of
     * @param list<int> $variantIds
     * @return array<int, array<int, int>> variant_id => store_id => quantity
     */
    private function inventoryOf(array $variantIds): array
    {
        if ($variantIds === []) {
            return [];
        }
        $out = [];
        foreach ($this->db->table('artifact_variant_inventory')->whereIn('variant_id', array_values($variantIds))->get()->getResultArray() as $row) {
            $out[(int) $row['variant_id']][(int) $row['store_id']] = max(0, (int) $row['quantity']);
        }

        return $out;
    }

    /**
     * The active payment methods of every store, and of $storeId when given; or the one with $id
     * @return list<array{id: int, public_id: string, method_code: string, handler_code: string, name: string, instruction: string|null, settings: array}>
     */
    private function paymentMethods(int $businessId, ?int $storeId, string $language, ?int $id = null): array
    {
        $builder = $this->db->table('business_payment_method m')
            ->select('m.*, t.method_name AS t_name, t.instruction AS t_instruction')
            ->join('business_payment_method_translation t', 't.payment_method_id = m.id AND t.language_code = ' . $this->db->escape($language), 'left')
            ->where('m.business_id', $businessId);
        if ($id !== null) {
            $builder->where('m.id', $id);
        } else {
            $builder->where('m.is_active', 'Y')->groupStart()->where('m.store_id', null);
            if ($storeId !== null) {
                $builder->orWhere('m.store_id', $storeId);
            }
            $builder->groupEnd();
        }

        return array_map(static fn (array $row): array => [
            'id'           => (int) $row['id'],
            'public_id'    => $row['public_id'],
            'method_code'  => $row['method_code'],
            'handler_code' => $row['handler_code'],
            'name'         => (string) ($row['t_name'] ?? '') !== '' ? $row['t_name'] : $row['method_name'],
            'instruction'  => (string) ($row['t_instruction'] ?? '') !== '' ? $row['t_instruction'] : $row['instruction'],
            'settings'     => is_array($settings = json_decode((string) $row['settings_json'], true)) ? $settings : [],
        ], $builder->orderBy('m.sort_order')->orderBy('m.id')->get()->getResultArray());
    }

    // --- placing ------------------------------------------------------------------------------------------------

    /**
     * Lock what the order takes, so two orders cannot both take the last of it (MySQL; SQLite, the test database,
     * runs one writer at a time): the stock of the products, the sessions, and the discount
     */
    private function lockRows(int $businessId, array $cart, array $choice): void
    {
        if ($this->db->DBDriver !== 'MySQLi') {
            return;
        }
        $products = array_values(array_unique(array_column(array_filter($cart, static fn (array $l): bool => $l['kind'] === 'P'), 'variant')));
        if ($products !== []) {
            $this->db->query('SELECT i.id FROM artifact_variant_inventory i JOIN artifact_variant v ON v.id = i.variant_id'
                . ' WHERE v.business_id = ? AND v.public_id IN ? ORDER BY i.id FOR UPDATE', [$businessId, $products]);
        }
        $sessions = array_values(array_unique(array_filter(array_column(array_filter($cart, static fn (array $l): bool => $l['kind'] === 'G'), 'session'), 'is_string')));
        if ($sessions !== []) {
            $this->db->query('SELECT id FROM service_session WHERE business_id = ? AND public_id IN ? ORDER BY id FOR UPDATE', [$businessId, $sessions]);
        }
        $code = strtoupper(trim((string) ($choice['discount'] ?? '')));
        if ($code !== '') {
            $this->db->query('SELECT id FROM ' . self::DISCOUNTS . ' WHERE business_id = ? AND discount_code = ? FOR UPDATE', [$businessId, $code]);
        }
    }

    /** What the delivery is missing for the shipping method */
    private static function deliveryErrors(?array $shipping, array $delivery): array
    {
        $errors = [];
        if (($shipping['type'] ?? null) === 'H') {
            foreach (['line_1', 'city', 'postal_code'] as $field) {
                if ($delivery[$field] === '') {
                    $errors['address.' . $field] = 'required';
                }
            }
        } elseif (($shipping['type'] ?? null) === 'L' && $delivery['pickup_point'] === '') {
            $errors['pickup_point'] = 'required';
        }

        return $errors;
    }

    /** customer_master's id for the email address, added when it is new; a known customer's details stay as they are */
    private function customerId(array $customer, string $name, DateTimeImmutable $now): int
    {
        $at  = $now->setTimezone(new DateTimeZone('UTC'))->format(self::DB_FORMAT);
        $row = $this->db->table('customer_master')->select('id')->where('email_address', $customer['email'])->get()->getRowArray();
        if ($row !== null) {
            $this->db->table('customer_master')->where('id', $row['id'])->update(['last_activity' => $at]);

            return (int) $row['id'];
        }
        $this->db->table('customer_master')->insert([
            'public_id'        => model(CustomerMasterModel::class)->newPublicId(),
            'email_address'    => $customer['email'],
            'telephone_number' => $customer['telephone'],
            'customer_name'    => mb_substr($name, 0, 255),
            'last_activity'    => $at,
        ]);

        return (int) $this->db->insertID();
    }

    // --- views --------------------------------------------------------------------------------------------------

    /** priced() as quote() answers it */
    private function view(array $priced): array
    {
        $stores = $priced['stores'];
        $store  = static fn (?int $id): ?array => $id === null || ! isset($stores[$id]) ? null : ['public_id' => $stores[$id]['public_id'], 'name' => $stores[$id]['name']];
        $money  = static fn (int $cents): string => ($cents < 0 ? '-' : '') . Money::fromCents(abs($cents));

        return [
            'currency' => $priced['currency'],
            'lines'    => array_values(array_map(static fn (array $l): array => [
                'key'           => $l['key'],
                'kind'          => $l['kind'],
                'variant'       => $l['variant'],
                'artifact'      => $l['artifact_public_id'],
                'name'          => $l['artifact_name'],
                'label'         => $l['label'],
                'quantity'      => $l['quantity'],
                'unit_price'    => $money($l['unit']),
                'compare_price' => $l['compare'] === null ? null : $money($l['compare']),
                'subtotal'      => $money($l['unit'] * $l['quantity']),
                'discount'      => $money($l['discount']),
                'tax'           => ['calculation' => $l['tax_calculation'], 'percentage' => $l['tax_percentage'], 'amount' => $money($l['tax'])],
                'total'         => $money($l['total']),
                'store'         => $store($l['store_id']),
                'starts_at'     => $l['starts_at'] === null ? null : self::local($l['starts_at'], $stores[$l['store_id']]['timezone'] ?? 'UTC'),
                'ends_at'       => $l['ends_at'] === null ? null : self::local($l['ends_at'], $stores[$l['store_id']]['timezone'] ?? 'UTC'),
                'problem'       => $l['problem'],
            ], $priced['lines'])),
            'problems'         => $priced['problems'],
            'needs_shipping'   => $priced['needs_shipping'],
            'shipping_methods' => array_map(static fn (array $m): array => [
                'public_id'     => $m['public_id'],
                'type'          => $m['type'],
                'name'          => $m['name'],
                'description'   => $m['description'],
                'eta_days_min'  => $m['eta_days_min'],
                'eta_days_max'  => $m['eta_days_max'],
                'fee'           => $money($m['fee']),
                'available'     => $m['available'],
                'unavailable'   => $m['unavailable'],
                'store'         => $store($m['store_id']),
                'pickup_stores' => $m['pickup_stores'] === null ? null : array_values(array_map(static fn (int $id): array => $store($id) + ['address' => $stores[$id]['address']], array_keys($m['pickup_stores']))),
            ], $priced['shipping_methods']),
            'shipping'       => $priced['shipping'] === null ? null : ['public_id' => $priced['shipping']['public_id'], 'type' => $priced['shipping']['type'],
                'name' => $priced['shipping']['name'], 'fee' => $money($priced['shipping']['fee']), 'pickup_store' => $store($priced['shipping']['pickup_store_id'])],
            'shipping_error' => $priced['shipping_error'],
            'discount'       => $priced['discount'] === null ? null : ['code' => $priced['discount']['code'], 'description' => $priced['discount']['description'],
                'amount' => $money($priced['discount']['amount'])],
            'discount_error'  => $priced['discount_error'],
            'payment_methods' => array_map(static fn (array $p): array => ['public_id' => $p['public_id'], 'method_code' => $p['method_code'],
                'handler_code' => $p['handler_code'], 'name' => $p['name'], 'instruction' => $p['instruction']], $priced['payment_methods']),
            'payment' => $priced['payment']['public_id'] ?? null,
            'totals'  => array_map($money, $priced['totals']),
        ];
    }

    /** A stored amount in cents */
    private static function cents(mixed $amount): int
    {
        return Money::toCents((string) Money::format($amount));
    }

    /** A UTC datetime as ISO 8601 with the timezone's offset */
    private static function local(?string $utc, string $timezone): ?string
    {
        return $utc === null ? null : (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($timezone))->format(self::LOCAL_FORMAT);
    }
}
