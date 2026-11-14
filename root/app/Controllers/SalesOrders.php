<?php

namespace App\Controllers;

use App\Libraries\ApiResponse;
use App\Libraries\Money;
use App\Libraries\OrderNumber;
use App\Models\BusinessPaymentMethodModel;
use App\Models\SalesOrderModel;
use CodeIgniter\HTTP\Exceptions\HTTPException;
use CodeIgniter\HTTP\ResponseInterface;
use DomainException;
use Throwable;

/**
 * The orders a business's customers placed on the mart, as the business works on them (App\Models\SalesOrderModel):
 * the Client app's, behind the jwt:C filter, for any active client of the business, its employees included
 * (ManagedBusinessTrait::ALL_ROLES), as the sales pages are everyone's; only refunds are kept to its owners and
 * managers. A business comes in by its business_slug, an order and a payment by their public_id.
 * financial_status is never set here: it follows the payments and refunds recorded.
 */
class SalesOrders extends BaseController
{
    use ManagedBusinessTrait;

    /** The longest reference and note taken */
    private const REFERENCE_MAX = 100;
    private const NOTE_MAX      = 1000;
    private const REASON_MAX    = 255;

    /**
     * POST /client/orders { business_slug, start, length, search, filters: { date_from, date_to, order_status,
     * financial_status, fulfillment_status } }
     * One page of that business's orders, newest first: { records_total, records_filtered, rows, timezone }, rows as
     * SalesOrderModel::listOfBusiness() gives them. date_from and date_to ("Y-m-d", both included) are days of the
     * business's clock (timezone, sent back); search looks for each of its words in the order number, the customer's
     * name, email address and telephone number. fulfillment_status "none" finds the orders with nothing to send.
     * start is 0 and length 25 when left out (at most 500); every filter is optional, and one that is not valid is
     * left out.
     * @return ResponseInterface
     */
    public function list(): ResponseInterface
    {
        $endpoint = 'v1/client/orders';
        $input    = $this->jsonInput();
        if ($input === null || ! is_array($input['filters'] ?? [])) {
            return ApiResponse::error(lang('SalesOrder.invalidRequest'), 422);
        }
        [$business, $error] = $this->managedBusinessBySlug($input, $endpoint, 'SalesOrder', self::ALL_ROLES);
        if ($error !== null) {
            return $error;
        }
        $start     = filter_var($input['start'] ?? null, FILTER_VALIDATE_INT);
        $length    = filter_var($input['length'] ?? null, FILTER_VALIDATE_INT);
        $start     = $start === false ? 0 : max(0, $start);
        $length    = $length === false ? 25 : min(500, max(1, $length));
        $search    = is_string($input['search'] ?? null) ? mb_substr(trim($input['search']), 0, 100) : '';
        $filtersIn = $input['filters'] ?? [];
        $text      = static fn (string $name): string => is_string($filtersIn[$name] ?? null) ? trim($filtersIn[$name]) : '';
        $filters   = [];
        foreach (['order_status' => SalesOrderModel::ORDER_STATUSES, 'financial_status' => SalesOrderModel::FINANCIAL_STATUSES,
            'fulfillment_status' => [...SalesOrderModel::FULFILLMENT_STATUSES, 'none']] as $name => $allowed) {
            if (in_array($text($name), $allowed, true)) {
                $filters[$name] = $text($name);
            }
        }
        try {
            $timezone = OrderNumber::timezoneOf(db_connect(), $business);
            if (($from = SalesOrderModel::dayStartUtc($text('date_from'), $timezone)) !== null) {
                $filters['from'] = $from;
            }
            if (($to = SalesOrderModel::dayStartUtc($text('date_to'), $timezone, 1)) !== null) {
                $filters['to'] = $to;
            }
            $data = model(SalesOrderModel::class)->listOfBusiness((int) $business['id'], $start, $length, $filters, $search);
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'SalesOrder.loadFailed');
        }

        return ApiResponse::success($data + ['timezone' => $timezone->getName()]);
    }

    /**
     * POST /client/order { public_id }
     * One order, as SalesOrderModel::detail() gives it: { order, payment_methods: [{public_id, method_name}] (the
     * business's active ones, for recording a payment), timezone, can_refund }
     * @return ResponseInterface
     */
    public function show(): ResponseInterface
    {
        $endpoint = 'v1/client/order';
        [$order, $business, $error] = $this->managedOrder($endpoint);
        if ($error !== null) {
            return $error;
        }

        return $this->detail($endpoint, $order, $business);
    }

    /**
     * POST /client/order/status { public_id, order_status, fulfillment_status }
     * Changes how far the business is with the order: order_status open, in_progress or closed; fulfillment_status
     * open, partially_fulfilled or fully_fulfilled, for an order with products only. Either may be left out.
     * @return ResponseInterface { order, payment_methods, timezone, can_refund }, as show()
     */
    public function saveStatus(): ResponseInterface
    {
        $endpoint = 'v1/client/order/status';
        [$order, $business, $error, $input] = $this->managedOrder($endpoint);
        if ($error !== null) {
            return $error;
        }
        $changes = [];
        $errors  = [];
        foreach (['order_status' => SalesOrderModel::ORDER_STATUSES, 'fulfillment_status' => SalesOrderModel::FULFILLMENT_STATUSES] as $field => $allowed) {
            if (! array_key_exists($field, $input)) {
                continue;
            }
            $value = is_string($input[$field]) ? trim($input[$field]) : '';
            if (! in_array($value, $allowed, true)) {
                $errors[$field] = lang('SalesOrder.invalidStatus');
            } else {
                $changes[$field] = $value;
            }
        }
        if (isset($changes['fulfillment_status']) && $order['fulfillment_status'] === null) {
            $errors['fulfillment_status'] = lang('SalesOrder.noProducts');
        }
        if ($errors !== []) {
            return ApiResponse::error(lang('SalesOrder.invalidFields'), 422, $errors);
        }
        $orders = model(SalesOrderModel::class);
        try {
            $orders->setStatuses($order, $changes, $this->actorId());
            $order = $orders->find((int) $order['id']);
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'SalesOrder.saveFailed');
        }

        return $this->detail($endpoint, $order, $business, lang('SalesOrder.statusSaved'));
    }

    /**
     * POST /client/order/payment/create { public_id, payment: { transaction_type (payment, the default, or refund),
     * transaction_status (succeeded, the default, pending or failed), amount, payment_method_public_id (one of the
     * business's; the order's own when left out), reference, note, failure_reason (for failed) } }
     * Records a payment of the order or a refund of it, and works out its financial_status again. A succeeded
     * payment may not be above the balance due, nor a succeeded refund above what was paid and not given back yet.
     * Refunds are for the owners and managers of the business only.
     * @return ResponseInterface { order, payment_methods, timezone, can_refund }, as show()
     */
    public function createPayment(): ResponseInterface
    {
        $endpoint = 'v1/client/order/payment/create';
        [$order, $business, $error, $input] = $this->managedOrder($endpoint);
        if ($error !== null) {
            return $error;
        }
        $in = $input['payment'] ?? null;
        if (! is_array($in) || ($in !== [] && array_is_list($in))) {
            return ApiResponse::error(lang('SalesOrder.invalidRequest'), 422);
        }
        $value   = static fn (string $field, string $default = ''): string => is_string($in[$field] ?? null) || is_int($in[$field] ?? null) || is_float($in[$field] ?? null)
            ? trim((string) $in[$field]) : $default;
        $payment = [
            'transaction_type'   => $value('transaction_type', SalesOrderModel::TYPE_PAYMENT) ?: SalesOrderModel::TYPE_PAYMENT,
            'transaction_status' => $value('transaction_status', SalesOrderModel::TXN_SUCCEEDED) ?: SalesOrderModel::TXN_SUCCEEDED,
            'amount'             => Money::normalize($in['amount'] ?? null),
            'payment_method'     => null,
            'reference'          => $value('reference') === '' ? null : $value('reference'),
            'note'               => $value('note') === '' ? null : $value('note'),
            'failure_reason'     => $value('failure_reason') === '' ? null : $value('failure_reason'),
        ];
        $errors = [];
        if (! in_array($payment['transaction_type'], SalesOrderModel::TYPES, true)) {
            $errors['transaction_type'] = lang('SalesOrder.invalidType');
        }
        if (! in_array($payment['transaction_status'], [SalesOrderModel::TXN_PENDING, SalesOrderModel::TXN_SUCCEEDED, SalesOrderModel::TXN_FAILED], true)) {
            $errors['transaction_status'] = lang('SalesOrder.invalidStatus');
        }
        if ($payment['amount'] === null || Money::isZero($payment['amount'])) {
            $errors['amount'] = lang('SalesOrder.invalidAmount');
        }
        if ($payment['reference'] !== null && mb_strlen($payment['reference']) > self::REFERENCE_MAX) {
            $errors['reference'] = lang('SalesOrder.tooLong', [self::REFERENCE_MAX]);
        }
        if ($payment['note'] !== null && mb_strlen($payment['note']) > self::NOTE_MAX) {
            $errors['note'] = lang('SalesOrder.tooLong', [self::NOTE_MAX]);
        }
        if ($payment['failure_reason'] !== null && mb_strlen($payment['failure_reason']) > self::REASON_MAX) {
            $errors['failure_reason'] = lang('SalesOrder.tooLong', [self::REASON_MAX]);
        }
        $methodId = $value('payment_method_public_id');
        if ($methodId !== '') {
            try {
                $method = model(BusinessPaymentMethodModel::class)->findByPublicId($methodId);
            } catch (Throwable $e) {
                return $this->failed($endpoint, $e, 'SalesOrder.loadFailed');
            }
            if ($method === null || (int) $method['business_id'] !== (int) $order['business_id']) {
                $errors['payment_method_public_id'] = lang('SalesOrder.unknownMethod');
            } else {
                $payment['payment_method'] = $method;
            }
        }
        if ($errors !== []) {
            return ApiResponse::error(lang('SalesOrder.invalidFields'), 422, $errors);
        }
        if ($payment['transaction_type'] === SalesOrderModel::TYPE_REFUND
            && ($error = $this->roleError((int) $order['business_id'], $endpoint, lang('SalesOrder.notFound'), 'SalesOrder')) !== null) {
            return ApiResponse::error(lang('SalesOrder.refundManagersOnly'), $error->getStatusCode());
        }

        $orders = model(SalesOrderModel::class);
        try {
            $orders->recordPayment($order, $payment, $this->actorId());
            $order = $orders->find((int) $order['id']);
        } catch (DomainException $e) {
            return ApiResponse::error(lang('SalesOrder.invalidFields'), 422, ['amount' => lang('SalesOrder.' . $e->getMessage())]);
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'SalesOrder.saveFailed');
        }

        return $this->detail($endpoint, $order, $business, lang($payment['transaction_type'] === SalesOrderModel::TYPE_REFUND ? 'SalesOrder.refundRecorded' : 'SalesOrder.paymentRecorded'));
    }

    /**
     * POST /client/order/payment/settle { public_id (of the payment), transaction_status (succeeded, failed or voided),
     * failure_reason (for failed), reference }
     * Settles a pending payment or refund of an order, and works out the order's financial_status again; refunds are
     * for the owners and managers of the business only.
     * @return ResponseInterface { order, payment_methods, timezone, can_refund }, as show()
     */
    public function settlePayment(): ResponseInterface
    {
        $endpoint = 'v1/client/order/payment/settle';
        $input    = $this->jsonInput();
        if ($input === null) {
            return ApiResponse::error(lang('SalesOrder.invalidRequest'), 422);
        }
        $publicId = $input['public_id'] ?? null;
        if (! is_string($publicId) || $publicId === '') {
            return ApiResponse::error(lang('SalesOrder.paymentIdRequired'), 400);
        }
        $orders = model(SalesOrderModel::class);
        try {
            $payment = db_connect()->table(SalesOrderModel::PAYMENTS)->where('public_id', $publicId)->get()->getRowArray();
            $order   = $payment === null ? null : $orders->find((int) $payment['order_id']);
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'SalesOrder.loadFailed');
        }
        $roles = ($payment['transaction_type'] ?? null) === SalesOrderModel::TYPE_REFUND ? self::MANAGING_ROLES : self::ALL_ROLES;
        $error = $order === null
            ? ApiResponse::error(lang('SalesOrder.paymentNotFound'), 404)
            : $this->roleError((int) $order['business_id'], $endpoint, lang('SalesOrder.paymentNotFound'), 'SalesOrder', $roles);
        if ($error !== null) {
            return $error->getStatusCode() === 403 ? ApiResponse::error(lang('SalesOrder.refundManagersOnly'), 403) : $error;
        }
        $status = is_string($input['transaction_status'] ?? null) ? trim($input['transaction_status']) : '';
        $reason = is_string($input['failure_reason'] ?? null) && trim($input['failure_reason']) !== '' ? trim($input['failure_reason']) : null;
        $ref    = is_string($input['reference'] ?? null) && trim($input['reference']) !== '' ? trim($input['reference']) : null;
        $errors = [];
        if (! in_array($status, [SalesOrderModel::TXN_SUCCEEDED, SalesOrderModel::TXN_FAILED, SalesOrderModel::TXN_VOIDED], true)) {
            $errors['transaction_status'] = lang('SalesOrder.invalidStatus');
        }
        if ($reason !== null && mb_strlen($reason) > self::REASON_MAX) {
            $errors['failure_reason'] = lang('SalesOrder.tooLong', [self::REASON_MAX]);
        }
        if ($ref !== null && mb_strlen($ref) > self::REFERENCE_MAX) {
            $errors['reference'] = lang('SalesOrder.tooLong', [self::REFERENCE_MAX]);
        }
        if ($errors !== []) {
            return ApiResponse::error(lang('SalesOrder.invalidFields'), 422, $errors);
        }

        try {
            $orders->settlePayment($order, $payment, $status, $reason, $ref, $this->actorId());
            $order = $orders->find((int) $order['id']);
        } catch (DomainException $e) {
            $field = $e->getMessage() === 'notPending' ? 'transaction_status' : 'amount';

            return ApiResponse::error(lang('SalesOrder.invalidFields'), 422, [$field => lang('SalesOrder.' . $e->getMessage())]);
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'SalesOrder.saveFailed');
        }

        return $this->detail($endpoint, $order, ['id' => $order['business_id']], lang('SalesOrder.paymentSettled'));
    }

    /**
     * The order of the request's public_id, when the signed-in client is active in its business
     * @param string $endpoint
     * @return array{0: array|null, 1: array|null, 2: ResponseInterface|null, 3: array} [order, business (id,
     *   country_code), error, input]
     */
    private function managedOrder(string $endpoint): array
    {
        $input = $this->jsonInput();
        if ($input === null) {
            return [null, null, ApiResponse::error(lang('SalesOrder.invalidRequest'), 422), []];
        }
        $publicId = $input['public_id'] ?? null;
        if (! is_string($publicId) || $publicId === '') {
            return [null, null, ApiResponse::error(lang('SalesOrder.idRequired'), 400), $input];
        }
        try {
            $order    = model(SalesOrderModel::class)->findByPublicId($publicId);
            $business = $order === null ? null : db_connect()->table('business_master')->select('id, country_code')->where('id', $order['business_id'])->get()->getRowArray();
        } catch (Throwable $e) {
            return [null, null, $this->failed($endpoint, $e, 'SalesOrder.loadFailed'), $input];
        }
        // an order of a business the client is not in is as unknown as one that is not there
        $error = $business === null
            ? ApiResponse::error(lang('SalesOrder.notFound'), 404)
            : $this->roleError((int) $business['id'], $endpoint, lang('SalesOrder.notFound'), 'SalesOrder', self::ALL_ROLES);

        return $error === null ? [$order, $business, null, $input] : [null, null, $error, $input];
    }

    /**
     * The answer of show() and of the changes
     * @param string      $endpoint
     * @param array       $order    order_master
     * @param array       $business id, country_code
     * @param string|null $message
     * @return ResponseInterface
     */
    private function detail(string $endpoint, array $order, array $business, ?string $message = null): ResponseInterface
    {
        try {
            $db       = db_connect();
            $business = $db->table('business_master')->select('id, country_code')->where('id', $business['id'])->get()->getRowArray();
            $methods  = $db->table('business_payment_method')->select('public_id, method_name')
                ->where('business_id', $business['id'])->where('is_active', 'Y')->orderBy('sort_order')->orderBy('id')->get()->getResultArray();
            $data     = [
                'order'           => model(SalesOrderModel::class)->detail($order),
                'payment_methods' => $methods,
                'timezone'        => OrderNumber::timezoneOf($db, $business)->getName(),
                'can_refund'      => $this->roleError((int) $business['id'], $endpoint, '', 'SalesOrder') === null,
            ];
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'SalesOrder.loadFailed');
        }

        return ApiResponse::success($data, 200, $message);
    }

    private function failed(string $endpoint, Throwable $e, string $message): ResponseInterface
    {
        log_message('error', $endpoint . ': {message}', ['message' => $e->getMessage()]);

        return ApiResponse::error(lang($message), 500);
    }

    /**
     * auth_master.id of the signed-in account, for log_activity.changed_by_acc and ManagedBusinessTrait
     * @return int
     */
    private function actorId(): int
    {
        return (int) service('authContext')->account()['id'];
    }

    /**
     * The JSON body as an array: an empty body counts as {}, and anything that is not a JSON object is null
     * @return array|null
     */
    private function jsonInput(): ?array
    {
        if (trim((string) $this->request->getBody()) === '') {
            return [];
        }

        try {
            $input = $this->request->getJSON(true);
        } catch (HTTPException) {
            return null;
        }

        return is_array($input) && ($input === [] || ! array_is_list($input)) ? $input : null;
    }
}
