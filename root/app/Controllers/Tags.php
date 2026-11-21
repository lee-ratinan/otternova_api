<?php

namespace App\Controllers;

use App\Libraries\ApiResponse;
use App\Models\TagMasterModel;
use CodeIgniter\HTTP\Exceptions\HTTPException;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * The tags a business gives itself, for searching businesses (TagMasterModel): the Client app's, behind the jwt:C
 * filter (clients only), and only for the active owners and managers of the business, which comes in by its
 * business_slug. Tags are shared by every business and written in any language; a tag written like one that exists
 * is that one.
 */
class Tags extends BaseController
{
    use ManagedBusinessTrait;

    /**
     * POST /client/tags { business_slug }
     * { tags: [{public_id, tag_name}] (by name), options: {max_tags, max_length} }
     * @return ResponseInterface
     */
    public function show(): ResponseInterface
    {
        $endpoint = 'v1/client/tags';
        [$business, , $error] = $this->managed($endpoint);
        if ($error !== null) {
            return $error;
        }
        try {
            return ApiResponse::success($this->settings((int) $business['id']));
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'Tag.loadFailed');
        }
    }

    /**
     * POST /client/tags/save { business_slug, tags: ["Thai massage", "นวดแผนโบราณ", ...] }
     * Replaces the business's tags with these; [] takes them all off. Each is tidied (a leading # dropped, spaces made
     * single) and found by its key, case aside, or made. Tags that are the same once tidied count once. A bad tag is
     * refused with 422, errors keyed tags.<index>, more than TagMasterModel::MAX_PER_BUSINESS with errors.tags; nothing
     * is saved then.
     * @return ResponseInterface The tags, as show() gives them
     */
    public function save(): ResponseInterface
    {
        $endpoint = 'v1/client/tags/save';
        [$business, $input, $error] = $this->managed($endpoint);
        if ($error !== null) {
            return $error;
        }
        $result = TagMasterModel::normalizeTags($input['tags'] ?? null);
        if ($result['errors'] !== []) {
            $errors = [];
            foreach ($result['errors'] as $index => $key) {
                $errors[$index === 'tags' ? 'tags' : 'tags.' . $index] = lang('Tag.' . $key, [TagMasterModel::MAX_PER_BUSINESS, TagMasterModel::MAX_LENGTH]);
            }

            return ApiResponse::error(lang('Tag.invalidFields'), 422, $errors);
        }
        $businessId = (int) $business['id'];
        try {
            model(TagMasterModel::class)->replaceTags($businessId, $result['tags'], $this->actorId());

            return ApiResponse::success($this->settings($businessId), 200, lang('Tag.saved'));
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'Tag.saveFailed');
        }
    }

    /**
     * POST /client/tags/suggest { business_slug, q }
     * { suggestions: [{tag_name, business_count}] }: tags other businesses have whose key holds q, those starting with
     * it first, then the most used; not the business's own. [] for a q that is not a tag.
     * @return ResponseInterface
     */
    public function suggest(): ResponseInterface
    {
        $endpoint = 'v1/client/tags/suggest';
        [$business, $input, $error] = $this->managed($endpoint);
        if ($error !== null) {
            return $error;
        }
        try {
            $q = is_string($input['q'] ?? null) ? $input['q'] : '';

            return ApiResponse::success(['suggestions' => model(TagMasterModel::class)->suggest($q, (int) $business['id'])]);
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'Tag.loadFailed');
        }
    }

    /**
     * What show() and save() send back
     * @param int $businessId
     * @return array
     */
    private function settings(int $businessId): array
    {
        return [
            'tags'    => model(TagMasterModel::class)->tagsOf($businessId),
            'options' => ['max_tags' => TagMasterModel::MAX_PER_BUSINESS, 'max_length' => TagMasterModel::MAX_LENGTH],
        ];
    }

    /**
     * The business of the request, when the signed-in client may manage it
     * @param string $endpoint For the error log
     * @return array{0: array|null, 1: array, 2: ResponseInterface|null} [business, input, null] or [null, [], error]
     */
    private function managed(string $endpoint): array
    {
        $input = $this->jsonInput();
        if ($input === null) {
            return [null, [], ApiResponse::error(lang('Tag.invalidRequest'), 422)];
        }
        [$business, $error] = $this->managedBusinessBySlug($input, $endpoint, 'Tag');

        return $error === null ? [$business, $input, null] : [null, [], $error];
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
