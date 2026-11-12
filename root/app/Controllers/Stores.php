<?php

namespace App\Controllers;

use App\Libraries\ApiResponse;
use App\Models\BusinessMasterModel;
use App\Models\BusinessStoreModel;
use App\Models\SubscriptionMasterModel;
use CodeIgniter\HTTP\Exceptions\HTTPException;
use CodeIgniter\HTTP\ResponseInterface;
use Config\PlanLimits;
use Throwable;

/**
 * The stores of a business (business_store): the Client app's, behind the jwt:C filter (clients only), and only for
 * the active owners and managers of the business (business_client_link.client_role O or M).
 * A business comes in by its business_slug, a store by its public_id; the ids stay inside.
 */
class Stores extends BaseController
{
    use ManagedBusinessTrait;
    use TranslationInputTrait;

    /** The business_store columns a client fills in when creating or editing a store */
    private const FORM_FIELDS = [
        'store_name',
        'store_type',
        'store_timezone',
        'store_phone_number',
        'store_address_line_1',
        'store_address_line_2',
        'store_city',
        'store_subdivision_code',
        'store_postal_code',
    ];

    /** Fields saved as NULL when left empty; an empty store_timezone becomes the country's default instead */
    private const FORM_NULLABLE = ['store_timezone', 'store_phone_number', ...BusinessStoreModel::ADDRESS_FIELDS];

    /** Fields saved in capitals, so "th-10" is accepted as TH-10 */
    private const UPPERCASE_FIELDS = ['store_type', 'store_subdivision_code'];

    /** The columns of business_store_translation and their longest lengths, the name (required) first */
    private const TRANSLATION_FIELDS = [
        'store_name'           => 255,
        'store_address_line_1' => 255,
        'store_address_line_2' => 255,
        'store_city'           => 128,
    ];

    /**
     * POST /client/stores { business_slug }
     * The stores of that business, by name: for each, public_id, store_name, store_type, store_timezone, store_phone_number, the address columns (NULL
     * for an online store) and its names and addresses in other languages as translations (language_code =>
     * {store_name, store_address_line_1, store_address_line_2, store_city}); with the business's plan_code, max_stores
     * (its cap, Config\PlanLimits::STORES) and can_add (false once it has max_stores stores, so create would be refused)
     * @return ResponseInterface
     */
    public function list(): ResponseInterface
    {
        $endpoint = 'v1/client/stores';
        $input    = $this->jsonInput();
        if ($input === null) {
            return ApiResponse::error(lang('Store.invalidRequest'), 422);
        }
        [$business, $error] = $this->managedBusinessBySlug($input, $endpoint, 'Store');
        if ($error !== null) {
            return $error;
        }
        try {
            $rows      = model(BusinessStoreModel::class)->listOfBusiness((int) $business['id']);
            $plan      = model(SubscriptionMasterModel::class)->planOfBusiness((int) $business['id'])['plan_code'];
            $maxStores = config(PlanLimits::class)->limitOf($plan, PlanLimits::STORES);
        } catch (Throwable $e) {
            log_message('error', $endpoint . ': {message}', ['message' => $e->getMessage()]);

            return ApiResponse::error(lang('Store.loadFailed'), 500);
        }

        return ApiResponse::success([
            'stores'     => $rows,
            'plan_code'  => $plan,
            'max_stores' => $maxStores,
            'can_add'    => count($rows) < $maxStores,
        ]);
    }

    /**
     * POST /client/store/create { business_slug, store: {...}, translations: {language_code: {store_name,
     * store_address_line_1, store_address_line_2, store_city}} }
     * Creates a store of that business, with its names and addresses in other languages (business_store_translation).
     * store.store_type is P (physical) or O (online); an online store's address is saved as NULL.
     * store.store_timezone is one of listSupportedTimezones() of the business's country; left out, the country's first.
     * "translations" is optional; each key must be a language of listLanguages(). A language whose texts are all empty
     * is left out; one with an address needs its store_name too. An online store's translated address is saved as NULL.
     * Refused with 409 once the business has as many stores as its plan allows (Config\PlanLimits::STORES).
     * @return ResponseInterface
     */
    public function create(): ResponseInterface
    {
        return $this->saveStore(false);
    }

    /**
     * POST /client/store/edit { public_id, store: {...}, translations: {language_code: {store_name, ...}} }
     * Updates the store with that public_id. A field left out of "store" keeps its value; changing it to an online
     * store clears its address, the translated one too. "translations", when given, replaces them all: a language left
     * out or empty loses its translation; left out altogether, the translations stay as they are.
     * @return ResponseInterface
     */
    public function edit(): ResponseInterface
    {
        return $this->saveStore(true);
    }

    /**
     * create() and edit()
     * @param bool $edit
     * @return ResponseInterface
     */
    private function saveStore(bool $edit): ResponseInterface
    {
        $endpoint = $edit ? 'v1/client/store/edit' : 'v1/client/store/create';
        $input    = $this->jsonInput();
        if ($input === null) {
            return ApiResponse::error(lang('Store.invalidRequest'), 422);
        }
        $storeIn = $input['store'] ?? [];
        // left out of an edit, the names stay as they are
        $translationsIn = $input['translations'] ?? ($edit ? null : []);
        if (! is_array($storeIn)) {
            return ApiResponse::error(lang('Store.invalidRequest'), 422);
        }

        $stores = model(BusinessStoreModel::class);
        $stored = null;
        if ($edit) {
            $publicId = $input['public_id'] ?? null;
            if (! is_string($publicId) || $publicId === '') {
                return ApiResponse::error(lang('Store.storeIdRequired'), 400);
            }
            try {
                $stored   = $stores->findByPublicId($publicId);
                $business = $stored === null ? null : model(BusinessMasterModel::class)->select('id, country_code')->find((int) $stored['business_id']);
            } catch (Throwable $e) {
                log_message('error', $endpoint . ': {message}', ['message' => $e->getMessage()]);

                return ApiResponse::error(lang('Store.loadFailed'), 500);
            }
            $error = $business === null ? ApiResponse::error(lang('Store.notFound'), 404) : $this->roleError((int) $business['id'], $endpoint, lang('Store.notFound'), 'Store');
        } else {
            [$business, $error] = $this->managedBusinessBySlug($input, $endpoint, 'Store');
        }
        if ($error !== null) {
            return $error;
        }

        $store = ['business_id' => (int) $business['id']];
        foreach (self::FORM_FIELDS as $field) {
            $store[$field] = $stored !== null && ! array_key_exists($field, $storeIn)
                ? $stored[$field]
                : $this->normalize($field, $storeIn[$field] ?? null);
        }
        $store['store_timezone'] ??= BusinessStoreModel::defaultTimezone($business['country_code']);
        $store  = BusinessStoreModel::withoutOnlineAddress($store);
        $errors = [];
        $names  = null;
        if ($translationsIn !== null) {
            [$names, $errors] = $this->translationsIn($translationsIn, self::TRANSLATION_FIELDS);
            if ($names === null) {
                return ApiResponse::error(lang('Store.invalidRequest'), 422);
            }
        }

        $rules = [];
        foreach ($stores->getValidationRules(['only' => array_keys($store)]) as $field => $fieldRules) {
            $rules[$field] = ['label' => $field === 'business_id' ? 'Business' : lang('BusinessStore.fields.' . $field), 'rules' => $fieldRules];
        }
        $validation = single_service('validation')->setRules($rules);
        if (! $validation->run($store)) {
            $errors = $validation->getErrors() + $errors;
        }
        if ($errors !== []) {
            return ApiResponse::error(lang('Store.invalidFields'), 422, $errors);
        }

        try {
            $errors = BusinessStoreModel::localeErrors($store, $business['country_code']);
            if ($stores->nameTaken((int) $business['id'], $store['store_name'], $stored === null ? null : (int) $stored['id'])) {
                $errors['store_name'] = lang('Store.nameTaken');
            }
            if ($errors !== []) {
                return ApiResponse::error(lang('Store.invalidFields'), 422, $errors);
            }

            if ($stored === null) {
                // the plan's cap; a business over it after moving to a smaller plan keeps its stores but adds none
                $plan      = model(SubscriptionMasterModel::class)->planOfBusiness((int) $business['id'])['plan_code'];
                $maxStores = config(PlanLimits::class)->limitOf($plan, PlanLimits::STORES);
                $publicId  = $stores->createWithNames($store, $this->actorId(), $names ?? [], $maxStores);
                if ($publicId === null) {
                    return ApiResponse::error(lang('Store.limitReached', [$maxStores, ucfirst($plan)]), 409);
                }
            } else {
                $stores->updateWithNames($stored, $store, $this->actorId(), $names);
                $publicId = $stored['public_id'];
            }
        } catch (Throwable $e) {
            log_message('error', $endpoint . ': {message}', ['message' => $e->getMessage()]);

            return ApiResponse::error(lang('Store.saveFailed'), 500);
        }

        return ApiResponse::success(['public_id' => $publicId], 200, lang($edit ? 'Store.updated' : 'Store.created'));
    }

    /**
     * A value from the body as the validation and the database take it: a trimmed string, or null
     * @param string $field
     * @param mixed  $value
     * @return string|null
     */
    private function normalize(string $field, mixed $value): ?string
    {
        // a number counts too: the phone number or the postal code may arrive as a JSON number
        $value = is_string($value) || is_int($value) ? trim((string) $value) : '';
        if (in_array($field, self::UPPERCASE_FIELDS, true)) {
            $value = strtoupper($value);
        }

        return $value === '' && in_array($field, self::FORM_NULLABLE, true) ? null : $value;
    }

    /**
     * auth_master.id of the signed-in account, for log_activity.changed_by_acc
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
