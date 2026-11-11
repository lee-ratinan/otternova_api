<?php

namespace App\Filters;

use App\Models\BusinessMasterModel;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * After a Client app endpoint that changes what the mart pages show has succeeded, makes the business's cached mart
 * answers stale (App\Libraries\MartCache). The business is the request's business_slug, from a JSON or a multipart
 * body; when that slug is gone (business/edit renamed it), the new one in the answer's data.business_slug.
 * Which endpoints: Config\Filters::$filters['martCacheRefresh']. Never changes the response, and a cache that will
 * not clear is only logged: the answers then expire by Config\Mart::$cacheTtl.
 */
class MartCacheRefresh implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        return null;
    }

    /**
     * @param RequestInterface  $request
     * @param ResponseInterface $response
     * @param array|null        $arguments
     * @return null
     */
    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300 || ! $request instanceof IncomingRequest) {
            return null;
        }

        try {
            $cache = service('martCache');
            $slugs = array_unique(array_filter([$this->requestSlug($request), $this->responseSlug($response)]));
            foreach ($slugs as $slug) {
                $cache->forgetSlug($slug);
                $business = model(BusinessMasterModel::class)->select('id')->where('business_slug', $slug)->first();
                if ($business !== null) {
                    $cache->forget((int) $business['id']);
                }
            }
        } catch (Throwable $e) {
            log_message('error', 'The mart cache could not be cleared: {message}', ['message' => $e->getMessage()]);
        }

        return null;
    }

    private function requestSlug(IncomingRequest $request): ?string
    {
        $slug = $request->getPost('business_slug');
        if (! is_string($slug)) {
            try {
                $json = $request->getJSON(true);
            } catch (Throwable) {
                $json = null;
            }
            $slug = is_array($json) ? ($json['business_slug'] ?? null) : null;
        }

        return is_string($slug) && trim($slug) !== '' ? trim($slug) : null;
    }

    private function responseSlug(ResponseInterface $response): ?string
    {
        $body = json_decode((string) $response->getBody(), true);
        $slug = is_array($body) && is_array($body['data'] ?? null) ? ($body['data']['business_slug'] ?? null) : null;

        return is_string($slug) && $slug !== '' ? $slug : null;
    }
}
