<?php

namespace App\Controllers;

use App\Libraries\ApiResponse;
use App\Models\ArtifactMasterModel;
use App\Models\BusinessServiceResourceLinkModel;
use App\Models\BusinessStoreModel;
use App\Models\ResourceMasterModel;
use App\Models\ResourceTypeModel;
use CodeIgniter\HTTP\Exceptions\HTTPException;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * The resources a business's services need besides a person: the kinds of resource (resource_type, "Massage room"),
 * the real ones at each store (resource_master, rooms "101" to "103"), and which kinds each service needs
 * (business_service_resource_link). The Client app's, behind the jwt:C filter, and only for the active owners and
 * managers of the business. A business comes in by its business_slug, everything else by its public_id. Nothing here
 * is deleted: a type or a resource is made inactive (I) instead.
 */
class Resources extends BaseController
{
    use ManagedBusinessTrait;
    use TranslationInputTrait;

    private const NAME_MAX_LENGTH        = 255;
    private const DESCRIPTION_MAX_LENGTH = 10000;

    /**
     * POST /client/resource-types { business_slug }
     * The resource types of that business by name: for each, public_id, type_name, type_description, buffer_minutes,
     * type_status, resource_count, active_resource_count, service_count and translations { language_code:
     * { type_name, type_description } }
     * @return ResponseInterface
     */
    public function types(): ResponseInterface
    {
        $endpoint = 'v1/client/resource-types';
        $input    = $this->jsonInput();
        if ($input === null) {
            return ApiResponse::error(lang('Resource.invalidRequest'), 422);
        }
        [$business, $error] = $this->managedBusinessBySlug($input, $endpoint, 'Resource');
        if ($error !== null) {
            return $error;
        }
        try {
            $rows = model(ResourceTypeModel::class)->listOfBusiness((int) $business['id']);
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'Resource.loadFailed');
        }

        return ApiResponse::success(['resource_types' => $rows]);
    }

    /**
     * POST /client/resource-type/create { business_slug, resource_type: { type_name, type_description, buffer_minutes,
     * type_status }, translations: { language_code: { type_name, type_description } } }
     * type_status A (the default) or I; buffer_minutes 0-1440, the turnover time after each use, 0 when left out.
     * "translations" is optional.
     * @return ResponseInterface { public_id }
     */
    public function createType(): ResponseInterface
    {
        return $this->saveType(false);
    }

    /**
     * POST /client/resource-type/edit { public_id, resource_type: {...}, translations: {...} }
     * A field left out of "resource_type" keeps its value. "translations", when given, replaces the texts: a language
     * left out loses them; left out altogether, they stay as they are.
     * @return ResponseInterface { public_id }
     */
    public function editType(): ResponseInterface
    {
        return $this->saveType(true);
    }

    /**
     * POST /client/resources { business_slug, store_public_id, type_public_id }
     * The resources of that business, of one store and one type when given, by store, type, sort_order and name: for
     * each, public_id, resource_name, capacity, resource_status, sort_order, store { public_id, store_name } and resource_type
     * { public_id, type_name, type_status }
     * @return ResponseInterface
     */
    public function list(): ResponseInterface
    {
        $endpoint = 'v1/client/resources';
        $input    = $this->jsonInput();
        if ($input === null) {
            return ApiResponse::error(lang('Resource.invalidRequest'), 422);
        }
        [$business, $error] = $this->managedBusinessBySlug($input, $endpoint, 'Resource');
        if ($error !== null) {
            return $error;
        }
        $businessId = (int) $business['id'];
        try {
            [$store, $type, $errors] = $this->storeAndType($businessId, $input, true);
            if ($errors !== []) {
                return ApiResponse::error(lang('Resource.invalidFields'), 422, $errors);
            }
            $rows = model(ResourceMasterModel::class)->listOfBusiness($businessId, $store === null ? null : (int) $store['id'], $type === null ? null : (int) $type['id']);
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'Resource.loadFailed');
        }

        return ApiResponse::success(['resources' => $rows]);
    }

    /**
     * POST /client/resource/create { business_slug, resource: { store_public_id, type_public_id, resource_name,
     * capacity, resource_status, sort_order } }
     * The store must be a physical store of the business and the type an active type of it; both are fixed once
     * created. capacity 1-65535, 1 when left out, how many customers it holds at once (a group session takes no more
     * than its resources hold). resource_status A (the default) or I; sort_order 0-65535, 0 when left out, the order free resources
     * are picked in.
     * @return ResponseInterface { public_id }
     */
    public function create(): ResponseInterface
    {
        return $this->saveResource(false);
    }

    /**
     * POST /client/resource/edit { public_id, resource: { resource_name, capacity, resource_status, sort_order } }
     * A field left out keeps its value. The store and the type cannot change: a store_public_id or type_public_id
     * other than the resource's own is refused.
     * @return ResponseInterface { public_id }
     */
    public function edit(): ResponseInterface
    {
        return $this->saveResource(true);
    }

    /**
     * POST /client/artifact/resources { public_id }
     * What a service needs: resources [{ public_id (of the type), type_name, type_status, quantity }], by type name
     * @return ResponseInterface
     */
    public function needs(): ResponseInterface
    {
        $endpoint = 'v1/client/artifact/resources';
        [$artifact, , $error] = $this->service($endpoint);
        if ($error !== null) {
            return $error;
        }
        try {
            $rows = model(BusinessServiceResourceLinkModel::class)->needsOf((int) $artifact['business_id'], (int) $artifact['id']);
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'Resource.loadFailed');
        }

        return ApiResponse::success(['resources' => $rows]);
    }

    /**
     * POST /client/artifact/resources/save { public_id, resources: [{ public_id (of the type), quantity }, ...] }
     * Replaces what the service needs: a type left out is no longer needed, [] needs none. Each type once, quantity
     * 1-255 (1 when left out); an inactive type may stay but not be added.
     * @return ResponseInterface { public_id }
     */
    public function saveNeeds(): ResponseInterface
    {
        $endpoint = 'v1/client/artifact/resources/save';
        [$artifact, $input, $error] = $this->service($endpoint);
        if ($error !== null) {
            return $error;
        }
        $wanted = $input['resources'] ?? null;
        if (! is_array($wanted) || ! array_is_list($wanted)) {
            return ApiResponse::error(lang('Resource.invalidFields'), 422, ['resources' => lang('Resource.invalidList')]);
        }
        $businessId = (int) $artifact['business_id'];
        $links      = model(BusinessServiceResourceLinkModel::class);
        try {
            $current = $links->quantitiesOf($businessId, (int) $artifact['id']);
            $types   = model(ResourceTypeModel::class)->byPublicIdOf($businessId);
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'Resource.loadFailed');
        }

        $needs  = [];
        $errors = [];
        foreach ($wanted as $i => $item) {
            $key      = 'resources.' . $i;
            $publicId = is_array($item) && is_string($item['public_id'] ?? null) ? $item['public_id'] : '';
            $type     = $types[$publicId] ?? null;
            if ($type === null) {
                $errors[$key . '.public_id'] = lang('Resource.unknownType');
                continue;
            }
            if (isset($needs[$type['id']])) {
                $errors[$key . '.public_id'] = lang('Resource.duplicateType');
                continue;
            }
            if ($type['type_status'] !== ResourceTypeModel::STATUS_ACTIVE && ! isset($current[$type['id']])) {
                $errors[$key . '.public_id'] = lang('Resource.inactiveType');
                continue;
            }
            $quantity = self::wholeNumber($item['quantity'] ?? 1);
            if ($quantity === null || $quantity < 1 || $quantity > BusinessServiceResourceLinkModel::MAX_QUANTITY) {
                $errors[$key . '.quantity'] = lang('Resource.invalidQuantity', [BusinessServiceResourceLinkModel::MAX_QUANTITY]);
                continue;
            }
            $needs[$type['id']] = $quantity;
        }
        if ($errors !== []) {
            return ApiResponse::error(lang('Resource.invalidFields'), 422, $errors);
        }

        try {
            $links->replaceNeeds($artifact, $needs, $this->actorId());
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'Resource.saveFailed');
        }

        return ApiResponse::success(['public_id' => $artifact['public_id']], 200, lang('Resource.needsSaved'));
    }

    /**
     * createType() and editType()
     * @param bool $edit
     * @return ResponseInterface
     */
    private function saveType(bool $edit): ResponseInterface
    {
        $endpoint = $edit ? 'v1/client/resource-type/edit' : 'v1/client/resource-type/create';
        $input    = $this->jsonInput();
        $in       = $input['resource_type'] ?? [];
        if ($input === null || ! is_array($in)) {
            return ApiResponse::error(lang('Resource.invalidRequest'), 422);
        }

        $types  = model(ResourceTypeModel::class);
        $stored = null;
        if ($edit) {
            $publicId = $input['public_id'] ?? null;
            if (! is_string($publicId) || $publicId === '') {
                return ApiResponse::error(lang('Resource.typeIdRequired'), 400);
            }
            try {
                $stored = $types->findByPublicId($publicId);
            } catch (Throwable $e) {
                return $this->failed($endpoint, $e, 'Resource.loadFailed');
            }
            $error = $stored === null
                ? ApiResponse::error(lang('Resource.typeNotFound'), 404)
                : $this->roleError((int) $stored['business_id'], $endpoint, lang('Resource.typeNotFound'), 'Resource');
            $businessId = $stored === null ? 0 : (int) $stored['business_id'];
        } else {
            [$business, $error] = $this->managedBusinessBySlug($input, $endpoint, 'Resource');
            $businessId         = $business === null ? 0 : (int) $business['id'];
        }
        if ($error !== null) {
            return $error;
        }

        $defaults = ['type_name' => '', 'type_description' => null, 'buffer_minutes' => 0, 'type_status' => ResourceTypeModel::STATUS_ACTIVE];
        $type     = ['business_id' => $businessId];
        foreach ($defaults as $field => $default) {
            $value        = array_key_exists($field, $in) ? $in[$field] : ($stored[$field] ?? $default);
            $type[$field] = is_string($value) || is_int($value) ? trim((string) $value) : '';
        }
        $type['type_status']      = strtoupper($type['type_status']);
        $type['type_description'] = $type['type_description'] === '' ? null : $type['type_description'];

        $errors = [];
        $names  = null;
        if (array_key_exists('translations', $input) || ! $edit) {
            [$names, $errors] = $this->translationsIn($input['translations'] ?? [], ['type_name' => self::NAME_MAX_LENGTH, 'type_description' => self::DESCRIPTION_MAX_LENGTH]);
        }
        $errors = $this->validationErrors($types->getValidationRules(), $type) + $errors;
        if ($errors !== []) {
            return ApiResponse::error(lang('Resource.invalidFields'), 422, $errors);
        }
        $type['buffer_minutes'] = (int) $type['buffer_minutes'];

        try {
            if ($types->nameTaken($businessId, $type['type_name'], $stored === null ? null : (int) $stored['id'])) {
                return ApiResponse::error(lang('Resource.invalidFields'), 422, ['type_name' => lang('Resource.typeNameTaken')]);
            }
            if ($stored === null) {
                $publicId = $types->createWithNames($type, $names ?? [], $this->actorId());
            } else {
                $types->updateWithNames($stored, $type, $names, $this->actorId());
                $publicId = $stored['public_id'];
            }
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'Resource.saveFailed');
        }

        return ApiResponse::success(['public_id' => $publicId], 200, lang($edit ? 'Resource.typeUpdated' : 'Resource.typeCreated'));
    }

    /**
     * create() and edit()
     * @param bool $edit
     * @return ResponseInterface
     */
    private function saveResource(bool $edit): ResponseInterface
    {
        $endpoint = $edit ? 'v1/client/resource/edit' : 'v1/client/resource/create';
        $input    = $this->jsonInput();
        $in       = $input['resource'] ?? [];
        if ($input === null || ! is_array($in)) {
            return ApiResponse::error(lang('Resource.invalidRequest'), 422);
        }

        $resources = model(ResourceMasterModel::class);
        $stored    = null;
        if ($edit) {
            $publicId = $input['public_id'] ?? null;
            if (! is_string($publicId) || $publicId === '') {
                return ApiResponse::error(lang('Resource.resourceIdRequired'), 400);
            }
            try {
                $stored = $resources->findByPublicId($publicId);
            } catch (Throwable $e) {
                return $this->failed($endpoint, $e, 'Resource.loadFailed');
            }
            $error = $stored === null
                ? ApiResponse::error(lang('Resource.resourceNotFound'), 404)
                : $this->roleError((int) $stored['business_id'], $endpoint, lang('Resource.resourceNotFound'), 'Resource');
            $businessId = $stored === null ? 0 : (int) $stored['business_id'];
        } else {
            [$business, $error] = $this->managedBusinessBySlug($input, $endpoint, 'Resource');
            $businessId         = $business === null ? 0 : (int) $business['id'];
        }
        if ($error !== null) {
            return $error;
        }

        $defaults = ['resource_name' => '', 'capacity' => 1, 'resource_status' => ResourceMasterModel::STATUS_ACTIVE, 'sort_order' => 0];
        $resource = [];
        foreach ($defaults as $field => $default) {
            $value            = array_key_exists($field, $in) ? $in[$field] : ($stored[$field] ?? $default);
            $resource[$field] = is_string($value) || is_int($value) ? trim((string) $value) : '';
        }
        $resource['resource_status'] = strtoupper($resource['resource_status']);
        $errors                      = $this->validationErrors($resources->getValidationRules(), $resource);

        try {
            if ($stored === null) {
                [$store, $type, $placeErrors] = $this->storeAndType($businessId, $in, false);
                $errors += $placeErrors;
            } else {
                $store = ['id' => (int) $stored['store_id']];
                $type  = ['id' => (int) $stored['resource_type_id']];
                $errors += $this->fixedPlaceErrors($stored, $in);
            }
            if ($errors !== []) {
                return ApiResponse::error(lang('Resource.invalidFields'), 422, $errors);
            }
            $resource['capacity']   = (int) $resource['capacity'];
            $resource['sort_order'] = (int) $resource['sort_order'];
            if ($resources->nameTaken((int) $store['id'], (int) $type['id'], $resource['resource_name'], $stored === null ? null : (int) $stored['id'])) {
                return ApiResponse::error(lang('Resource.invalidFields'), 422, ['resource_name' => lang('Resource.resourceNameTaken')]);
            }
            if ($stored === null) {
                $publicId = $resources->createLogged(['business_id' => $businessId, 'store_id' => (int) $store['id'], 'resource_type_id' => (int) $type['id']] + $resource, $this->actorId());
            } else {
                $resources->updateLogged($stored, $resource, $this->actorId());
                $publicId = $stored['public_id'];
            }
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'Resource.saveFailed');
        }

        return ApiResponse::success(['public_id' => $publicId], 200, lang($edit ? 'Resource.resourceUpdated' : 'Resource.resourceCreated'));
    }

    /**
     * The store and the type of $in's store_public_id and type_public_id, both of the business
     * @param int   $businessId
     * @param array $in
     * @param bool  $optional For a filter: either may be left out (null). For a new resource both are required, the
     *                        store must be physical and the type active
     * @return array{0: array|null, 1: array|null, 2: array<string, string>} [store, type, field => error]
     */
    private function storeAndType(int $businessId, array $in, bool $optional): array
    {
        $errors = [];
        $store  = null;
        $type   = null;
        $storeId = $in['store_public_id'] ?? null;
        if ($storeId !== null || ! $optional) {
            $store = model(BusinessStoreModel::class)->findByPublicId($storeId);
            if ($store === null || (int) $store['business_id'] !== $businessId) {
                $errors['store_public_id'] = lang('Resource.unknownStore');
                $store                     = null;
            } elseif (! $optional && $store['store_type'] !== BusinessStoreModel::TYPE_PHYSICAL) {
                $errors['store_public_id'] = lang('Resource.physicalStoreOnly');
            }
        }
        $typeId = $in['type_public_id'] ?? null;
        if ($typeId !== null || ! $optional) {
            $type = model(ResourceTypeModel::class)->findByPublicId($typeId);
            if ($type === null || (int) $type['business_id'] !== $businessId) {
                $errors['type_public_id'] = lang('Resource.unknownType');
                $type                     = null;
            } elseif (! $optional && $type['type_status'] !== ResourceTypeModel::STATUS_ACTIVE) {
                $errors['type_public_id'] = lang('Resource.inactiveType');
            }
        }

        return [$store, $type, $errors];
    }

    /**
     * The errors of an edit that tries to move a resource to another store or type
     * @param array $stored The resource_master row
     * @param array $in
     * @return array<string, string>
     */
    private function fixedPlaceErrors(array $stored, array $in): array
    {
        $errors = [];
        foreach (['store_public_id' => [BusinessStoreModel::class, 'store_id'], 'type_public_id' => [ResourceTypeModel::class, 'resource_type_id']] as $field => [$model, $column]) {
            if (! array_key_exists($field, $in)) {
                continue;
            }
            $row = model($model)->findByPublicId($in[$field]);
            if ($row === null || (int) $row['id'] !== (int) $stored[$column]) {
                $errors[$field] = lang('Resource.placeFixed');
            }
        }

        return $errors;
    }

    /**
     * The service of the request's public_id, when the signed-in client manages its business
     * @param string $endpoint
     * @return array{0: array|null, 1: array|null, 2: ResponseInterface|null} [artifact, input, null] or [null, null, error]
     */
    private function service(string $endpoint): array
    {
        $input = $this->jsonInput();
        if ($input === null) {
            return [null, null, ApiResponse::error(lang('Resource.invalidRequest'), 422)];
        }
        $publicId = $input['public_id'] ?? null;
        if (! is_string($publicId) || $publicId === '') {
            return [null, null, ApiResponse::error(lang('Resource.serviceIdRequired'), 400)];
        }
        try {
            $artifact = model(ArtifactMasterModel::class)->findByPublicId($publicId);
        } catch (Throwable $e) {
            return [null, null, $this->failed($endpoint, $e, 'Resource.loadFailed')];
        }
        if ($artifact === null) {
            return [null, null, ApiResponse::error(lang('Resource.serviceNotFound'), 404)];
        }
        $error = $this->roleError((int) $artifact['business_id'], $endpoint, lang('Resource.serviceNotFound'), 'Resource');
        if ($error === null && $artifact['artifact_type'] !== ArtifactMasterModel::TYPE_SERVICE) {
            $error = ApiResponse::error(lang('Resource.notAService'), 422);
        }

        return $error === null ? [$artifact, $input, null] : [null, null, $error];
    }

    /**
     * @param array $modelRules field => rules, from the model
     * @param array $row
     * @return array<string, string> field => error
     */
    private function validationErrors(array $modelRules, array $row): array
    {
        $rules = [];
        foreach ($modelRules as $field => $fieldRules) {
            if (array_key_exists($field, $row)) {
                $rules[$field] = ['label' => lang('Resource.fields.' . $field), 'rules' => $fieldRules];
            }
        }
        $validation = single_service('validation')->setRules($rules);

        return $validation->run($row) ? [] : $validation->getErrors();
    }

    /**
     * @param mixed $value
     * @return int|null The whole number of an int or a string of digits, else null
     */
    private static function wholeNumber(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && ctype_digit($value) ? (int) $value : null;
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
