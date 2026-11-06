<?php

namespace App\Controllers;

use App\Libraries\ApiResponse;
use App\Models\ArtifactAttributeModel;
use CodeIgniter\HTTP\Exceptions\HTTPException;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * The attributes of a business's variants (artifact_attribute: Color, Size) with their values
 * (artifact_attribute_value: Blue, Red): the Client app's, behind the jwt:C filter, and only for the active owners and
 * managers of the business. Attributes and values are never deleted, as variants may use them.
 */
class ArtifactAttributes extends BaseController
{
    use ManagedBusinessTrait;
    use TranslationInputTrait;

    /** The most values one request may send */
    private const MAX_VALUES = 100;

    /**
     * POST /client/artifact-attributes { business_slug }
     * The attributes of that business by name: for each, public_id, attribute_name, translations and its values in
     * their order, each { public_id, attribute_value, sort_order, translations }
     * @return ResponseInterface
     */
    public function list(): ResponseInterface
    {
        $endpoint = 'v1/client/artifact-attributes';
        $input    = $this->jsonInput();
        if ($input === null) {
            return ApiResponse::error(lang('ArtifactAttribute.invalidRequest'), 422);
        }
        [$business, $error] = $this->managedBusinessBySlug($input, $endpoint, 'ArtifactAttribute');
        if ($error !== null) {
            return $error;
        }
        try {
            $rows = model(ArtifactAttributeModel::class)->listOfBusiness((int) $business['id']);
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'ArtifactAttribute.loadFailed');
        }

        return ApiResponse::success(['attributes' => $rows]);
    }

    /**
     * POST /client/artifact-attribute/create { business_slug, attribute: { attribute_name },
     * translations: { language_code: name }, values: [{ attribute_value, sort_order, translations }] }
     * "translations" and "values" are optional; a value's sort_order is its place in the list when left out.
     * @return ResponseInterface The attribute, as list() gives it
     */
    public function create(): ResponseInterface
    {
        return $this->save(false);
    }

    /**
     * POST /client/artifact-attribute/edit { public_id, attribute: {...}, translations: {...},
     * values: [{ public_id, attribute_value, sort_order, translations }] }
     * A value with a public_id updates that value of the attribute (a field left out keeps its value; its
     * translations, when given, replace its names); one without is added. A value left out of "values" stays as it
     * is. The attribute's "translations", when given, replace its names; left out, they stay.
     * @return ResponseInterface The attribute, as list() gives it
     */
    public function edit(): ResponseInterface
    {
        return $this->save(true);
    }

    /**
     * create() and edit()
     * @param bool $edit
     * @return ResponseInterface
     */
    private function save(bool $edit): ResponseInterface
    {
        $endpoint = $edit ? 'v1/client/artifact-attribute/edit' : 'v1/client/artifact-attribute/create';
        $input    = $this->jsonInput();
        $in       = $input['attribute'] ?? [];
        $valuesIn = $input['values'] ?? [];
        if ($input === null || ! is_array($in) || ! is_array($valuesIn) || ! array_is_list($valuesIn)) {
            return ApiResponse::error(lang('ArtifactAttribute.invalidRequest'), 422);
        }
        if (count($valuesIn) > self::MAX_VALUES) {
            return ApiResponse::error(lang('ArtifactAttribute.invalidFields'), 422, ['values' => lang('ArtifactAttribute.tooManyValues', [self::MAX_VALUES])]);
        }

        $attributes = model(ArtifactAttributeModel::class);
        $stored     = null;
        if ($edit) {
            $publicId = $input['public_id'] ?? null;
            if (! is_string($publicId) || $publicId === '') {
                return ApiResponse::error(lang('ArtifactAttribute.attributeIdRequired'), 400);
            }
            try {
                $stored = $attributes->findByPublicId($publicId);
            } catch (Throwable $e) {
                return $this->failed($endpoint, $e, 'ArtifactAttribute.loadFailed');
            }
            $error = $stored === null
                ? ApiResponse::error(lang('ArtifactAttribute.notFound'), 404)
                : $this->roleError((int) $stored['business_id'], $endpoint, lang('ArtifactAttribute.notFound'), 'ArtifactAttribute');
            $businessId = $stored === null ? 0 : (int) $stored['business_id'];
        } else {
            [$business, $error] = $this->managedBusinessBySlug($input, $endpoint, 'ArtifactAttribute');
            $businessId         = $business === null ? 0 : (int) $business['id'];
        }
        if ($error !== null) {
            return $error;
        }

        $errors = [];
        $name   = array_key_exists('attribute_name', $in) ? $in['attribute_name'] : ($stored['attribute_name'] ?? '');
        $name   = is_string($name) ? trim($name) : '';
        if ($name === '') {
            $errors['attribute_name'] = lang('ArtifactAttribute.nameRequired');
        } elseif (mb_strlen($name) > 64) {
            $errors['attribute_name'] = lang('ArtifactAttribute.tooLong', [64]);
        }
        $names = null;
        if (array_key_exists('translations', $input) || ! $edit) {
            [$names, $translationErrors] = $this->translationsIn($input['translations'] ?? [], ['attribute_name' => 64]);
            $errors += $translationErrors;
        }

        try {
            $storedValues = $stored === null ? [] : $attributes->valuesOf((int) $stored['id']);
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'ArtifactAttribute.loadFailed');
        }
        [$values, $valueErrors] = $this->values($valuesIn, $storedValues);
        $errors += $valueErrors;
        if ($errors !== []) {
            return ApiResponse::error(lang('ArtifactAttribute.invalidFields'), 422, $errors);
        }

        try {
            if ($attributes->nameTaken($businessId, $name, $stored === null ? null : (int) $stored['id'])) {
                return ApiResponse::error(lang('ArtifactAttribute.invalidFields'), 422, ['attribute_name' => lang('ArtifactAttribute.nameTaken')]);
            }
            $publicId = $attributes->saveWithValues($stored, ['business_id' => $businessId, 'attribute_name' => $name], $names, $values, $this->actorId());
            $saved    = array_values(array_filter($attributes->listOfBusiness($businessId), static fn (array $row) => $row['public_id'] === $publicId))[0];
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'ArtifactAttribute.saveFailed');
        }

        return ApiResponse::success(['attribute' => $saved], 200, lang($edit ? 'ArtifactAttribute.updated' : 'ArtifactAttribute.created'));
    }

    /**
     * The "values" of the body, checked against each other and the attribute's stored values
     * @param list<mixed>          $valuesIn
     * @param array<string, array> $storedValues public_id => artifact_attribute_value row
     * @return array{0: list<array>, 1: array<string, string>} [values as ArtifactAttributeModel::saveWithValues() takes them, errors]
     */
    private function values(array $valuesIn, array $storedValues): array
    {
        $values = [];
        $errors = [];
        $seen   = []; // public_id => true, for a value listed twice
        foreach ($valuesIn as $i => $in) {
            $key = 'values.' . $i;
            if (! is_array($in) || ($in !== [] && array_is_list($in))) {
                $errors[$key] = lang('ArtifactAttribute.invalidValue');

                continue;
            }
            $stored = null;
            if (array_key_exists('public_id', $in) && $in['public_id'] !== null && $in['public_id'] !== '') {
                $stored = is_string($in['public_id']) ? ($storedValues[$in['public_id']] ?? null) : null;
                if ($stored === null || isset($seen[$stored['public_id']])) {
                    $errors[$key . '.public_id'] = lang('ArtifactAttribute.valueNotFound');

                    continue;
                }
                $seen[$stored['public_id']] = true;
            }

            $text = array_key_exists('attribute_value', $in) ? $in['attribute_value'] : ($stored['attribute_value'] ?? '');
            $text = is_string($text) || is_int($text) ? trim((string) $text) : '';
            if ($text === '') {
                $errors[$key . '.attribute_value'] = lang('ArtifactAttribute.valueRequired');
            } elseif (mb_strlen($text) > 128) {
                $errors[$key . '.attribute_value'] = lang('ArtifactAttribute.tooLong', [128]);
            }
            $sort = array_key_exists('sort_order', $in) ? $in['sort_order'] : ($stored['sort_order'] ?? $i);
            $sort = filter_var($sort, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 65535]]);
            if ($sort === false) {
                $errors[$key . '.sort_order'] = lang('ArtifactAttribute.invalidSortOrder');
            }
            $names = null;
            if (array_key_exists('translations', $in) || $stored === null) {
                [$names, $translationErrors] = $this->translationsIn($in['translations'] ?? [], ['attribute_value' => 128], $key . '.translations');
                $errors += $translationErrors;
            }
            $values[$i] = ['stored' => $stored, 'row' => ['attribute_value' => $text, 'sort_order' => (int) $sort], 'names' => $names];
        }

        // no two values of the attribute with the same text, once these are saved
        $texts = [];
        foreach ($storedValues as $publicId => $row) {
            if (! isset($seen[$publicId])) {
                $texts[mb_strtolower($row['attribute_value'])] = true;
            }
        }
        foreach ($values as $i => $value) {
            $text = mb_strtolower($value['row']['attribute_value']);
            if ($text !== '' && isset($texts[$text])) {
                $errors['values.' . $i . '.attribute_value'] = lang('ArtifactAttribute.valueTaken');
            }
            $texts[$text] = true;
        }

        return [array_values($values), $errors];
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
