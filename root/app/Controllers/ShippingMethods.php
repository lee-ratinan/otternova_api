<?php

namespace App\Controllers;

use App\Libraries\ApiResponse;
use App\Models\BusinessMasterModel;
use App\Models\BusinessShippingMethodModel;
use App\Models\BusinessStoreModel;
use CodeIgniter\HTTP\Exceptions\HTTPException;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * The ways a customer of a business can get a physical product (business_shipping_method) and what each costs
 * (business_shipping_rate): the Client app's, behind the jwt:C filter (clients only), and only for the active owners
 * and managers of the business.
 * A business comes in by its business_slug, a store and a method by their public_id; the ids stay inside.
 * A method is offered at every store of the business (store_public_id null) or at one store only.
 */
class ShippingMethods extends BaseController
{
    use ManagedBusinessTrait;
    use TranslationInputTrait;

    /** The columns of business_shipping_method_translation and their longest lengths, the name (required) first */
    private const TRANSLATION_FIELDS = [
        'method_name' => 100,
        'description' => BusinessShippingMethodModel::DESCRIPTION_MAX,
    ];

    /**
     * POST /client/shipping-methods { business_slug, store_public_id? }
     * The shipping methods of that business by sort_order and name, or with store_public_id those offered at that
     * store (the business-wide ones and its own). For each: public_id, store_public_id (null for every store),
     * method_type, method_name, description, fee_basis, free_over_amount, eta_days_min, eta_days_max, is_active,
     * sort_order, rates (by range_from: {range_from, range_to, fee_amount, step_size, step_fee}) and translations
     * (language_code => {method_name, description}). Amounts and grams are strings with 2 decimals.
     * With "options", what create and edit take: method_types, fee_bases and rates_max.
     * @return ResponseInterface
     */
    public function list(): ResponseInterface
    {
        $endpoint = 'v1/client/shipping-methods';
        $input    = $this->jsonInput();
        if ($input === null) {
            return ApiResponse::error(lang('ShippingMethod.invalidRequest'), 422);
        }
        [$business, $error] = $this->managedBusinessBySlug($input, $endpoint, 'ShippingMethod');
        if ($error !== null) {
            return $error;
        }
        try {
            $storeId = null;
            if (($input['store_public_id'] ?? null) !== null) {
                $storeId = $this->storeIdOf((int) $business['id'], $input['store_public_id']);
                if ($storeId === null) {
                    return ApiResponse::error(lang('ShippingMethod.storeNotFound'), 404);
                }
            }
            $rows = model(BusinessShippingMethodModel::class)->listOfBusiness((int) $business['id'], $storeId);
        } catch (Throwable $e) {
            log_message('error', $endpoint . ': {message}', ['message' => $e->getMessage()]);

            return ApiResponse::error(lang('ShippingMethod.loadFailed'), 500);
        }

        return ApiResponse::success([
            'shipping_methods' => $rows,
            'options'          => [
                'method_types' => BusinessShippingMethodModel::METHOD_TYPES,
                'fee_bases'    => BusinessShippingMethodModel::FEE_BASES,
                'rates_max'    => BusinessShippingMethodModel::RATES_MAX,
            ],
        ]);
    }

    /**
     * POST /client/shipping-method/create { business_slug, shipping_method: {store_public_id, method_type,
     * method_name, description, fee_basis, free_over_amount, eta_days_min, eta_days_max, is_active, sort_order},
     * rates: [{range_from, range_to, fee_amount, step_size, step_fee}], translations: {language_code: {method_name,
     * description}} }
     * Creates a shipping method of that business. store_public_id left out or null: offered at every store.
     * method_type H (home delivery), L (locker or pickup point) or S (store pickup); fee_basis P (subtotal, the
     * default) or W (weight in grams); free_over_amount null (never free by amount) or an amount; eta_days 0 to 255,
     * min no more than max; is_active Y (the default) or N; sort_order 0 to 255 (0).
     * "rates" is optional (none: free); range_from defaults to 0, range_to null is no upper bound, step_size and
     * step_fee go together, and the brackets may not overlap. "translations" is optional; each key must be a
     * language of listLanguages(), and a language with a description needs its method_name too.
     * @return ResponseInterface
     */
    public function create(): ResponseInterface
    {
        return $this->saveMethod(false);
    }

    /**
     * POST /client/shipping-method/edit { public_id, shipping_method: {...}, rates: [...], translations: {...} }
     * Updates the shipping method with that public_id. A field left out of "shipping_method" keeps its value.
     * "rates" and "translations", when given, replace them all; left out, they stay as they are. A change of fee_basis
     * needs "rates", as the stored brackets are in the old unit.
     * @return ResponseInterface
     */
    public function edit(): ResponseInterface
    {
        return $this->saveMethod(true);
    }

    /**
     * create() and edit()
     * @param bool $edit
     * @return ResponseInterface
     */
    private function saveMethod(bool $edit): ResponseInterface
    {
        $endpoint = $edit ? 'v1/client/shipping-method/edit' : 'v1/client/shipping-method/create';
        $input    = $this->jsonInput();
        if ($input === null) {
            return ApiResponse::error(lang('ShippingMethod.invalidRequest'), 422);
        }
        $in             = $input['shipping_method'] ?? [];
        $ratesIn        = array_key_exists('rates', $input) ? $input['rates'] : ($edit ? null : []);
        $translationsIn = $input['translations'] ?? ($edit ? null : []);
        if (! is_array($in) || ($in !== [] && array_is_list($in))) {
            return ApiResponse::error(lang('ShippingMethod.invalidRequest'), 422);
        }

        $methods = model(BusinessShippingMethodModel::class);
        $stored  = null;
        if ($edit) {
            $publicId = $input['public_id'] ?? null;
            if (! is_string($publicId) || $publicId === '') {
                return ApiResponse::error(lang('ShippingMethod.idRequired'), 400);
            }
            try {
                $stored   = $methods->findByPublicId($publicId);
                $business = $stored === null ? null : model(BusinessMasterModel::class)->select('id')->find((int) $stored['business_id']);
            } catch (Throwable $e) {
                log_message('error', $endpoint . ': {message}', ['message' => $e->getMessage()]);

                return ApiResponse::error(lang('ShippingMethod.loadFailed'), 500);
            }
            $error = $business === null ? ApiResponse::error(lang('ShippingMethod.notFound'), 404) : $this->roleError((int) $business['id'], $endpoint, lang('ShippingMethod.notFound'), 'ShippingMethod');
        } else {
            [$business, $error] = $this->managedBusinessBySlug($input, $endpoint, 'ShippingMethod');
        }
        if ($error !== null) {
            return $error;
        }

        // a field left out of an edit keeps its value; left out of a create, it takes its default
        $value  = static fn (string $field, mixed $default) => array_key_exists($field, $in) ? $in[$field] : ($stored[$field] ?? $default);
        $method = [
            'business_id'      => (int) $business['id'],
            'method_type'      => strtoupper(self::text($value('method_type', ''))),
            'method_name'      => self::text($value('method_name', '')),
            'description'      => self::text($value('description', null)),
            'fee_basis'        => strtoupper(self::text($value('fee_basis', 'P'))),
            'free_over_amount' => BusinessShippingMethodModel::decimal($value('free_over_amount', null)),
            'eta_days_min'     => self::text($value('eta_days_min', null)),
            'eta_days_max'     => self::text($value('eta_days_max', null)),
            'is_active'        => strtoupper(self::text($value('is_active', 'Y'))),
            'sort_order'       => self::text($value('sort_order', 0)),
        ];
        foreach (['description', 'eta_days_min', 'eta_days_max'] as $field) {
            $method[$field] = $method[$field] === '' ? null : $method[$field];
        }

        $errors = [];
        $rates  = null;
        if ($ratesIn !== null) {
            [$rates, $rateErrors] = BusinessShippingMethodModel::ratesIn($ratesIn, $method['fee_basis']);
            $errors += $rateErrors;
        } elseif ($stored !== null && $stored['fee_basis'] !== $method['fee_basis']) {
            $errors['rates'] = lang('BusinessShippingMethod.ratesRequired');
        }

        $names = null;
        if ($translationsIn !== null) {
            [$names, $translationErrors] = $this->translationsIn($translationsIn, self::TRANSLATION_FIELDS);
            if ($names === null) {
                return ApiResponse::error(lang('ShippingMethod.invalidRequest'), 422);
            }
            $errors += $translationErrors;
        }

        $rules = [];
        foreach ($methods->getValidationRules(['only' => array_keys($method)]) as $field => $fieldRules) {
            $rules[$field] = ['label' => lang('BusinessShippingMethod.fields.' . $field), 'rules' => $fieldRules];
        }
        $validation = single_service('validation')->setRules($rules);
        if (! $validation->run($method)) {
            $errors = $validation->getErrors() + $errors;
        }
        $errors += BusinessShippingMethodModel::methodErrors($method);

        try {
            if (array_key_exists('store_public_id', $in)) {
                $method['store_id'] = null;
                if ($in['store_public_id'] !== null && $in['store_public_id'] !== '') {
                    $method['store_id'] = $this->storeIdOf((int) $business['id'], $in['store_public_id']);
                    if ($method['store_id'] === null) {
                        $errors['store_public_id'] = lang('ShippingMethod.storeNotFound');
                    }
                }
            } else {
                $method['store_id'] = $stored === null || $stored['store_id'] === null ? null : (int) $stored['store_id'];
            }
            if ($errors !== []) {
                return ApiResponse::error(lang('ShippingMethod.invalidFields'), 422, $errors);
            }

            $method['sort_order'] = (int) $method['sort_order'];
            foreach (['eta_days_min', 'eta_days_max'] as $field) {
                $method[$field] = $method[$field] === null ? null : (int) $method[$field];
            }
            $actorId = $this->actorId();
            if ($stored === null) {
                $publicId = $methods->createWithRates($method, $actorId, $rates ?? [], $names ?? []);
                $saved    = $publicId !== null;
            } else {
                $saved    = $methods->updateWithRates($stored, $method, $actorId, $rates, $names);
                $publicId = $stored['public_id'];
            }
        } catch (Throwable $e) {
            log_message('error', $endpoint . ': {message}', ['message' => $e->getMessage()]);

            return ApiResponse::error(lang('ShippingMethod.saveFailed'), 500);
        }
        if (! $saved) {
            return ApiResponse::error(lang('ShippingMethod.invalidFields'), 422, ['method_name' => lang('ShippingMethod.nameTaken')]);
        }

        return ApiResponse::success(['public_id' => $publicId], 200, lang($edit ? 'ShippingMethod.updated' : 'ShippingMethod.created'));
    }

    /**
     * The id of the business's store with this public_id
     * @param int   $businessId
     * @param mixed $publicId From the request
     * @return int|null Null when there is none, or it is another business's
     */
    private function storeIdOf(int $businessId, mixed $publicId): ?int
    {
        $store = model(BusinessStoreModel::class)->findByPublicId($publicId);

        return $store !== null && (int) $store['business_id'] === $businessId ? (int) $store['id'] : null;
    }

    /**
     * A value from the body as a trimmed string; anything but a string or a number becomes '', which the rules refuse
     * where a value is needed
     * @param mixed $value
     * @return string
     */
    private static function text(mixed $value): string
    {
        return is_string($value) || is_int($value) ? trim((string) $value) : '';
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
