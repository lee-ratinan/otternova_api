<?php

namespace App\Controllers;

use App\Libraries\ApiResponse;
use App\Models\BusinessClientAttributeModel;
use App\Models\BusinessClientLanguageModel;
use App\Models\BusinessClientLinkModel;
use App\Models\BusinessClientRestrictionModel;
use CodeIgniter\HTTP\Exceptions\HTTPException;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * What a business records about its people besides where they work and what they are paid: the attributes it defines
 * (business_client_attribute: "Certification", "Specialized in"), each person's values of them in free text
 * (business_client_attribute_value: "JLPT N1"), the languages they speak (business_client_language) and the
 * customers they do not serve (business_client_restriction).
 * The Client app's, behind the jwt:C filter, and only for the active owners and managers of the business. A business
 * comes in by its business_slug, a person by their auth_master.uid, an attribute or a value by its public_id; the ids
 * stay inside.
 */
class StaffProfiles extends BaseController
{
    use ManagedBusinessTrait;
    use TranslationInputTrait;

    /** The most languages, and values, one person may have */
    private const MAX_LANGUAGES = 50;
    private const MAX_VALUES    = 200;

    /** The oldest age a restriction takes, as TINYINT holds it */
    private const MAX_AGE = 120;

    /**
     * POST /client/staff-attributes { business_slug }
     * The attributes of that business in their order: public_id, attribute_name, is_public, sort_order, translations
     * and value_count (how many values its people have of it)
     * @return ResponseInterface
     */
    public function attributes(): ResponseInterface
    {
        $endpoint = 'v1/client/staff-attributes';
        $input    = $this->jsonInput();
        if ($input === null) {
            return ApiResponse::error(lang('StaffProfile.invalidRequest'), 422);
        }
        [$business, $error] = $this->managedBusinessBySlug($input, $endpoint, 'StaffProfile');
        if ($error !== null) {
            return $error;
        }
        try {
            $rows = model(BusinessClientAttributeModel::class)->listOfBusiness((int) $business['id']);
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'StaffProfile.loadFailed');
        }

        return ApiResponse::success(['attributes' => $rows]);
    }

    /**
     * POST /client/staff-attribute/create { business_slug, attribute: { attribute_name, is_public, sort_order },
     * translations: { language_code: name } }
     * is_public is true and sort_order 0 when left out; "translations" is optional.
     * @return ResponseInterface The attribute, as attributes() gives it
     */
    public function createAttribute(): ResponseInterface
    {
        return $this->saveAttribute(false);
    }

    /**
     * POST /client/staff-attribute/edit { public_id, attribute: {...}, translations: {...} }
     * A field left out keeps its value; "translations", when given, replace the names, and left out, they stay.
     * @return ResponseInterface The attribute, as attributes() gives it
     */
    public function editAttribute(): ResponseInterface
    {
        return $this->saveAttribute(true);
    }

    /**
     * POST /client/staff-attribute/delete { public_id }
     * Deletes the attribute with everyone's values of it
     * @return ResponseInterface { public_id, deleted_values }
     */
    public function deleteAttribute(): ResponseInterface
    {
        $endpoint = 'v1/client/staff-attribute/delete';
        [$stored, , $error] = $this->storedAttribute($endpoint);
        if ($error !== null) {
            return $error;
        }
        try {
            $count = model(BusinessClientAttributeModel::class)->deleteAttribute($stored, $this->actorId());
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'StaffProfile.saveFailed');
        }

        return ApiResponse::success(['public_id' => $stored['public_id'], 'deleted_values' => $count], 200, lang('StaffProfile.attributeDeleted'));
    }

    /**
     * POST /client/staff/profile { business_slug, uid }
     * "person" (uid, full_name), "languages" (the best spoken first: language_code, proficiency), "attributes" (every
     * attribute of the business in its order, as attributes() gives them but with the person's "values" in place of
     * value_count, each { public_id, attribute_value, sort_order, translations }), "restrictions" (excluded_genders,
     * age_min, age_max) and "restriction_notices", the restrictions as a customer is told them before booking, in the
     * request's language
     * @return ResponseInterface
     */
    public function profile(): ResponseInterface
    {
        $endpoint = 'v1/client/staff/profile';
        [$link, , $error] = $this->person($endpoint);
        if ($error !== null) {
            return $error;
        }
        $businessId = (int) $link['business_id'];
        $clientId   = (int) $link['client_id'];
        try {
            $languages    = model(BusinessClientLanguageModel::class)->languagesOf($businessId, $clientId);
            $attributes   = model(BusinessClientAttributeModel::class)->profileOf($businessId, $clientId);
            $restrictions = model(BusinessClientRestrictionModel::class)->restrictionsOf($businessId, $clientId);
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'StaffProfile.loadFailed');
        }

        return ApiResponse::success([
            'person'              => ['uid' => $link['uid'], 'full_name' => $link['full_name']],
            'languages'           => $languages,
            'attributes'          => $attributes,
            'restrictions'        => $restrictions,
            'restriction_notices' => self::notices($restrictions, (string) $link['full_name']),
        ]);
    }

    /**
     * POST /client/staff/languages/save { business_slug, uid, languages: [{ language_code, proficiency }] }
     * Makes the person speak exactly those languages, [] for none. language_code is BCP 47 (ja, nan-TW), any
     * language; proficiency N (native), F (fluent, when left out), C (conversational) or B (basic).
     * @return ResponseInterface { uid, languages } as profile() gives them
     */
    public function saveLanguages(): ResponseInterface
    {
        $endpoint = 'v1/client/staff/languages/save';
        [$link, $input, $error] = $this->person($endpoint);
        if ($error !== null) {
            return $error;
        }
        $in = $input['languages'] ?? null;
        if (! is_array($in) || ! array_is_list($in)) {
            return ApiResponse::error(lang('StaffProfile.invalidFields'), 422, ['languages' => lang('StaffProfile.invalidList')]);
        }
        if (count($in) > self::MAX_LANGUAGES) {
            return ApiResponse::error(lang('StaffProfile.invalidFields'), 422, ['languages' => lang('StaffProfile.tooMany', [self::MAX_LANGUAGES])]);
        }

        $languages = [];
        $errors    = [];
        foreach ($in as $i => $item) {
            $key = 'languages.' . $i;
            if (! is_array($item) || ($item !== [] && array_is_list($item))) {
                $errors[$key] = lang('StaffProfile.invalidLanguage');

                continue;
            }
            $code = BusinessClientLanguageModel::normalizeCode($item['language_code'] ?? null);
            if ($code === null) {
                $errors[$key . '.language_code'] = lang('StaffProfile.invalidLanguage');
            } elseif (isset($languages[$code])) {
                $errors[$key . '.language_code'] = lang('StaffProfile.duplicateLanguage');
            }
            $proficiency = $item['proficiency'] ?? 'F';
            $proficiency = is_string($proficiency) ? strtoupper(trim($proficiency)) : '';
            if (! in_array($proficiency, BusinessClientLanguageModel::PROFICIENCIES, true)) {
                $errors[$key . '.proficiency'] = lang('StaffProfile.invalidProficiency');
            }
            if ($code !== null) {
                $languages[$code] = $proficiency;
            }
        }
        if ($errors !== []) {
            return ApiResponse::error(lang('StaffProfile.invalidFields'), 422, $errors);
        }

        $model = model(BusinessClientLanguageModel::class);
        try {
            $model->replaceLanguages((int) $link['business_id'], (int) $link['client_id'], $languages, $this->actorId(), $link['uid']);
            $saved = $model->languagesOf((int) $link['business_id'], (int) $link['client_id']);
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'StaffProfile.saveFailed');
        }

        return ApiResponse::success(['uid' => $link['uid'], 'languages' => $saved], 200, lang('StaffProfile.languagesSaved'));
    }

    /**
     * POST /client/staff/attributes/save { business_slug, uid,
     * values: [{ public_id, attribute_public_id, attribute_value, sort_order, translations }] }
     * Makes these the person's values, [] for none: a value with a public_id updates that value of theirs (a field
     * left out keeps its value; its translations, when given, replace its names), one without is added and needs
     * attribute_public_id, and one of theirs left out is deleted. sort_order is its place among the values of its
     * attribute in the list when left out of a new value.
     * @return ResponseInterface { uid, attributes } as profile() gives them
     */
    public function saveAttributes(): ResponseInterface
    {
        $endpoint = 'v1/client/staff/attributes/save';
        [$link, $input, $error] = $this->person($endpoint);
        if ($error !== null) {
            return $error;
        }
        $in = $input['values'] ?? null;
        if (! is_array($in) || ! array_is_list($in)) {
            return ApiResponse::error(lang('StaffProfile.invalidFields'), 422, ['values' => lang('StaffProfile.invalidList')]);
        }
        if (count($in) > self::MAX_VALUES) {
            return ApiResponse::error(lang('StaffProfile.invalidFields'), 422, ['values' => lang('StaffProfile.tooMany', [self::MAX_VALUES])]);
        }
        $businessId = (int) $link['business_id'];
        $clientId   = (int) $link['client_id'];
        $model      = model(BusinessClientAttributeModel::class);
        try {
            $attributeIds = array_map('intval', array_column($model->rowsOfBusiness($businessId), 'id', 'public_id'));
            $storedValues = $model->valuesOf($businessId, $clientId);
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'StaffProfile.loadFailed');
        }

        [$values, $errors] = $this->values($in, $attributeIds, $storedValues);
        if ($errors !== []) {
            return ApiResponse::error(lang('StaffProfile.invalidFields'), 422, $errors);
        }
        try {
            $model->replaceValues($businessId, $clientId, $values, $this->actorId(), $link['uid']);
            $saved = $model->profileOf($businessId, $clientId);
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'StaffProfile.saveFailed');
        }

        return ApiResponse::success(['uid' => $link['uid'], 'attributes' => $saved], 200, lang('StaffProfile.attributesSaved'));
    }

    /**
     * POST /client/staff/restrictions/save { business_slug, uid, excluded_genders: ["M"], age_min, age_max }
     * Makes these the person's restrictions: the genders they do not serve (M, F, NB; [] for none) and the ages they
     * serve (either null for an open end, both for none). A field left out is cleared.
     * @return ResponseInterface { uid, restrictions, restriction_notices } as profile() gives them
     */
    public function saveRestrictions(): ResponseInterface
    {
        $endpoint = 'v1/client/staff/restrictions/save';
        [$link, $input, $error] = $this->person($endpoint);
        if ($error !== null) {
            return $error;
        }
        $errors  = [];
        $genders = $input['excluded_genders'] ?? [];
        if (! is_array($genders) || ! array_is_list($genders)) {
            $errors['excluded_genders'] = lang('StaffProfile.invalidList');
            $genders                    = [];
        }
        $genders = array_map(static fn ($gender) => is_string($gender) ? strtoupper(trim($gender)) : '', $genders);
        foreach ($genders as $i => $gender) {
            if (! in_array($gender, BusinessClientRestrictionModel::GENDERS, true)) {
                $errors['excluded_genders.' . $i] = lang('StaffProfile.invalidGender');
            }
        }
        $ages = [];
        foreach (['age_min', 'age_max'] as $field) {
            $value = $input[$field] ?? null;
            if ($value === null || $value === '') {
                $ages[$field] = null;

                continue;
            }
            $age = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => self::MAX_AGE]]);
            if ($age === false || is_bool($value)) {
                $errors[$field] = lang('StaffProfile.invalidAge', [self::MAX_AGE]);
            }
            $ages[$field] = $age === false ? null : $age;
        }
        if (! isset($errors['age_min']) && ! isset($errors['age_max']) && $ages['age_min'] !== null && $ages['age_max'] !== null && $ages['age_min'] > $ages['age_max']) {
            $errors['age_max'] = lang('StaffProfile.ageRange');
        }
        if ($errors !== []) {
            return ApiResponse::error(lang('StaffProfile.invalidFields'), 422, $errors);
        }

        $model = model(BusinessClientRestrictionModel::class);
        try {
            $model->replaceRestrictions((int) $link['business_id'], (int) $link['client_id'], array_values(array_unique($genders)), $ages['age_min'], $ages['age_max'], $this->actorId(), $link['uid']);
            $saved = $model->restrictionsOf((int) $link['business_id'], (int) $link['client_id']);
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'StaffProfile.saveFailed');
        }

        return ApiResponse::success([
            'uid'                 => $link['uid'],
            'restrictions'        => $saved,
            'restriction_notices' => self::notices($saved, (string) $link['full_name']),
        ], 200, lang('StaffProfile.restrictionsSaved'));
    }

    /**
     * The restrictions of a person as a customer is told them before booking, in the request's language
     * @param array{excluded_genders: list<string>, age_min: int|null, age_max: int|null} $restrictions
     * @param string $name The person's name
     * @return list<string>
     */
    private static function notices(array $restrictions, string $name): array
    {
        $notices = [];
        if ($restrictions['excluded_genders'] !== []) {
            $genders   = array_map(static fn (string $gender) => lang('StaffProfile.gender' . $gender), $restrictions['excluded_genders']);
            $notices[] = lang('StaffProfile.noticeGender', [$name, implode(lang('StaffProfile.listSeparator'), $genders)]);
        }
        [$min, $max] = [$restrictions['age_min'], $restrictions['age_max']];
        if ($min !== null && $max !== null) {
            $notices[] = lang('StaffProfile.noticeAgeRange', [$name, $min, $max]);
        } elseif ($min !== null) {
            $notices[] = lang('StaffProfile.noticeAgeMin', [$name, $min]);
        } elseif ($max !== null) {
            $notices[] = lang('StaffProfile.noticeAgeMax', [$name, $max]);
        }

        return $notices;
    }

    /**
     * createAttribute() and editAttribute()
     * @param bool $edit
     * @return ResponseInterface
     */
    private function saveAttribute(bool $edit): ResponseInterface
    {
        $endpoint = $edit ? 'v1/client/staff-attribute/edit' : 'v1/client/staff-attribute/create';
        if ($edit) {
            [$stored, $input, $error] = $this->storedAttribute($endpoint);
            $businessId               = $stored === null ? 0 : (int) $stored['business_id'];
        } else {
            $stored = null;
            $input  = $this->jsonInput();
            if ($input === null) {
                return ApiResponse::error(lang('StaffProfile.invalidRequest'), 422);
            }
            [$business, $error] = $this->managedBusinessBySlug($input, $endpoint, 'StaffProfile');
            $businessId         = $business === null ? 0 : (int) $business['id'];
        }
        if ($error !== null) {
            return $error;
        }
        $in = $input['attribute'] ?? [];
        if (! is_array($in) || ($in !== [] && array_is_list($in))) {
            return ApiResponse::error(lang('StaffProfile.invalidRequest'), 422);
        }

        $errors = [];
        $name   = array_key_exists('attribute_name', $in) ? $in['attribute_name'] : ($stored['attribute_name'] ?? '');
        $name   = is_string($name) ? trim($name) : '';
        if ($name === '') {
            $errors['attribute_name'] = lang('StaffProfile.nameRequired');
        } elseif (mb_strlen($name) > 64) {
            $errors['attribute_name'] = lang('StaffProfile.tooLong', [64]);
        }
        $public = array_key_exists('is_public', $in) ? $in['is_public'] : (int) ($stored['is_public'] ?? 1) === 1;
        $public = is_bool($public) ? $public : filter_var($public, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        if ($public === null) {
            $errors['is_public'] = lang('StaffProfile.invalidPublic');
        }
        $sort = array_key_exists('sort_order', $in) ? $in['sort_order'] : ($stored['sort_order'] ?? 0);
        $sort = is_bool($sort) ? false : filter_var($sort, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 65535]]);
        if ($sort === false) {
            $errors['sort_order'] = lang('StaffProfile.invalidSortOrder');
        }
        $names = null;
        if (array_key_exists('translations', $input) || ! $edit) {
            [$names, $translationErrors] = $this->translationsIn($input['translations'] ?? [], ['attribute_name' => 64]);
            $errors += $translationErrors;
        }
        if ($errors !== []) {
            return ApiResponse::error(lang('StaffProfile.invalidFields'), 422, $errors);
        }

        $model = model(BusinessClientAttributeModel::class);
        try {
            if ($model->nameTaken($businessId, $name, $stored === null ? null : (int) $stored['id'])) {
                return ApiResponse::error(lang('StaffProfile.invalidFields'), 422, ['attribute_name' => lang('StaffProfile.nameTaken')]);
            }
            $row      = ['business_id' => $businessId, 'attribute_name' => $name, 'is_public' => $public ? 1 : 0, 'sort_order' => (int) $sort];
            $publicId = $model->saveAttribute($stored, $row, $names, $this->actorId());
            $saved    = array_values(array_filter($model->listOfBusiness($businessId), static fn (array $row) => $row['public_id'] === $publicId))[0];
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'StaffProfile.saveFailed');
        }

        return ApiResponse::success(['attribute' => $saved], 200, lang($edit ? 'StaffProfile.attributeUpdated' : 'StaffProfile.attributeCreated'));
    }

    /**
     * The "values" of attributes/save, checked against the business's attributes and the person's stored values
     * @param list<mixed>          $in
     * @param array<string, int>   $attributeIds public_id => id of the business's attributes
     * @param array<string, array> $storedValues public_id => business_client_attribute_value row of the person
     * @return array{0: list<array>, 1: array<string, string>} [values as BusinessClientAttributeModel::replaceValues() takes them, errors]
     */
    private function values(array $in, array $attributeIds, array $storedValues): array
    {
        $values   = [];
        $errors   = [];
        $seen     = []; // public_id => true, for a value listed twice
        $texts    = []; // attribute_id => lowercase text => true, for the same text twice
        $position = []; // attribute_id => how many values of it so far, the sort_order of a new one
        foreach ($in as $i => $item) {
            $key = 'values.' . $i;
            if (! is_array($item) || ($item !== [] && array_is_list($item))) {
                $errors[$key] = lang('StaffProfile.invalidValue');

                continue;
            }
            $stored = null;
            if (($item['public_id'] ?? null) !== null && $item['public_id'] !== '') {
                $stored = is_string($item['public_id']) ? ($storedValues[$item['public_id']] ?? null) : null;
                if ($stored === null || isset($seen[$stored['public_id']])) {
                    $errors[$key . '.public_id'] = lang('StaffProfile.valueNotFound');

                    continue;
                }
                $seen[$stored['public_id']] = true;
            }

            $attributeId = $stored === null ? null : (int) $stored['attribute_id'];
            if (array_key_exists('attribute_public_id', $item) || $stored === null) {
                $given       = $item['attribute_public_id'] ?? null;
                $attributeId = is_string($given) ? ($attributeIds[$given] ?? null) : null;
                if ($attributeId === null) {
                    $errors[$key . '.attribute_public_id'] = lang('StaffProfile.unknownAttribute');

                    continue;
                }
            }

            $text = array_key_exists('attribute_value', $item) ? $item['attribute_value'] : ($stored['attribute_value'] ?? '');
            $text = is_string($text) || is_int($text) || is_float($text) ? trim((string) $text) : '';
            if ($text === '') {
                $errors[$key . '.attribute_value'] = lang('StaffProfile.valueRequired');
            } elseif (mb_strlen($text) > 255) {
                $errors[$key . '.attribute_value'] = lang('StaffProfile.tooLong', [255]);
            } elseif (isset($texts[$attributeId][mb_strtolower($text)])) {
                $errors[$key . '.attribute_value'] = lang('StaffProfile.valueTaken');
            }
            $texts[$attributeId][mb_strtolower($text)] = true;

            $place                  = $position[$attributeId] ?? 0;
            $position[$attributeId] = $place + 1;
            $sort                   = array_key_exists('sort_order', $item) ? $item['sort_order'] : ($stored['sort_order'] ?? $place);
            $sort                   = is_bool($sort) ? false : filter_var($sort, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 65535]]);
            if ($sort === false) {
                $errors[$key . '.sort_order'] = lang('StaffProfile.invalidSortOrder');
            }

            $names = null;
            if (array_key_exists('translations', $item) || $stored === null) {
                [$names, $translationErrors] = $this->translationsIn($item['translations'] ?? [], ['attribute_value' => 255], $key . '.translations');
                $errors += $translationErrors;
            }
            $values[] = ['stored' => $stored, 'row' => ['attribute_id' => $attributeId, 'attribute_value' => $text, 'sort_order' => (int) $sort], 'names' => $names];
        }

        return [$values, $errors];
    }

    /**
     * The request's attribute, once the signed-in client is found to be an active owner or manager of its business
     * @param string $endpoint For the error log
     * @return array{0: array|null, 1: array|null, 2: ResponseInterface|null} [the stored row, the request body, null] or [null, null, error response]
     */
    private function storedAttribute(string $endpoint): array
    {
        $input = $this->jsonInput();
        if ($input === null) {
            return [null, null, ApiResponse::error(lang('StaffProfile.invalidRequest'), 422)];
        }
        $publicId = $input['public_id'] ?? null;
        if (! is_string($publicId) || $publicId === '') {
            return [null, null, ApiResponse::error(lang('StaffProfile.attributeIdRequired'), 400)];
        }
        try {
            $stored = model(BusinessClientAttributeModel::class)->findByPublicId($publicId);
        } catch (Throwable $e) {
            return [null, null, $this->failed($endpoint, $e, 'StaffProfile.loadFailed')];
        }
        $error = $stored === null
            ? ApiResponse::error(lang('StaffProfile.attributeNotFound'), 404)
            : $this->roleError((int) $stored['business_id'], $endpoint, lang('StaffProfile.attributeNotFound'), 'StaffProfile');

        return $error === null ? [$stored, $input, null] : [null, null, $error];
    }

    /**
     * The request's person of the request's business, once the signed-in client is found to be an active owner or
     * manager of it
     * @param string $endpoint For the error log
     * @return array{0: array|null, 1: array|null, 2: ResponseInterface|null}
     *         [their business_client_link row with uid and full_name, the request body, null] or [null, null, error response]
     */
    private function person(string $endpoint): array
    {
        $input = $this->jsonInput();
        if ($input === null) {
            return [null, null, ApiResponse::error(lang('StaffProfile.invalidRequest'), 422)];
        }
        [$business, $error] = $this->managedBusinessBySlug($input, $endpoint, 'StaffProfile');
        if ($error !== null) {
            return [null, null, $error];
        }
        $uid = is_string($input['uid'] ?? null) ? trim($input['uid']) : '';
        if ($uid === '') {
            return [null, null, ApiResponse::error(lang('StaffProfile.uidRequired'), 400)];
        }
        try {
            $link = model(BusinessClientLinkModel::class)->findStaff((int) $business['id'], $uid);
        } catch (Throwable $e) {
            return [null, null, $this->failed($endpoint, $e, 'StaffProfile.loadFailed')];
        }

        return $link === null ? [null, null, ApiResponse::error(lang('StaffProfile.notFound'), 404)] : [$link, $input, null];
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

    /**
     * @param string    $endpoint For the error log
     * @param Throwable $e
     * @param string    $message  Language key shown to the user
     * @return ResponseInterface
     */
    private function failed(string $endpoint, Throwable $e, string $message): ResponseInterface
    {
        log_message('error', $endpoint . ': {message}', ['message' => $e->getMessage()]);

        return ApiResponse::error(lang($message), 500);
    }
}
