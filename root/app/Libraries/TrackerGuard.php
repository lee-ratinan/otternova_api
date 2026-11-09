<?php

namespace App\Libraries;

use CodeIgniter\Cache\CacheInterface;
use Config\Tracker;

/**
 * Keeps junk out of the visitor tracker (POST /v1/track), which anyone can call:
 * - only the website and marketplace pages (Config\Tracker::$allowedOrigins) may send, by their Origin header;
 * - crawlers and headless browsers are dropped by their user agent;
 * - batches per IP address are counted in a one-minute window.
 * Counts live in the cache, under hashed keys, like App\Libraries\LoginThrottle.
 */
class TrackerGuard
{
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly Tracker $config,
    ) {
    }

    /**
     * @param string $origin The Origin header; '' when missing
     * @return bool
     */
    public function isAllowedOrigin(string $origin): bool
    {
        if ($this->config->allowedOrigins === []) {
            return true;
        }

        return in_array(strtolower(rtrim($origin, '/')), $this->config->allowedOrigins, true);
    }

    /**
     * @param string $userAgent '' when missing, which no browser sends
     * @return bool
     */
    public function isBot(string $userAgent): bool
    {
        return trim($userAgent) === '' || preg_match($this->config->botPattern, $userAgent) === 1;
    }

    /**
     * Count a batch from an IP address, and say whether it is over the limit of the current minute
     * @param string   $ip
     * @param int|null $now Unix seconds; for tests
     * @return bool
     */
    public function isThrottled(string $ip, ?int $now = null): bool
    {
        $minute = intdiv($now ?? time(), MINUTE);
        $key    = 'tracker_ip_' . $minute . '_' . hash('sha256', $ip);
        $count  = (int) ($this->cache->get($key) ?? 0) + 1;
        $this->cache->save($key, $count, 2 * MINUTE);

        return $count > $this->config->maxPerIpPerMinute;
    }
}
