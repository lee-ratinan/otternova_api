<?php

namespace App\Controllers;

use App\Libraries\ApiResponse;
use App\Models\BusinessSocialLinkModel;
use CodeIgniter\HTTP\Exceptions\HTTPException;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * A business's social media accounts (BusinessSocialLinkModel): the Client app's, behind the jwt:C filter (clients
 * only), and only for the active owners and managers of the business, which comes in by its business_slug. At most one
 * account per platform of BusinessSocialLinkModel::PLATFORM_HOSTS, each a URL on that platform's own site, in the
 * order they are shown.
 */
class SocialLinks extends BaseController
{
    use ManagedBusinessTrait;

    /** listSocialMediaKeys() */
    protected $helpers = ['list'];

    /**
     * POST /client/social-links { business_slug }
     * { links: [{public_id, platform, profile_url, sort_order}] (in order), options: {platforms: {platform: name}} }
     * @return ResponseInterface
     */
    public function show(): ResponseInterface
    {
        $endpoint = 'v1/client/social-links';
        [$business, , $error] = $this->managed($endpoint);
        if ($error !== null) {
            return $error;
        }
        try {
            return ApiResponse::success($this->settings((int) $business['id']));
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'SocialLink.loadFailed');
        }
    }

    /**
     * POST /client/social-links/save { business_slug, links: [{platform, profile_url}, ...] }
     * Replaces the business's links with these, in this order; [] removes them all. A URL given without https:// is
     * taken as https, and tracking parameters are dropped. A bad link is refused with 422, errors keyed links.<index>
     * (or links when it is not a list), and nothing is saved then.
     * @return ResponseInterface The links, as show() gives them
     */
    public function save(): ResponseInterface
    {
        $endpoint = 'v1/client/social-links/save';
        [$business, $input, $error] = $this->managed($endpoint);
        if ($error !== null) {
            return $error;
        }
        if (! array_key_exists('links', $input)) {
            return ApiResponse::error(lang('SocialLink.invalidFields'), 422, ['links' => lang('SocialLink.invalidLinks')]);
        }
        $result = BusinessSocialLinkModel::normalizeLinks($input['links']);
        if ($result['errors'] !== []) {
            $errors = [];
            foreach ($result['errors'] as $index => $key) {
                $errors[$index === 'links' ? 'links' : 'links.' . $index] = lang('SocialLink.' . $key);
            }

            return ApiResponse::error(lang('SocialLink.invalidFields'), 422, $errors);
        }
        $businessId = (int) $business['id'];
        try {
            model(BusinessSocialLinkModel::class)->replaceLinks($businessId, $result['links'], $this->actorId());

            return ApiResponse::success($this->settings($businessId), 200, lang('SocialLink.saved'));
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'SocialLink.saveFailed');
        }
    }

    /**
     * What show() and save() send back
     * @param int $businessId
     * @return array
     */
    private function settings(int $businessId): array
    {
        $names = listSocialMediaKeys();
        $platforms = [];
        foreach (array_keys(BusinessSocialLinkModel::PLATFORM_HOSTS) as $platform) {
            $platforms[$platform] = $names[$platform] ?? $platform;
        }

        return [
            'links'   => model(BusinessSocialLinkModel::class)->linksOf($businessId),
            'options' => ['platforms' => $platforms],
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
            return [null, [], ApiResponse::error(lang('SocialLink.invalidRequest'), 422)];
        }
        [$business, $error] = $this->managedBusinessBySlug($input, $endpoint, 'SocialLink');

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
