<?php

namespace App\Controllers;

use App\Libraries\ApiResponse;
use App\Models\BusinessClientLinkModel;
use App\Models\BusinessMasterModel;
use App\Models\BusinessTypeModel;
use App\Models\ClientMasterModel;
use CodeIgniter\HTTP\Exceptions\HTTPException;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * Businesses and their types: create(), edit() and listActiveTypes() are the Client app's, behind the jwt:C filter (clients only);
 * listTypes(), createType() and editType() are the Admin app's, behind jwt:S (staff only).
 * A business goes out and comes in by its business_slug, a business type by its public_id; the ids stay inside.
 */
class Businesses extends BaseController
{
    /** The business_master columns a client fills in when creating or editing a business */
    private const FORM_FIELDS = [
        'business_type_id',
        'business_name',
        'business_slug',
        'business_description',
        'business_mood_and_tone',
        'contact_person_name',
        'contact_email_address',
        'contact_phone_number',
        'contact_website',
        'contact_address_line_1',
        'contact_address_line_2',
        'contact_city',
        'contact_subdivision_code',
        'contact_postal_code',
        'country_code',
        'currency_code',
        'tax_id',
    ];

    /** Fields saved as NULL when left empty */
    private const FORM_NULLABLE = [
        'business_description',
        'business_mood_and_tone',
        'contact_person_name',
        'contact_phone_number',
        'contact_website',
        'contact_address_line_1',
        'contact_address_line_2',
        'contact_city',
        'contact_subdivision_code',
        'contact_postal_code',
        'tax_id',
    ];

    /** Fields saved in capitals, so "sg" is accepted as SG */
    private const UPPERCASE_FIELDS = ['country_code', 'currency_code', 'contact_subdivision_code', 'type_status'];

    /** business_master_translation.business_name and business_type_translation.type_name */
    private const TRANSLATION_MAX_LENGTH = 255;

    /**
     * POST /client/business/create { business: {...}, translations: {language_code: business_name} }
     * Creates a business with the signed-in client as its owner (business_client_link), and its names in other
     * languages (business_master_translation). The business type is business.business_type_public_id.
     * "translations" is optional; each key must be a language of listLanguages(), and an empty name is left out.
     * @return ResponseInterface
     */
    public function create(): ResponseInterface
    {
        return $this->saveBusiness(false);
    }

    /**
     * POST /client/business/edit { business_slug, business: {...}, translations: {language_code: business_name} }
     * Updates the business with that business_slug; only its active owners may. A field left out of "business"
     * keeps its value, and business.business_slug renames the business. country_code and currency_code are fixed
     * once created. "translations", when given, replaces the names: a language left out or empty loses its name;
     * left out altogether, the names stay as they are.
     * @return ResponseInterface
     */
    public function edit(): ResponseInterface
    {
        return $this->saveBusiness(true);
    }

    /**
     * POST /staff/business-types {}
     * Every business type, active or not, by type_code, with its names in every language as translations
     * @return ResponseInterface
     */
    public function listTypes(): ResponseInterface
    {
        if ($this->jsonInput() === null) {
            return ApiResponse::error(lang('Business.invalidRequest'), 422);
        }

        try {
            $rows = model(BusinessTypeModel::class)->listWithNames();
        } catch (Throwable $e) {
            log_message('error', 'v1/staff/business-types: {message}', ['message' => $e->getMessage()]);

            return ApiResponse::error(lang('Business.loadFailed'), 500);
        }

        return ApiResponse::success(['rows' => $rows]);
    }

    /**
     * POST /client/business-types {}
     * The active business types, for the Business Type dropdown of a new business: by type_code, with their names in
     * every language as translations
     * @return ResponseInterface
     */
    public function listActiveTypes(): ResponseInterface
    {
        if ($this->jsonInput() === null) {
            return ApiResponse::error(lang('Business.invalidRequest'), 422);
        }

        try {
            $rows = model(BusinessTypeModel::class)->listWithNames();
        } catch (Throwable $e) {
            log_message('error', 'v1/client/business-types: {message}', ['message' => $e->getMessage()]);

            return ApiResponse::error(lang('Business.loadFailed'), 500);
        }
        $rows = array_values(array_filter($rows, static fn (array $row) => $row['type_status'] === BusinessTypeModel::STATUS_ACTIVE));

        return ApiResponse::success(['rows' => $rows]);
    }

    /**
     * POST /staff/business-type/create { type_code, type_status, translations: {language_code: type_name} }
     * Creates a business type with its name in every language of listSupportedLanguages(), all required;
     * any other language is refused. type_status defaults to active.
     * @return ResponseInterface
     */
    public function createType(): ResponseInterface
    {
        return $this->saveType(false);
    }

    /**
     * POST /staff/business-type/edit { public_id, type_status, translations: {language_code: type_name} }
     * Updates the business type with that public_id: its status (kept when left out) and its names, again one for every
     * language of listSupportedLanguages(). type_code is fixed once created.
     * @return ResponseInterface
     */
    public function editType(): ResponseInterface
    {
        return $this->saveType(true);
    }

    /**
     * create() and edit()
     * @param bool $edit
     * @return ResponseInterface
     */
    private function saveBusiness(bool $edit): ResponseInterface
    {
        $endpoint = $edit ? 'v1/client/business/edit' : 'v1/client/business/create';
        $input    = $this->jsonInput();
        if ($input === null) {
            return ApiResponse::error(lang('Business.invalidRequest'), 422);
        }
        $businessIn = $input['business'] ?? [];
        // left out of an edit, the names stay as they are
        $translationsIn = $input['translations'] ?? ($edit ? null : []);
        if (! is_array($businessIn) || ($translationsIn !== null && ! $this->isObject($translationsIn))) {
            return ApiResponse::error(lang('Business.invalidRequest'), 422);
        }
        $slug = is_string($input['business_slug'] ?? null) ? trim($input['business_slug']) : '';
        if ($edit && $slug === '') {
            return ApiResponse::error(lang('Business.slugRequired'), 400);
        }

        $businesses = model(BusinessMasterModel::class);
        try {
            // the jwt filter signs in only accounts with a profile row; this covers it being deleted meanwhile
            $client = model(ClientMasterModel::class)->findByAuthId($this->actorId());
            if ($client === null) {
                return ApiResponse::error(lang('Business.profileRequired'), 409);
            }
            $stored = null;
            if ($edit) {
                $stored = $businesses->findBySlugDecrypted($slug);
                $role   = $stored === null ? null : model(BusinessClientLinkModel::class)->activeRole((int) $stored['id'], (int) $client['id']);
                // a business the client is not active in is not theirs to see either
                if ($role === null) {
                    return ApiResponse::error(lang('Business.notFound'), 404);
                }
                if ($role !== BusinessClientLinkModel::ROLE_OWNER) {
                    return ApiResponse::error(lang('Business.ownerOnly'), 403);
                }
            }
        } catch (Throwable $e) {
            log_message('error', $endpoint . ': {message}', ['message' => $e->getMessage()]);

            return ApiResponse::error(lang('Business.loadFailed'), 500);
        }

        // the type is only ever set from its public_id; left out of an edit, it stays
        unset($businessIn['business_type_id']);
        $errors = [];
        if ($stored === null || array_key_exists('business_type_public_id', $businessIn)) {
            $typePublicId = $businessIn['business_type_public_id'] ?? null;
            try {
                $typeId = $typePublicId === null || $typePublicId === '' ? null : model(BusinessTypeModel::class)->idOfPublicId($typePublicId);
            } catch (Throwable $e) {
                log_message('error', $endpoint . ': {message}', ['message' => $e->getMessage()]);

                return ApiResponse::error(lang('Business.loadFailed'), 500);
            }
            if ($typeId === null && $typePublicId !== null && $typePublicId !== '') {
                $errors['business_type_public_id'] = lang('Business.typeNotFound');
            }
            $businessIn['business_type_id'] = $typeId; // none: the rules say it is required
        }

        $business = [];
        foreach (self::FORM_FIELDS as $field) {
            $business[$field] = $stored !== null && ! array_key_exists($field, $businessIn)
                ? $stored[$field]
                : $this->normalize($field, $businessIn[$field] ?? null);
        }
        foreach ($stored === null ? [] : BusinessMasterModel::FIXED_FIELDS as $field) {
            if ((string) $business[$field] !== (string) $stored[$field]) {
                $errors[$field] = lang('Business.fixedField');
            }
        }
        $names = null;
        if ($translationsIn !== null) {
            helper('list');
            [$names, $nameErrors] = $this->translations($translationsIn, listLanguages(), false);
            $errors += $nameErrors;
        }

        $rules = [];
        foreach ($businesses->getValidationRules(['only' => ['id', ...self::FORM_FIELDS]]) as $field => $fieldRules) {
            $rules[$field] = ['label' => $field === 'id' ? 'ID' : lang('BusinessMaster.fields.' . $field), 'rules' => $fieldRules];
        }
        // the business's own id, for is_unique[...,id,{id}]
        $validation = single_service('validation')->setRules($rules);
        if (! $validation->run($business + ['id' => $stored === null ? '' : (string) $stored['id']])) {
            $ruleErrors = $validation->getErrors();
            if (isset($ruleErrors['business_type_id'])) {
                $ruleErrors['business_type_public_id'] = $ruleErrors['business_type_id'];
                unset($ruleErrors['business_type_id']);
            }
            $errors += $ruleErrors;
        }
        if ($errors !== []) {
            return ApiResponse::error(lang('Business.invalidFields'), 422, $errors);
        }

        try {
            $errors = BusinessMasterModel::localeErrors($business);
            if ($businesses->nameTaken($business['business_name'], $business['country_code'], $stored === null ? null : (int) $stored['id'])) {
                $errors['business_name'] = lang('Business.nameTaken');
            }
            if ($errors !== []) {
                return ApiResponse::error(lang('Business.invalidFields'), 422, $errors);
            }

            if ($stored === null) {
                $businesses->createWithOwner($business, (int) $client['id'], $this->actorId(), $names);
            } else {
                $businesses->updateBusiness($stored, $business, $this->actorId(), $names);
            }
        } catch (Throwable $e) {
            log_message('error', $endpoint . ': {message}', ['message' => $e->getMessage()]);

            return ApiResponse::error(lang('Business.saveFailed'), 500);
        }

        // the internal id never goes out; the slug is the business's public key
        return ApiResponse::success(['business_slug' => $business['business_slug']], 200, lang($edit ? 'Business.updated' : 'Business.created'));
    }

    /**
     * createType() and editType()
     * @param bool $edit
     * @return ResponseInterface
     */
    private function saveType(bool $edit): ResponseInterface
    {
        $endpoint = $edit ? 'v1/staff/business-type/edit' : 'v1/staff/business-type/create';
        $input    = $this->jsonInput();
        if ($input === null) {
            return ApiResponse::error(lang('Business.invalidRequest'), 422);
        }
        $translationsIn = $input['translations'] ?? [];
        if (! $this->isObject($translationsIn)) {
            return ApiResponse::error(lang('Business.invalidRequest'), 422);
        }
        $publicId = $input['public_id'] ?? null;
        if ($edit && (! is_string($publicId) || $publicId === '')) {
            return ApiResponse::error(lang('Business.typeIdRequired'), 400);
        }

        $types  = model(BusinessTypeModel::class);
        $stored = null;
        if ($edit) {
            try {
                $stored = $types->findByPublicId($publicId);
            } catch (Throwable $e) {
                log_message('error', $endpoint . ': {message}', ['message' => $e->getMessage()]);

                return ApiResponse::error(lang('Business.loadFailed'), 500);
            }
            if ($stored === null) {
                return ApiResponse::error(lang('Business.typeNotFound'), 404);
            }
        }

        $type = [];
        foreach (['type_code', 'type_status'] as $field) {
            $type[$field] = $stored !== null && ! array_key_exists($field, $input)
                ? $stored[$field]
                : $this->normalize($field, $input[$field] ?? null);
        }
        $type['type_status'] = $type['type_status'] ?: BusinessTypeModel::STATUS_ACTIVE;
        helper('list');
        [$names, $errors] = $this->translations($translationsIn, listSupportedLanguages(), true);
        if ($stored !== null && $type['type_code'] !== $stored['type_code']) {
            $errors['type_code'] = lang('Business.fixedField');
        }

        $rules = [];
        foreach ($types->getValidationRules(['only' => ['id', ...array_keys($type)]]) as $field => $fieldRules) {
            $rules[$field] = ['label' => $field === 'id' ? 'ID' : lang('BusinessType.fields.' . $field), 'rules' => $fieldRules];
        }
        // the type's own id, for is_unique[...,id,{id}]
        $validation = single_service('validation')->setRules($rules);
        if (! $validation->run($type + ['id' => $stored === null ? '' : (string) $stored['id']])) {
            $errors = $validation->getErrors() + $errors;
        }
        if ($errors !== []) {
            return ApiResponse::error(lang('Business.invalidFields'), 422, $errors);
        }

        try {
            foreach ($names as $language => $name) {
                if ($types->nameTaken($language, $name, $stored === null ? null : (int) $stored['id'])) {
                    $errors['translations.' . $language] = lang('Business.typeNameTaken');
                }
            }
            if ($errors !== []) {
                return ApiResponse::error(lang('Business.invalidFields'), 422, $errors);
            }

            if ($stored === null) {
                $publicId = $types->publicIdOf($types->createWithNames($type, $names, $this->actorId()));
            } else {
                $types->updateWithNames($stored, $type, $names, $this->actorId());
            }
        } catch (Throwable $e) {
            log_message('error', $endpoint . ': {message}', ['message' => $e->getMessage()]);

            return ApiResponse::error(lang('Business.typeSaveFailed'), 500);
        }

        // what /client/business/create and edit take as business.business_type_public_id
        return ApiResponse::success(['public_id' => $publicId, 'type_code' => $type['type_code']], 200, lang($edit ? 'Business.typeUpdated' : 'Business.typeCreated'));
    }

    /**
     * The names of "translations", keyed by language: only the languages of $languages are accepted
     * @param array $translations
     * @param array $languages    language_code => language name, from list_helper
     * @param bool  $required     Every language of $languages needs a name
     * @return array{array<string, string>, array<string, string>} [language_code => name, field => error]
     */
    private function translations(array $translations, array $languages, bool $required): array
    {
        $names  = [];
        $errors = [];
        foreach ($translations as $language => $name) {
            $field = 'translations.' . $language;
            if (! isset($languages[$language])) {
                $errors[$field] = lang('Business.unknownLanguage');

                continue;
            }
            if ($name !== null && ! is_string($name)) {
                $errors[$field] = lang('Business.invalidTranslation');

                continue;
            }
            $name = trim((string) $name);
            if (mb_strlen($name) > self::TRANSLATION_MAX_LENGTH) {
                $errors[$field] = lang('Business.translationTooLong', [self::TRANSLATION_MAX_LENGTH]);
            } elseif ($name !== '') {
                $names[$language] = $name;
            }
        }
        if ($required) {
            foreach (array_keys($languages) as $language) {
                if (! isset($names[$language]) && ! isset($errors['translations.' . $language])) {
                    $errors['translations.' . $language] = lang('Business.translationRequired');
                }
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
        // a number counts too: the phone number may arrive as a JSON number, and business_type_id is the id found here
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
