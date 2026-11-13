<?php

namespace App\Controllers;

use App\Libraries\ApiResponse;
use App\Models\BusinessMasterModel;
use App\Models\BusinessPaymentMethodModel;
use App\Models\BusinessStoreModel;
use CodeIgniter\HTTP\Exceptions\HTTPException;
use CodeIgniter\HTTP\ResponseInterface;
use Config\BusinessPayment;
use Throwable;

/**
 * The ways the customers of a business can pay it (business_payment_method): the Client app's, behind the jwt:C
 * filter (clients only), and only for the active owners and managers of the business.
 * A business comes in by its business_slug, a store and a method by their public_id; the ids stay inside.
 * A method is offered at every store of the business (store_public_id null) or at one store only.
 */
class PaymentMethods extends BaseController
{
    use ManagedBusinessTrait;
    use TranslationInputTrait;

    /** The columns of business_payment_method_translation and their longest lengths, the name (required) first */
    private const TRANSLATION_FIELDS = [
        'method_name' => 100,
        'instruction' => BusinessPaymentMethodModel::INSTRUCTION_MAX,
    ];

    /**
     * POST /client/payment-methods { business_slug, store_public_id? }
     * The payment methods of that business by sort_order and name, or with store_public_id those offered at that store
     * (the business-wide ones and its own). For each: public_id, store_public_id (null for every store), method_code,
     * handler_code, confirmation (A by the provider, M by staff), method_name, instruction, settings, is_active,
     * sort_order and translations (language_code => {method_name, instruction}).
     * With "options", what create and edit take: methods (the method_code values) and handlers
     * (handler_code => {methods, confirmation, instruction_required, settings: the keys it needs}).
     * @return ResponseInterface
     */
    public function list(): ResponseInterface
    {
        $endpoint = 'v1/client/payment-methods';
        $input    = $this->jsonInput();
        if ($input === null) {
            return ApiResponse::error(lang('PaymentMethod.invalidRequest'), 422);
        }
        [$business, $error] = $this->managedBusinessBySlug($input, $endpoint, 'PaymentMethod');
        if ($error !== null) {
            return $error;
        }
        try {
            $storeId = null;
            if (($input['store_public_id'] ?? null) !== null) {
                $storeId = $this->storeIdOf((int) $business['id'], $input['store_public_id']);
                if ($storeId === null) {
                    return ApiResponse::error(lang('PaymentMethod.storeNotFound'), 404);
                }
            }
            $rows = model(BusinessPaymentMethodModel::class)->listOfBusiness((int) $business['id'], $storeId);
        } catch (Throwable $e) {
            log_message('error', $endpoint . ': {message}', ['message' => $e->getMessage()]);

            return ApiResponse::error(lang('PaymentMethod.loadFailed'), 500);
        }

        $config   = config(BusinessPayment::class);
        $handlers = [];
        foreach ($config->handlers as $code => $handler) {
            $handlers[$code] = [
                'methods'              => $handler['methods'],
                'confirmation'         => $handler['confirmation'],
                'instruction_required' => $handler['instruction'],
                'settings'             => array_keys($handler['settings']),
            ];
        }

        return ApiResponse::success([
            'payment_methods' => $rows,
            'options'         => ['methods' => $config->methods, 'handlers' => $handlers],
        ]);
    }

    /**
     * POST /client/payment-method/create { business_slug, payment_method: {store_public_id, method_code, handler_code,
     * method_name, instruction, settings: {...}, is_active, sort_order}, translations: {language_code: {method_name,
     * instruction}} }
     * Creates a payment method of that business. store_public_id left out or null: offered at every store.
     * method_code and handler_code must go together (Config\BusinessPayment); the handler instruction needs an
     * instruction, promptpay_qr a settings.promptpay_id. is_active is Y (the default) or N; sort_order 0 to 255 (0).
     * "translations" is optional; each key must be a language of listLanguages(), and a language with an instruction
     * needs its method_name too.
     * @return ResponseInterface
     */
    public function create(): ResponseInterface
    {
        return $this->saveMethod(false);
    }

    /**
     * POST /client/payment-method/edit { public_id, payment_method: {...}, translations: {...} }
     * Updates the payment method with that public_id. A field left out of "payment_method" keeps its value; so do the
     * settings the handler still takes. "translations", when given, replaces them all; left out, they stay as they are.
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
        $endpoint = $edit ? 'v1/client/payment-method/edit' : 'v1/client/payment-method/create';
        $input    = $this->jsonInput();
        if ($input === null) {
            return ApiResponse::error(lang('PaymentMethod.invalidRequest'), 422);
        }
        $in             = $input['payment_method'] ?? [];
        $translationsIn = $input['translations'] ?? ($edit ? null : []);
        if (! is_array($in) || ($in !== [] && array_is_list($in))) {
            return ApiResponse::error(lang('PaymentMethod.invalidRequest'), 422);
        }

        $methods = model(BusinessPaymentMethodModel::class);
        $stored  = null;
        if ($edit) {
            $publicId = $input['public_id'] ?? null;
            if (! is_string($publicId) || $publicId === '') {
                return ApiResponse::error(lang('PaymentMethod.idRequired'), 400);
            }
            try {
                $stored   = $methods->findByPublicId($publicId);
                $business = $stored === null ? null : model(BusinessMasterModel::class)->select('id, country_code')->find((int) $stored['business_id']);
            } catch (Throwable $e) {
                log_message('error', $endpoint . ': {message}', ['message' => $e->getMessage()]);

                return ApiResponse::error(lang('PaymentMethod.loadFailed'), 500);
            }
            $error = $business === null ? ApiResponse::error(lang('PaymentMethod.notFound'), 404) : $this->roleError((int) $business['id'], $endpoint, lang('PaymentMethod.notFound'), 'PaymentMethod');
        } else {
            [$business, $error] = $this->managedBusinessBySlug($input, $endpoint, 'PaymentMethod');
        }
        if ($error !== null) {
            return $error;
        }

        // a field left out of an edit keeps its value; left out of a create, it takes its default
        $value = static fn (string $field, mixed $default) => array_key_exists($field, $in) ? $in[$field] : ($stored[$field] ?? $default);
        $method = [
            'business_id'  => (int) $business['id'],
            'method_code'  => strtolower(self::text($value('method_code', ''))),
            'handler_code' => strtolower(self::text($value('handler_code', ''))),
            'method_name'  => self::text($value('method_name', '')),
            'instruction'  => self::text($value('instruction', null)),
            'is_active'    => strtoupper(self::text($value('is_active', 'Y'))),
            'sort_order'   => self::text($value('sort_order', 0)),
        ];
        $method['instruction'] = $method['instruction'] === '' ? null : $method['instruction'];

        $errors = [];
        if (array_key_exists('settings', $in) && $in['settings'] !== null && (! is_array($in['settings']) || ($in['settings'] !== [] && array_is_list($in['settings'])))) {
            $errors['settings'] = lang('BusinessPaymentMethod.invalidSettings');
        }
        $settingsIn = array_key_exists('settings', $in) ? $in['settings'] : ($stored === null ? [] : BusinessPaymentMethodModel::settingsOf($stored));
        $settings   = BusinessPaymentMethodModel::settingsFor($method['handler_code'], is_array($settingsIn) ? $settingsIn : []);

        $names = null;
        if ($translationsIn !== null) {
            [$names, $translationErrors] = $this->translationsIn($translationsIn, self::TRANSLATION_FIELDS);
            if ($names === null) {
                return ApiResponse::error(lang('PaymentMethod.invalidRequest'), 422);
            }
            $errors += $translationErrors;
        }

        $rules = [];
        foreach ($methods->getValidationRules(['only' => array_keys($method)]) as $field => $fieldRules) {
            $rules[$field] = ['label' => lang('BusinessPaymentMethod.fields.' . $field), 'rules' => $fieldRules];
        }
        $validation = single_service('validation')->setRules($rules);
        if (! $validation->run($method)) {
            $errors = $validation->getErrors() + $errors;
        }
        $errors += BusinessPaymentMethodModel::ruleErrors($method + ['settings' => $settings]);

        try {
            if (array_key_exists('store_public_id', $in)) {
                $method['store_id'] = null;
                if ($in['store_public_id'] !== null && $in['store_public_id'] !== '') {
                    $method['store_id'] = $this->storeIdOf((int) $business['id'], $in['store_public_id']);
                    if ($method['store_id'] === null) {
                        $errors['store_public_id'] = lang('PaymentMethod.storeNotFound');
                    }
                }
            } else {
                $method['store_id'] = $stored === null || $stored['store_id'] === null ? null : (int) $stored['store_id'];
            }
            if ($errors !== []) {
                return ApiResponse::error(lang('PaymentMethod.invalidFields'), 422, $errors);
            }

            $method['sort_order']    = (int) $method['sort_order'];
            $method['settings_json'] = $settings === [] ? null : json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $actorId                 = $this->actorId();
            if ($stored === null) {
                $publicId = $methods->createWithNames($method, $actorId, $names ?? []);
                $saved    = $publicId !== null;
            } else {
                $saved    = $methods->updateWithNames($stored, $method, $actorId, $names);
                $publicId = $stored['public_id'];
            }
        } catch (Throwable $e) {
            log_message('error', $endpoint . ': {message}', ['message' => $e->getMessage()]);

            return ApiResponse::error(lang('PaymentMethod.saveFailed'), 500);
        }
        if (! $saved) {
            return ApiResponse::error(lang('PaymentMethod.invalidFields'), 422, ['method_name' => lang('PaymentMethod.nameTaken')]);
        }

        return ApiResponse::success(['public_id' => $publicId], 200, lang($edit ? 'PaymentMethod.updated' : 'PaymentMethod.created'));
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
