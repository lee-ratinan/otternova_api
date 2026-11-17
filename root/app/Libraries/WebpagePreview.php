<?php

namespace App\Libraries;

use Config\Auth;
use RuntimeException;

/**
 * The tokens that let the storefront show a business's own page as it is saved, a draft included, to whoever opens
 * the preview link the Client app made (App\Controllers\Webpages::preview, Mart::webpage). A token names one page of
 * one business and lasts TTL seconds; it is signed with HMAC-SHA256 under the JWT secret, kept apart from the JWTs by
 * the purpose in the signed text. It shows that page only, never lets anything be changed, and is not secret beyond
 * that: anyone given the link may look until it expires.
 */
class WebpagePreview
{
    /** How long a preview link works, in seconds */
    public const TTL = 1800;

    private const PURPOSE = 'webpage-preview';

    /**
     * A token for the page
     * @param int    $businessId
     * @param string $pagePublicId
     * @param int|null $now For tests
     * @return array{token: string, expires_at: int} expires_at a Unix time
     */
    public static function issue(int $businessId, string $pagePublicId, ?int $now = null): array
    {
        $expires = ($now ?? time()) + self::TTL;
        $payload = self::base64Url(json_encode(['b' => $businessId, 'p' => $pagePublicId, 'e' => $expires]));

        return ['token' => $payload . '.' . self::sign($payload), 'expires_at' => $expires];
    }

    /**
     * The page a token is for, when it is genuine, unexpired and for this business
     * @param mixed    $token From the request
     * @param int      $businessId
     * @param int|null $now
     * @return string|null The page's public_id
     */
    public static function pageOf(mixed $token, int $businessId, ?int $now = null): ?string
    {
        if (! is_string($token) || strlen($token) > 512 || substr_count($token, '.') !== 1) {
            return null;
        }
        [$payload, $signature] = explode('.', $token);
        if (! hash_equals(self::sign($payload), $signature)) {
            return null;
        }
        $claims = json_decode((string) base64_decode(strtr($payload, '-_', '+/'), true), true);
        if (! is_array($claims) || ($claims['b'] ?? null) !== $businessId || ! is_string($claims['p'] ?? null) || ! is_int($claims['e'] ?? null)
            || $claims['e'] < ($now ?? time())) {
            return null;
        }

        return $claims['p'];
    }

    private static function sign(string $payload): string
    {
        $secret = config(Auth::class)->jwtSecret;
        if (strlen($secret) < 32) {
            throw new RuntimeException('JWT_SECRET is not set in .env or is shorter than 32 bytes');
        }

        return self::base64Url(hash_hmac('sha256', self::PURPOSE . '|' . $payload, $secret, true));
    }

    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
