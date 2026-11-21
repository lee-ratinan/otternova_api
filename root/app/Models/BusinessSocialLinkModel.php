<?php

namespace App\Models;

use App\Libraries\ActivityLog;
use CodeIgniter\Model;
use Throwable;

/**
 * Model for the business_social_link table: a business's social media accounts, at most one per platform, in the
 * order they are shown. A link is stored as the full https URL of the account, normalised by normalizeUrl(), which
 * also checks that it is on the platform's own site. The whole set is written at once by replaceLinks(), each write
 * logged to log_activity in its transaction.
 */
class BusinessSocialLinkModel extends Model
{
    use PublicIdTrait;
    use TransactionTrait;

    public const TABLE = 'business_social_link';

    /**
     * Platform (a key of listSocialMediaKeys()) => the hosts its accounts are on; a subdomain of one is taken too
     * (www., m.). The business's website is not a link here: it is business_master.contact_website.
     */
    public const PLATFORM_HOSTS = [
        'facebook'  => ['facebook.com', 'fb.com', 'fb.me'],
        'instagram' => ['instagram.com'],
        'twitter-x' => ['x.com', 'twitter.com'],
        'linkedin'  => ['linkedin.com'],
        'youtube'   => ['youtube.com', 'youtu.be'],
        'line'      => ['line.me', 'lin.ee'], // LINE Official Account: line.me/R/ti/p/@id, or a lin.ee short link
        'tiktok'    => ['tiktok.com'],
    ];

    /** The longest profile_url taken, in characters: the column's length */
    public const MAX_URL_LENGTH = 255;

    /** Query parameters dropped from a URL: tracking only, never part of the address of the account */
    private const TRACKING_PARAMETERS = ['fbclid', 'gclid', 'igsh', 'igshid', 'si', 'ref', 'ref_src', 's', 't', 'mibextid', 'is_from_webapp', 'sender_device'];

    protected $table            = self::TABLE;
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [];

    /** public_id: CHAR(8), see PublicIdTrait */
    protected int $publicIdLength = 8;

    // created_at and updated_at are maintained by the database
    protected $useTimestamps = false;

    /**
     * The URL of an account on the platform in its stored form: https, the host in lower case, no port, user,
     * fragment, tracking parameters or trailing slash, and a path to the account rather than the site's home page.
     * A URL given without a scheme is taken as https.
     * @param mixed $platform A key of PLATFORM_HOSTS
     * @param mixed $url
     * @return string|null null when the platform is not supported or the URL is not one of its accounts
     */
    public static function normalizeUrl(mixed $platform, mixed $url): ?string
    {
        if (! is_string($platform) || ! isset(self::PLATFORM_HOSTS[$platform]) || ! is_string($url)) {
            return null;
        }
        $url = trim($url);
        if ($url === '' || preg_match('/\s/', $url) === 1) {
            return null;
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $url) !== 1) {
            $url = 'https://' . $url;
        }
        $parts = parse_url($url);
        if ($parts === false || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return null;
        }

        $host = strtolower($parts['host'] ?? '');
        $onPlatform = false;
        foreach (self::PLATFORM_HOSTS[$platform] as $platformHost) {
            if ($host === $platformHost || str_ends_with($host, '.' . $platformHost)) {
                $onPlatform = true;
                break;
            }
        }
        $path = rtrim($parts['path'] ?? '', '/');
        if (! $onPlatform || $path === '') {
            return null;
        }

        $normalized = 'https://' . $host . $path;
        if (isset($parts['query'])) {
            parse_str($parts['query'], $query);
            $query = array_filter($query, static fn (string $key): bool => ! in_array(strtolower($key), self::TRACKING_PARAMETERS, true)
                && ! str_starts_with(strtolower($key), 'utm_'), ARRAY_FILTER_USE_KEY);
            if ($query !== []) {
                $normalized .= '?' . http_build_query($query);
            }
        }

        return strlen($normalized) <= self::MAX_URL_LENGTH ? $normalized : null;
    }

    /**
     * Check and normalise the links of a request, in the order given
     * @param mixed $links list of {platform, profile_url}
     * @return array{links: array<string, string>, errors: array<int|string, string>} links: platform => normalised URL,
     *         in order; errors: by index in $links, a key of the SocialLink language file (invalidPlatform,
     *         duplicatePlatform, invalidUrl), or ['links' => 'invalidLinks'] when $links is not a list; empty when
     *         every link is good
     */
    public static function normalizeLinks(mixed $links): array
    {
        if (! is_array($links) || ! array_is_list($links)) {
            return ['links' => [], 'errors' => ['links' => 'invalidLinks']];
        }
        $normalized = [];
        $errors     = [];
        foreach ($links as $i => $link) {
            $platform = is_array($link) ? ($link['platform'] ?? null) : null;
            if (! is_string($platform) || ! isset(self::PLATFORM_HOSTS[$platform])) {
                $errors[$i] = 'invalidPlatform';
            } elseif (isset($normalized[$platform])) {
                $errors[$i] = 'duplicatePlatform';
            } elseif (($url = self::normalizeUrl($platform, $link['profile_url'] ?? null)) === null) {
                $errors[$i] = 'invalidUrl';
            } else {
                $normalized[$platform] = $url;
            }
        }

        return ['links' => $normalized, 'errors' => $errors];
    }

    /**
     * The links of the business, in the order they are shown
     * @param int $businessId
     * @return list<array{public_id: string, platform: string, profile_url: string, sort_order: int}>
     */
    public function linksOf(int $businessId): array
    {
        $rows = $this->db->table($this->table)->select('public_id, platform, profile_url, sort_order')
            ->where('business_id', $businessId)
            ->orderBy('sort_order')->orderBy('id')
            ->get()->getResultArray();

        return array_map(static fn (array $row): array => ['sort_order' => (int) $row['sort_order']] + $row, $rows);
    }

    /**
     * Make the business have exactly $links, in that order, in one transaction, logged to log_activity in it: the
     * platforms left out are deleted, the others added or their URL or place changed. A link kept keeps its public_id.
     * @param int                   $businessId
     * @param array<string, string> $links      platform => URL, from normalizeLinks(), in the order shown
     * @param int|null              $actorId    auth_master.id of whoever makes the change
     * @return void
     * @throws Throwable When a write fails; nothing is written then
     */
    public function replaceLinks(int $businessId, array $links, ?int $actorId): void
    {
        $log = new ActivityLog();
        $this->inTransaction(function () use ($businessId, $links, $actorId, $log): void {
            $order = array_flip(array_keys($links));
            $have  = [];
            foreach ($this->db->table($this->table)->where('business_id', $businessId)->get()->getResultArray() as $row) {
                $platform        = $row['platform'];
                $have[$platform] = true;
                if (! isset($links[$platform])) {
                    $this->db->table($this->table)->where('id', $row['id'])->delete();
                    $log->deleted($actorId, 'Removed the ' . $platform . ' link of business ' . $businessId, $this->table, (int) $row['id'], $row);

                    continue;
                }
                $new = ['profile_url' => $links[$platform], 'sort_order' => $order[$platform]];
                if ($row['profile_url'] !== $new['profile_url'] || (int) $row['sort_order'] !== $new['sort_order']) {
                    $this->db->table($this->table)->where('id', $row['id'])->update($new);
                    $log->updated($actorId, 'Changed the ' . $platform . ' link of business ' . $businessId, $this->table, (int) $row['id'], $row, $new);
                }
            }
            foreach ($links as $platform => $url) {
                if (! isset($have[$platform])) {
                    $row = ['public_id' => $this->newPublicId(), 'business_id' => $businessId, 'platform' => $platform,
                        'profile_url' => $url, 'sort_order' => $order[$platform]];
                    $this->db->table($this->table)->insert($row);
                    $log->inserted($actorId, 'Added a ' . $platform . ' link to business ' . $businessId, $this->table, (int) $this->db->insertID(), $row);
                }
            }
        });
    }
}
