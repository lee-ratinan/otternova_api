<?php

namespace App\Controllers;

use App\Libraries\ApiResponse;
use App\Libraries\EmailService;
use App\Libraries\Money;
use App\Libraries\OrderNumber;
use App\Models\BusinessPaymentMethodModel;
use App\Models\SalesOrderModel;
use CodeIgniter\I18n\Time;
use CodeIgniter\HTTP\Exceptions\HTTPException;
use CodeIgniter\HTTP\ResponseInterface;
use DomainException;
use Throwable;

/**
 * The orders a business's customers placed on the mart, as the business works on them (App\Models\SalesOrderModel):
 * the Client app's, behind the jwt:C filter, for any active client of the business, its employees included
 * (ManagedBusinessTrait::ALL_ROLES), as the sales pages are everyone's; only refunds are kept to its owners and
 * managers, and so is settling a cancelled order's refund without refunding it all. Anyone may move a private
 * appointment to another time or other people until the cancellation deadline before it (the customer is emailed). Anyone may cancel an order before
 * any of it is sent or starts, giving a reason; its customer is emailed and its owners and managers notified. A
 * business comes in by its business_slug, an order and a payment by their public_id.
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
        if (in_array($filtersIn['needs_refund'] ?? null, [true, 1, '1', 'Y', 'y', 'true'], true)) {
            $filters['needs_refund'] = true;
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
        } catch (DomainException $e) {
            return ApiResponse::error(lang('SalesOrder.' . $e->getMessage()), 409);
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
     * POST /client/order/reschedule/slots { public_id, line_number, date }
     * The free times a private appointment of the order may move to on date (Y-m-d on its store's clock), as at the
     * counter (the booking window aside), its own holds aside: { date, until (UTC, the last moment it may be moved),
     * staff: [{public_id, name}], slots }. Anyone working in the business, until the cancellation deadline.
     * @return ResponseInterface
     */
    public function rescheduleSlots(): ResponseInterface
    {
        $endpoint = 'v1/client/order/reschedule/slots';
        [$order, , $error, $input] = $this->managedOrder($endpoint);
        if ($error !== null) {
            return $error;
        }
        $date = is_string($input['date'] ?? null) ? $input['date'] : '';
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || date('Y-m-d', (int) strtotime($date)) !== $date) {
            return ApiResponse::error(lang('SalesOrder.invalidFields'), 422, ['date' => lang('SalesOrder.invalidDate')]);
        }
        try {
            $data = model(SalesOrderModel::class)->rescheduleSlots($order, (int) ($input['line_number'] ?? 0), $date, $this->language($order), self::nowAt());
        } catch (DomainException $e) {
            return ApiResponse::error(lang('SalesOrder.cannotReschedule.' . $e->getMessage()), $e->getMessage() === 'notFound' ? 404 : 409);
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'SalesOrder.loadFailed');
        }

        return ApiResponse::success($data);
    }

    /**
     * POST /client/order/reschedule { public_id, line_number, starts_at, staff }
     * Moves a private appointment of the order to one of /client/order/reschedule/slots' times, with its people
     * (public_ids): another time, or other people at the same time when the one booked cannot come. Anyone working
     * in the business, until the cancellation deadline before the booked start. The customer is emailed the new time.
     * @return ResponseInterface { order, payment_methods, timezone, can_refund }, as show()
     */
    public function reschedule(): ResponseInterface
    {
        $endpoint = 'v1/client/order/reschedule';
        [$order, $business, $error, $input] = $this->managedOrder($endpoint);
        if ($error !== null) {
            return $error;
        }
        $startsAt = is_string($input['starts_at'] ?? null) ? $input['starts_at'] : '';
        $staff    = $input['staff'] ?? null;
        if ($startsAt === '' || ! is_array($staff) || ! array_is_list($staff) || $staff === [] || array_filter($staff, static fn ($s): bool => ! is_string($s)) !== []) {
            return ApiResponse::error(lang('SalesOrder.invalidFields'), 422, ['starts_at' => lang('SalesOrder.chooseTime')]);
        }
        $orders = model(SalesOrderModel::class);
        try {
            $moved = $orders->reschedule($order, (int) ($input['line_number'] ?? 0), $startsAt, $staff, $this->language($order), $this->actorId(), self::nowAt());
            $order = $orders->find((int) $order['id']);
        } catch (DomainException $e) {
            return ApiResponse::error(lang('SalesOrder.cannotReschedule.' . $e->getMessage()), $e->getMessage() === 'notFound' ? 404 : 409);
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'SalesOrder.saveFailed');
        }
        $this->emailReschedule($order, $moved);

        return $this->detail($endpoint, $order, $business, lang('SalesOrder.rescheduled'));
    }

    /**
     * Tell the customer the new time of their booking, on its store's clock; a failure is logged, the move stands
     */
    private function emailReschedule(array $order, array $moved): void
    {
        if (trim((string) $order['email_address']) === '') {
            return;
        }
        try {
            $business = db_connect()->table('business_master')->select('business_name, business_slug')->where('id', $order['business_id'])->get()->getRowArray();
            $zone     = new \DateTimeZone($moved['timezone'] ?: 'UTC');
            $local    = static fn (string $utc): string => (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->setTimezone($zone)->format('Y-m-d H:i');
            service('emailService')->send(EmailService::TYPE_ORDER_RESCHEDULED, $order['email_address'], [
                'first_name'    => $order['first_name'],
                'order_number'  => $order['order_number'],
                'business_name' => $business['business_name'] ?? '',
                'service'       => $moved['line']['artifact_name'],
                'from'          => $local($moved['from']),
                'to'            => $local($moved['to']),
                'link'          => rtrim((string) env('LINK_MART', ''), '/') . '/' . ($business['business_slug'] ?? '') . '/check-order-status/' . rawurlencode((string) $order['order_number']),
            ], $order['language_code']);
        } catch (Throwable $e) {
            log_message('error', 'Order rescheduled email of {order}: {message}', ['order' => $order['order_number'], 'message' => $e->getMessage()]);
        }
    }

    /**
     * The language the order's names are shown in: the request's, among the business's
     */
    private function language(array $order): string
    {
        return model(\App\Models\MartCatalogModel::class)->languageOf((int) $order['business_id'], (string) $this->request->getLocale())[0];
    }

    /** Now, which a test can set (Time::setTestNow()) */
    private static function nowAt(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface(Time::now('UTC'));
    }

    /**
     * GET /client/order/payment-slip/{payment public_id}
     * The slip a customer sent with a payment of an order, for anyone working in its business; 404 when it has none
     * @param string $publicId order_payment.public_id
     * @return ResponseInterface The file, private and not to be cached
     */
    public function paymentSlip(string $publicId): ResponseInterface
    {
        $endpoint = 'v1/client/order/payment-slip';
        try {
            $payment = preg_match('/^[0-9A-Za-z_-]{12}$/', $publicId) ? db_connect()->table(SalesOrderModel::PAYMENTS)->where('public_id', $publicId)->get()->getRowArray() : null;
            $order   = $payment === null ? null : model(SalesOrderModel::class)->find((int) $payment['order_id']);
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'SalesOrder.loadFailed');
        }
        $error = $order === null ? ApiResponse::error(lang('SalesOrder.paymentNotFound'), 404)
            : $this->roleError((int) $order['business_id'], $endpoint, lang('SalesOrder.paymentNotFound'), 'SalesOrder', self::ALL_ROLES);
        if ($error !== null) {
            return $error;
        }
        $types = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'pdf' => 'application/pdf'];
        $name  = $payment['slip_path'] ?? null;
        $path  = $name !== null && preg_match('/^\d+-[0-9a-f]{16}\.(jpg|png|webp|pdf)$/', $name, $parts)
            ? rtrim(config(\Config\Mart::class)->orderSlipUploadPath, '/') . '/' . $name : null;
        if ($path === null || ! is_file($path)) {
            return ApiResponse::error(lang('SalesOrder.noSlip'), 404);
        }

        return $this->response
            ->removeHeader('Cache-Control')
            ->setHeader('Cache-Control', 'private, no-store')
            ->setHeader('Content-Disposition', 'inline; filename="slip-' . $publicId . '.' . $parts[1] . '"')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setContentType($types[$parts[1]], '')
            ->setBody((string) file_get_contents($path));
    }

    /**
     * POST /client/order/cancel { public_id, reason, restock }
     * Cancels the order, for anyone working in the business: only before any of its products is sent and before any
     * of its booked services starts (after that it is a no-show). reason (3 to 255 characters) is required. restock
     * (true when left out) puts the products back in stock. Its pending payments are voided, its appointments and
     * seats given back; what was paid is left as refund_due_amount for an owner or manager, who are notified. The
     * customer is emailed.
     * @return ResponseInterface { order, payment_methods, timezone, can_refund }, as show()
     */
    public function cancel(): ResponseInterface
    {
        $endpoint = 'v1/client/order/cancel';
        [$order, $business, $error, $input] = $this->managedOrder($endpoint);
        if ($error !== null) {
            return $error;
        }
        [$reason, $reasonError] = $this->reason($input['reason'] ?? null);
        if ($reasonError !== null) {
            return ApiResponse::error(lang('SalesOrder.invalidFields'), 422, ['reason' => $reasonError]);
        }
        $restock = ! in_array($input['restock'] ?? true, [false, 0, '0', 'N', 'n', 'false'], true);
        $orders  = model(SalesOrderModel::class);
        try {
            $order = $orders->cancel($order, $reason, $restock, $this->actorId(), self::now());
        } catch (DomainException $e) {
            return ApiResponse::error(lang('SalesOrder.cannotCancel.' . $e->getMessage()), 409);
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'SalesOrder.saveFailed');
        }
        $this->emailCancellation($order);

        return $this->detail($endpoint, $order, $business, lang('SalesOrder.cancelled'));
    }

    /**
     * POST /client/order/no-show { public_id }
     * Marks the order a no-show, for anyone working in the business: once its first booked service has started. When
     * nothing of it was paid, its customer is flagged by the business.
     * @return ResponseInterface { order, payment_methods, timezone, can_refund, flagged }
     */
    public function noShow(): ResponseInterface
    {
        $endpoint = 'v1/client/order/no-show';
        [$order, $business, $error] = $this->managedOrder($endpoint);
        if ($error !== null) {
            return $error;
        }
        $orders = model(SalesOrderModel::class);
        try {
            $flagged = $orders->noShow($order, $this->actorId(), self::now());
            $order   = $orders->find((int) $order['id']);
        } catch (DomainException $e) {
            return ApiResponse::error(lang('SalesOrder.cannotNoShow.' . $e->getMessage()), 409);
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'SalesOrder.saveFailed');
        }

        return $this->detail($endpoint, $order, $business, lang($flagged ? 'SalesOrder.noShowFlagged' : 'SalesOrder.noShowMarked'));
    }

    /**
     * POST /client/order/refund-close { public_id, reason }
     * Settles a cancelled order's refund without refunding what is left (a late-cancellation fee kept): the owners and
     * managers of the business only. reason (3 to 255 characters) is required.
     * @return ResponseInterface { order, payment_methods, timezone, can_refund }, as show()
     */
    public function closeRefund(): ResponseInterface
    {
        $endpoint = 'v1/client/order/refund-close';
        [$order, $business, $error, $input] = $this->managedOrder($endpoint);
        if ($error !== null) {
            return $error;
        }
        if (($error = $this->roleError((int) $order['business_id'], $endpoint, lang('SalesOrder.notFound'), 'SalesOrder')) !== null) {
            return ApiResponse::error(lang('SalesOrder.refundManagersOnly'), $error->getStatusCode());
        }
        [$reason, $reasonError] = $this->reason($input['reason'] ?? null);
        if ($reasonError !== null) {
            return ApiResponse::error(lang('SalesOrder.invalidFields'), 422, ['reason' => $reasonError]);
        }
        $orders = model(SalesOrderModel::class);
        try {
            $orders->closeRefund($order, $reason, $this->actorId());
            $order = $orders->find((int) $order['id']);
        } catch (DomainException $e) {
            return ApiResponse::error(lang('SalesOrder.' . $e->getMessage()), 409);
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'SalesOrder.saveFailed');
        }

        return $this->detail($endpoint, $order, $business, lang('SalesOrder.refundClosed'));
    }

    /**
     * A reason from the body, its spaces tidied
     * @return array{0: string, 1: string|null} [reason, error when it is missing, too short or too long]
     */
    private function reason(mixed $value): array
    {
        $reason = is_string($value) ? trim((string) preg_replace('/\s+/u', ' ', $value)) : '';
        $length = mb_strlen($reason);
        if ($length < SalesOrderModel::REASON_MIN) {
            return [$reason, lang('SalesOrder.reasonRequired', [SalesOrderModel::REASON_MIN])];
        }

        return [$reason, $length > SalesOrderModel::REASON_MAX ? lang('SalesOrder.tooLong', [SalesOrderModel::REASON_MAX]) : null];
    }

    /**
     * Tell the customer their order was cancelled, in the order's language, with what they paid; a failure is logged,
     * the cancellation stands
     * @param array $order order_master, cancelled
     */
    private function emailCancellation(array $order): void
    {
        // a counter customer may have given only a telephone number
        if (trim((string) $order['email_address']) === '') {
            return;
        }
        try {
            $business = db_connect()->table('business_master')->select('business_name, business_slug')->where('id', $order['business_id'])->get()->getRowArray();
            $paid     = model(SalesOrderModel::class)->moneyOf($order)['net_paid'];
            service('emailService')->send($paid > 0 ? EmailService::TYPE_ORDER_CANCELLED_PAID : EmailService::TYPE_ORDER_CANCELLED, $order['email_address'], [
                'first_name'    => $order['first_name'],
                'order_number'  => $order['order_number'],
                'business_name' => $business['business_name'] ?? '',
                'amount'        => $order['currency_code'] . ' ' . number_format($paid / 100, 2),
                'link'          => rtrim((string) env('LINK_MART', ''), '/') . '/' . ($business['business_slug'] ?? '') . '/check-order-status/' . rawurlencode((string) $order['order_number']),
            ], $order['language_code']);
        } catch (Throwable $e) {
            log_message('error', 'Order cancelled email of {order}: {message}', ['order' => $order['order_number'], 'message' => $e->getMessage()]);
        }
    }

    /**
     * Now, UTC, as the database keeps it
     */
    private static function now(): string
    {
        return Time::now('UTC')->toDateTimeString();
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
                'order'           => model(SalesOrderModel::class)->detail($order, self::now()),
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
