<?php

namespace App\Controllers;

use App\Libraries\ApiResponse;
use App\Models\BusinessClientLinkModel;
use App\Models\BusinessMasterModel;
use App\Models\BusinessStoreModel;
use App\Models\ClientMasterModel;
use CodeIgniter\HTTP\Exceptions\HTTPException;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * The stores of a business (business_store): the Client app's, behind the jwt:C filter (clients only), and only for
 * the active owners and managers of the business (business_client_link.client_role O or M).
 * A business comes in by its business_slug, a store by its public_id; the ids stay inside.
 */
class Stores extends BaseController
{
    /** The business_store columns a client fills in when creating or editing a store */
    private const FORM_FIELDS = [
        'store_name',
        'store_type',
        'store_phone_number',
        'store_address_line_1',
        'store_address_line_2',
        'store_city',
        'store_subdivision_code',
        'store_postal_code',
    ];

    /** Fields saved as NULL when left empty */
    private const FORM_NULLABLE = ['store_phone_number', ...BusinessStoreModel::ADDRESS_FIELDS];

    /** Fields saved in capitals, so "th-10" is accepted as TH-10 */
    private const UPPERCASE_FIELDS = ['store_type', 'store_subdivision_code'];

    /** business_store_translation.store_name */
    private const TRANSLATION_MAX_LENGTH = 255;

    /** The roles that may manage the stores of a business */
    private const MANAGING_ROLES = [BusinessClientLinkModel::ROLE_OWNER, BusinessClientLinkModel::ROLE_MANAGER];

    /**
     * POST /client/stores { business_slug }
     * The stores of that business, by name: for each, public_id, store_name, store_type, store_phone_number, the address columns (NULL
     * for an online store) and its names in other languages as translations
     * @return ResponseInterface
     */
    public function list(): ResponseInterface
    {
        $endpoint = 'v1/client/stores';
        $input    = $this->jsonInput();
        if ($input === null) {
            return ApiResponse::error(lang('Store.invalidRequest'), 422);
        }
        [$business, $error] = $this->managedBusinessBySlug($input, $endpoint);
        if ($error !== null) {
            return $error;
        }
        try {
            $rows = model(BusinessStoreModel::class)->listOfBusiness((int) $business['id']);
        } catch (Throwable $e) {
            log_message('error', $endpoint . ': {message}', ['message' => $e->getMessage()]);

            return ApiResponse::error(lang('Store.loadFailed'), 500);
        }

        return ApiResponse::success(['stores' => $rows]);
    }

    /**
     * POST /client/store/create { business_slug, store: {...}, translations: {language_code: store_name} }
     * Creates a store of that business, with its names in other languages (business_store_translation).
     * store.store_type is P (physical) or O (online); an online store's address is saved as NULL.
     * "translations" is optional; each key must be a language of listLanguages(), and an empty name is left out.
     * @return ResponseInterface
     */
    public function create(): ResponseInterface
    {
        return $this->saveStore(false);
    }

    /**
     * POST /client/store/edit { public_id, store: {...}, translations: {language_code: store_name} }
     * Updates the store with that public_id. A field left out of "store" keeps its value; changing it to an online
     * store clears its address. "translations", when given, replaces the names: a language left out or empty loses
     * its name; left out altogether, the names stay as they are.
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
        if (! is_array($storeIn) || ($translationsIn !== null && ! $this->isObject($translationsIn))) {
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
            $error = $business === null ? ApiResponse::error(lang('Store.notFound'), 404) : $this->roleError((int) $business['id'], $endpoint, lang('Store.notFound'));
        } else {
            [$business, $error] = $this->managedBusinessBySlug($input, $endpoint);
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
        $store  = BusinessStoreModel::withoutOnlineAddress($store);
        $errors = [];
        $names  = null;
        if ($translationsIn !== null) {
            helper('list');
            [$names, $errors] = $this->translations($translationsIn, listLanguages());
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
                $publicId = $stores->createWithNames($store, $this->actorId(), $names);
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
     * The business of the request's business_slug, when the signed-in client is one of its active owners or managers
     * @param array  $input
     * @param string $endpoint for the error log
     * @return array{0: array|null, 1: ResponseInterface|null} [business (id, country_code), null] or [null, error response]
     */
    private function managedBusinessBySlug(array $input, string $endpoint): array
    {
        $slug = is_string($input['business_slug'] ?? null) ? trim($input['business_slug']) : '';
        if ($slug === '') {
            return [null, ApiResponse::error(lang('Store.slugRequired'), 400)];
        }
        try {
            $business = model(BusinessMasterModel::class)->select('id, country_code')->where('business_slug', $slug)->first();
        } catch (Throwable $e) {
            log_message('error', $endpoint . ': {message}', ['message' => $e->getMessage()]);

            return [null, ApiResponse::error(lang('Store.loadFailed'), 500)];
        }
        $error = $business === null
            ? ApiResponse::error(lang('Store.businessNotFound'), 404)
            : $this->roleError((int) $business['id'], $endpoint, lang('Store.businessNotFound'));

        return $error === null ? [$business, null] : [null, $error];
    }

    /**
     * Null when the signed-in client is an active owner or manager of the business, else the error response:
     * 404 with $notFound when they are not active in it (it is not theirs to see either), 403 for an employee
     * @param int    $businessId
     * @param string $endpoint for the error log
     * @param string $notFound
     * @return ResponseInterface|null
     */
    private function roleError(int $businessId, string $endpoint, string $notFound): ?ResponseInterface
    {
        try {
            $client = model(ClientMasterModel::class)->findByAuthId($this->actorId());
            $role   = $client === null ? null : model(BusinessClientLinkModel::class)->activeRole($businessId, (int) $client['id']);
        } catch (Throwable $e) {
            log_message('error', $endpoint . ': {message}', ['message' => $e->getMessage()]);

            return ApiResponse::error(lang('Store.loadFailed'), 500);
        }
        if ($role === null) {
            return ApiResponse::error($notFound, 404);
        }

        return in_array($role, self::MANAGING_ROLES, true) ? null : ApiResponse::error(lang('Store.managersOnly'), 403);
    }

    /**
     * The names of "translations", keyed by language: only the languages of $languages are accepted
     * @param array $translations
     * @param array $languages    language_code => language name, from list_helper
     * @return array{array<string, string>, array<string, string>} [language_code => name, field => error]
     */
    private function translations(array $translations, array $languages): array
    {
        $names  = [];
        $errors = [];
        foreach ($translations as $language => $name) {
            $field = 'translations.' . $language;
            if (! isset($languages[$language])) {
                $errors[$field] = lang('Store.unknownLanguage');

                continue;
            }
            if ($name !== null && ! is_string($name)) {
                $errors[$field] = lang('Store.invalidTranslation');

                continue;
            }
            $name = trim((string) $name);
            if (mb_strlen($name) > self::TRANSLATION_MAX_LENGTH) {
                $errors[$field] = lang('Store.translationTooLong', [self::TRANSLATION_MAX_LENGTH]);
            } elseif ($name !== '') {
                $names[$language] = $name;
            }
        }

        return [$names, $errors];
    }

    /**
     * Whether a value from the body is a JSON object ({} included), as "translations" must be
     * @param mixed $value
     * @return bool
     */
    private function isObject(mixed $value): bool
    {
        return is_array($value) && ($value === [] || ! array_is_list($value));
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
