<?php

namespace App\Controllers;

use App\Libraries\ApiResponse;
use App\Models\ArtifactCategoryModel;
use CodeIgniter\HTTP\Exceptions\HTTPException;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * The categories of a business's artifacts (artifact_category): the Client app's, behind the jwt:C filter, and only
 * for the active owners and managers of the business. A business comes in by its business_slug, a category by its
 * public_id. Categories are never deleted; category_status I hides one.
 */
class ArtifactCategories extends BaseController
{
    use ManagedBusinessTrait;
    use TranslationInputTrait;

    /**
     * POST /client/artifact-categories { business_slug }
     * The categories of that business in their order: for each, public_id, category_name, category_status,
     * sort_order, artifact_count and its names in other languages as translations { language_code: name }
     * @return ResponseInterface
     */
    public function list(): ResponseInterface
    {
        $endpoint = 'v1/client/artifact-categories';
        $input    = $this->jsonInput();
        if ($input === null) {
            return ApiResponse::error(lang('ArtifactCategory.invalidRequest'), 422);
        }
        [$business, $error] = $this->managedBusinessBySlug($input, $endpoint, 'ArtifactCategory');
        if ($error !== null) {
            return $error;
        }
        try {
            $rows = model(ArtifactCategoryModel::class)->listOfBusiness((int) $business['id']);
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'ArtifactCategory.loadFailed');
        }

        return ApiResponse::success(['categories' => $rows]);
    }

    /**
     * POST /client/artifact-category/create { business_slug, category: { category_name, category_status, sort_order },
     * translations: { language_code: name } }
     * category_status is A (the default) or I; sort_order 0-65535, 0 when left out. "translations" is optional.
     * @return ResponseInterface { public_id }
     */
    public function create(): ResponseInterface
    {
        return $this->save(false);
    }

    /**
     * POST /client/artifact-category/edit { public_id, category: {...}, translations: {...} }
     * A field left out of "category" keeps its value. "translations", when given, replaces the names: a language
     * left out loses its name; left out altogether, the names stay as they are.
     * @return ResponseInterface { public_id }
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
        $endpoint = $edit ? 'v1/client/artifact-category/edit' : 'v1/client/artifact-category/create';
        $input    = $this->jsonInput();
        $in       = $input['category'] ?? [];
        if ($input === null || ! is_array($in)) {
            return ApiResponse::error(lang('ArtifactCategory.invalidRequest'), 422);
        }

        $categories = model(ArtifactCategoryModel::class);
        $stored     = null;
        if ($edit) {
            $publicId = $input['public_id'] ?? null;
            if (! is_string($publicId) || $publicId === '') {
                return ApiResponse::error(lang('ArtifactCategory.categoryIdRequired'), 400);
            }
            try {
                $stored = $categories->findByPublicId($publicId);
            } catch (Throwable $e) {
                return $this->failed($endpoint, $e, 'ArtifactCategory.loadFailed');
            }
            $error = $stored === null
                ? ApiResponse::error(lang('ArtifactCategory.notFound'), 404)
                : $this->roleError((int) $stored['business_id'], $endpoint, lang('ArtifactCategory.notFound'), 'ArtifactCategory');
            $businessId = $stored === null ? 0 : (int) $stored['business_id'];
        } else {
            [$business, $error] = $this->managedBusinessBySlug($input, $endpoint, 'ArtifactCategory');
            $businessId         = $business === null ? 0 : (int) $business['id'];
        }
        if ($error !== null) {
            return $error;
        }

        $defaults = ['category_name' => '', 'category_status' => ArtifactCategoryModel::STATUS_ACTIVE, 'sort_order' => 0];
        $category = ['business_id' => $businessId];
        foreach ($defaults as $field => $default) {
            $value            = array_key_exists($field, $in) ? $in[$field] : ($stored[$field] ?? $default);
            $category[$field] = is_string($value) || is_int($value) ? trim((string) $value) : '';
        }
        $category['category_status'] = strtoupper($category['category_status']);

        $errors = [];
        $names  = null;
        if (array_key_exists('translations', $input) || ! $edit) {
            [$names, $errors] = $this->translationsIn($input['translations'] ?? [], ['category_name' => 255]);
        }
        $rules = [];
        foreach ($categories->getValidationRules() as $field => $fieldRules) {
            $rules[$field] = ['label' => lang('ArtifactCategory.fields.' . $field), 'rules' => $fieldRules];
        }
        $validation = single_service('validation')->setRules($rules);
        if (! $validation->run($category)) {
            $errors = $validation->getErrors() + $errors;
        }
        if ($errors !== []) {
            return ApiResponse::error(lang('ArtifactCategory.invalidFields'), 422, $errors);
        }
        $category['sort_order'] = (int) $category['sort_order'];

        try {
            if ($categories->nameTaken($businessId, $category['category_name'], $stored === null ? null : (int) $stored['id'])) {
                return ApiResponse::error(lang('ArtifactCategory.invalidFields'), 422, ['category_name' => lang('ArtifactCategory.nameTaken')]);
            }
            if ($stored === null) {
                $publicId = $categories->createWithNames($category, $names ?? [], $this->actorId());
            } else {
                $categories->updateWithNames($stored, $category, $names, $this->actorId());
                $publicId = $stored['public_id'];
            }
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'ArtifactCategory.saveFailed');
        }

        return ApiResponse::success(['public_id' => $publicId], 200, lang($edit ? 'ArtifactCategory.updated' : 'ArtifactCategory.created'));
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
