<?php

namespace App\Libraries;

use App\Models\BusinessMasterModel;
use CodeIgniter\Cache\CacheInterface;
use Config\Mart as MartConfig;

/**
 * The cache of the public mart endpoints (App\Controllers\Mart). Each business has a version token in the cache, and
 * every answer of the business is cached under a key with that token in it, so forget() makes all of them stale at
 * once by changing the token; the stale ones expire by their TTL. The business_slug => id lookups are cached too,
 * but a slug that is not found is not, so a new or renamed business shows at once.
 */
class MartCache
{
    private const PREFIX = 'mart_';

    /** How long a version token lives: far longer than any answer */
    private const VERSION_TTL = 30 * 86400;

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly MartConfig $config,
    ) {
    }

    /**
     * The id of the business with this slug, or null when there is none
     * @param string $slug
     * @return int|null
     */
    public function businessIdOf(string $slug): ?int
    {
        $key = $this->slugKey($slug);
        $id  = $this->cache->get($key);
        if (is_int($id)) {
            return $id;
        }
        $row = model(BusinessMasterModel::class)->select('id')->where('business_slug', $slug)->first();
        if ($row === null) {
            return null;
        }
        $this->cache->save($key, (int) $row['id'], $this->config->slugTtl);

        return (int) $row['id'];
    }

    /**
     * The cached answer of $part for these parameters, or the one $build makes, which is then cached
     * @param int                  $businessId
     * @param string               $part   What the answer is: site, artifacts, artifact
     * @param array                $params Everything the answer depends on besides the business
     * @param callable(): ?array   $build  Null is an answer too (nothing found), and is cached as such
     * @return array|null
     */
    public function remember(int $businessId, string $part, array $params, callable $build): ?array
    {
        $key    = self::PREFIX . $businessId . '_' . $this->version($businessId) . '_' . $part . '_' . md5(json_encode($params));
        $cached = $this->cache->get($key);
        if (is_array($cached) && array_key_exists('value', $cached)) {
            return $cached['value'];
        }
        $value = $build();
        $this->cache->save($key, ['value' => $value], $this->config->cacheTtl);

        return $value;
    }

    /**
     * Make every cached answer of the business stale
     * @param int $businessId
     * @return void
     */
    public function forget(int $businessId): void
    {
        $this->cache->save($this->versionKey($businessId), bin2hex(random_bytes(6)), self::VERSION_TTL);
    }

    /**
     * Drop the cached lookup of a slug, as when the business is renamed
     * @param string $slug
     * @return void
     */
    public function forgetSlug(string $slug): void
    {
        $this->cache->delete($this->slugKey($slug));
    }

    private function version(int $businessId): string
    {
        $version = $this->cache->get($this->versionKey($businessId));
        if (is_string($version) && $version !== '') {
            return $version;
        }
        $version = bin2hex(random_bytes(6));
        $this->cache->save($this->versionKey($businessId), $version, self::VERSION_TTL);

        return $version;
    }

    private function versionKey(int $businessId): string
    {
        return self::PREFIX . 'v_' . $businessId;
    }

    /** Hashed, as a slug may hold characters the cache refuses in a key */
    private function slugKey(string $slug): string
    {
        return self::PREFIX . 'slug_' . md5($slug);
    }
}
