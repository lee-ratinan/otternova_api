<?php

namespace App\Controllers;

use App\Libraries\ApiResponse;
use App\Libraries\OrderNumber;
use App\Models\BusinessMasterModel;
use CodeIgniter\HTTP\Exceptions\HTTPException;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\I18n\Time;
use DateTimeImmutable;
use Throwable;

/**
 * How the order numbers of a business look (App\Libraries\OrderNumber): its active owners and managers only.
 * - POST /client/order-numbers:      the settings, and the number the next order would get
 * - POST /client/order-numbers/save: change them
 */
class OrderNumbers extends BaseController
{
    use ManagedBusinessTrait;

    /**
     * POST /client/order-numbers { business_slug }
     * { order_numbers: {order_prefix, order_date_part, order_reset, order_digits}, next: the number the next order would
     *   get now, e.g. IN2611-0003 }
     * @return ResponseInterface
     */
    public function show(): ResponseInterface
    {
        $endpoint = 'v1/client/order-numbers';
        $input    = $this->jsonInput();
        if ($input === null) {
            return ApiResponse::error(lang('OrderNumber.invalidRequest'), 422);
        }
        [$business, $error] = $this->managedBusinessBySlug($input, $endpoint, 'OrderNumber');
        if ($error !== null) {
            return $error;
        }
        try {
            return ApiResponse::success($this->settingsOf((int) $business['id']));
        } catch (Throwable $e) {
            log_message('error', $endpoint . ': {message}', ['message' => $e->getMessage()]);

            return ApiResponse::error(lang('OrderNumber.loadFailed'), 500);
        }
    }

    /**
     * POST /client/order-numbers/save { business_slug, order_numbers: {order_prefix, order_date_part, order_reset, order_digits} }
     * A field left out keeps its value. order_prefix: 0 to 4 letters A-Z (lower case is made upper); order_date_part
     * and order_reset: N, Y, M or D; order_digits: 3 to 6. The reset may not be more often than the date part shows
     * (errors.order_reset). The orders numbered already keep their numbers. Answers as /client/order-numbers.
     * @return ResponseInterface
     */
    public function save(): ResponseInterface
    {
        $endpoint = 'v1/client/order-numbers/save';
        $input    = $this->jsonInput();
        if ($input === null) {
            return ApiResponse::error(lang('OrderNumber.invalidRequest'), 422);
        }
        [$business, $error] = $this->managedBusinessBySlug($input, $endpoint, 'OrderNumber');
        if ($error !== null) {
            return $error;
        }
        $in = $input['order_numbers'] ?? [];
        if (! is_array($in)) {
            return ApiResponse::error(lang('OrderNumber.invalidRequest'), 422);
        }
        try {
            $stored = model(BusinessMasterModel::class)->select('id, ' . implode(', ', OrderNumber::FIELDS))->find((int) $business['id']);
        } catch (Throwable $e) {
            log_message('error', $endpoint . ': {message}', ['message' => $e->getMessage()]);

            return ApiResponse::error(lang('OrderNumber.loadFailed'), 500);
        }
        [$settings, $codes] = OrderNumber::check(array_intersect_key($in, array_flip(OrderNumber::FIELDS)) + $stored);
        if ($codes !== []) {
            $errors = [];
            foreach ($codes as $field => $code) {
                $errors[$field] = lang('OrderNumber.' . ($code === 'too_often' ? 'resetTooOften' : $field));
            }

            return ApiResponse::error(lang('OrderNumber.invalidFields'), 422, $errors);
        }
        try {
            model(BusinessMasterModel::class)->saveOrderNumbering($stored, $settings, $this->actorId());

            return ApiResponse::success($this->settingsOf((int) $business['id']), 200, lang('OrderNumber.saved'));
        } catch (Throwable $e) {
            log_message('error', $endpoint . ': {message}', ['message' => $e->getMessage()]);

            return ApiResponse::error(lang('OrderNumber.saveFailed'), 500);
        }
    }

    private function settingsOf(int $businessId): array
    {
        $row      = model(BusinessMasterModel::class)->select('id, country_code, ' . implode(', ', OrderNumber::FIELDS))->find($businessId);
        $settings = array_intersect_key($row, array_flip(OrderNumber::FIELDS));
        $settings['order_digits'] = (int) $settings['order_digits'];

        return [
            'order_numbers' => $settings,
            'next'          => OrderNumber::upcoming(db_connect(), $row, DateTimeImmutable::createFromInterface(Time::now('UTC'))),
        ];
    }

    /** The JSON body as an object, [] when there is none; null when it is not one */
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

    /** auth_master.id of the signed-in account */
    private function actorId(): int
    {
        return (int) service('authContext')->account()['id'];
    }
}
