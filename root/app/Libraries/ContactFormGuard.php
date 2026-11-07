<?php

namespace App\Libraries;

use CodeIgniter\Cache\CacheInterface;
use Config\ContactForm;

/**
 * Keeps bots and floods out of the public contact form, in layers:
 * - a form token from GET /v1/contact-form-token, signed so it cannot be made up, single use, and refused when sent
 *   back sooner than a person could fill in the form (Config\ContactForm::$tokenMinAge) or once stale;
 * - a honeypot field the form hides, which only a bot fills in (checked by App\Controllers\Contact);
 * - a count of messages per IP address and per email address in a fixed window;
 * - the same message from the same address is stored once;
 * - content checks that mark link-stuffed messages as spam instead of new.
 * Counts live in the cache, under hashed keys, like App\Libraries\LoginThrottle.
 */
class ContactFormGuard
{
    public const TOKEN_OK      = 'ok';
    public const TOKEN_INVALID = 'invalid';
    public const TOKEN_TOO_NEW = 'too_new';
    public const TOKEN_EXPIRED = 'expired';
    public const TOKEN_USED    = 'used';

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly ContactForm $config,
        private readonly string $secret,
    ) {
    }

    /**
     * A new form token: "<issued at>.<nonce>.<signature>"
     * @param int|null $now Unix seconds; for tests
     * @return string
     */
    public function issueToken(?int $now = null): string
    {
        $payload = ($now ?? time()) . '.' . bin2hex(random_bytes(16));

        return $payload . '.' . $this->sign($payload);
    }

    /**
     * Whether a form token may be used now; one of the TOKEN_ constants. Does not use it up: see consumeToken().
     * @param mixed $token
     * @return string
     */
    public function checkToken(mixed $token): string
    {
        $parts = is_string($token) ? explode('.', $token) : [];
        if (count($parts) !== 3 || ! ctype_digit($parts[0]) || ! preg_match('/^[0-9a-f]{32}$/', $parts[1])
            || ! hash_equals($this->sign($parts[0] . '.' . $parts[1]), $parts[2])) {
            return self::TOKEN_INVALID;
        }
        $age = time() - (int) $parts[0];
        if ($age < $this->config->tokenMinAge) {
            return self::TOKEN_TOO_NEW;
        }
        if ($age > $this->config->tokenMaxAge) {
            return self::TOKEN_EXPIRED;
        }
        if ($this->cache->get($this->key('token', $parts[1])) !== null) {
            return self::TOKEN_USED;
        }

        return self::TOKEN_OK;
    }

    /**
     * Use up a token checkToken() accepted, until it would have expired anyway
     * @param string $token
     * @return void
     */
    public function consumeToken(string $token): void
    {
        [$issuedAt, $nonce] = explode('.', $token);
        $ttl = max(1, (int) $issuedAt + $this->config->tokenMaxAge - time());
        $this->cache->save($this->key('token', $nonce), 1, $ttl);
    }

    /**
     * @param string $email
     * @param string $ip
     * @return bool
     */
    public function isBlocked(string $email, string $ip): bool
    {
        return $this->count('ip', $ip) >= $this->config->maxPerIp
            || $this->count('email', $email) >= $this->config->maxPerEmail;
    }

    /**
     * Count a submission that passed the token and field checks, whether it is stored or not
     * @param string $email
     * @param string $ip
     * @return void
     */
    public function record(string $email, string $ip): void
    {
        $this->increment('ip', $ip);
        $this->increment('email', $email);
    }

    /**
     * Whether the same address sent the same message within the duplicate window
     * @param string $email
     * @param string $message
     * @return bool
     */
    public function isDuplicate(string $email, string $message): bool
    {
        return $this->cache->get($this->duplicateKey($email, $message)) !== null;
    }

    /**
     * Remember a stored message, so isDuplicate() knows it within the duplicate window
     * @param string $email
     * @param string $message
     * @return void
     */
    public function remember(string $email, string $message): void
    {
        $this->cache->save($this->duplicateKey($email, $message), 1, $this->config->duplicateWindow);
    }

    /**
     * @param string $email
     * @param string $message Compared ignoring case and spacing
     * @return string
     */
    private function duplicateKey(string $email, string $message): string
    {
        $normalised = mb_strtolower(preg_replace('/\s+/u', ' ', trim($message)) ?? '', 'UTF-8');

        return $this->key('dup', $email . "\n" . $normalised);
    }

    /**
     * Why the content looks like spam, or null when it does not
     * @param string $name
     * @param string $subject
     * @param string $message
     * @return string|null
     */
    public function spamReason(string $name, string $subject, string $message): ?string
    {
        $link = '~https?://|www\.~i';
        if (preg_match($link, $name)) {
            return 'link_in_name';
        }
        if (preg_match('~\[url[=\]]|<a\s~i', $subject . ' ' . $message)) {
            return 'markup_link';
        }
        if (preg_match_all($link, $subject . ' ' . $message) > $this->config->maxLinks) {
            return 'too_many_links';
        }

        return null;
    }

    /**
     * @param string $payload
     * @return string
     */
    private function sign(string $payload): string
    {
        // a key of its own, derived from the app secret, so a form token can never pass as anything else
        return hash_hmac('sha256', $payload, hash_hmac('sha256', 'contact-form-token', $this->secret));
    }

    /**
     * @param string $scope
     * @param string $value
     * @return int
     */
    private function count(string $scope, string $value): int
    {
        $entry = $this->cache->get($this->key($scope, $value));

        return is_array($entry) && $entry['until'] > time() ? $entry['count'] : 0;
    }

    /**
     * @param string $scope
     * @param string $value
     * @return void
     */
    private function increment(string $scope, string $value): void
    {
        $key   = $this->key($scope, $value);
        $entry = $this->cache->get($key);
        if (! is_array($entry) || $entry['until'] <= time()) {
            $entry = ['count' => 0, 'until' => time() + $this->config->throttleWindow];
        }
        $entry['count']++;
        $this->cache->save($key, $entry, max(1, $entry['until'] - time()));
    }

    /**
     * Hashed, so the key holds no reserved cache characters and no plain email address
     * @param string $scope
     * @param string $value
     * @return string
     */
    private function key(string $scope, string $value): string
    {
        return 'contact_' . $scope . '_' . hash('sha256', strtolower(trim($value)));
    }
}
