<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for the tracker_event_raw table: the events of the tracker script as received by POST /v1/track, until the
 * tracker job merges them into tracker_visit
 */
class TrackerEventRawModel extends Model
{
    public const SITE_WWW  = 'www';
    public const SITE_MART = 'mart';
    public const SITES     = [self::SITE_WWW, self::SITE_MART];

    public const TYPE_PAGEVIEW = 'pageview';
    public const TYPE_ENGAGE   = 'engage';
    public const TYPE_EVENT    = 'event';
    public const TYPES         = [self::TYPE_PAGEVIEW, self::TYPE_ENGAGE, self::TYPE_EVENT];

    /** The steps of the shopping funnel, in order: event_name => funnel_stage of tracker_visit */
    public const FUNNEL = [
        'view_item'        => 1,
        'add_to_cart'      => 2,
        'checkout_start'   => 3,
        'checkout_confirm' => 4,
    ];

    protected $table          = 'tracker_event_raw';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useSoftDeletes = false;
    protected $protectFields  = true;
    protected $allowedFields  = [
        'site',
        'business_id',
        'visitor_id',
        'session_id',
        'event_type',
        'event_name',
        'path',
        'referrer',
        'received_at',
        'occurred_at',
        'ip_address',
        'user_agent',
        'accept_language',
        'payload',
        'processed_at',
    ];

    protected $useTimestamps = false;

    /**
     * Store the events of one batch. Not logged to log_activity: these are no one's changes, and there are many.
     * @param list<array<string, mixed>> $rows Already validated and normalised by App\Controllers\Tracker
     * @return void
     */
    public function store(array $rows): void
    {
        if ($rows !== []) {
            $this->insertBatch($rows);
        }
    }

    /**
     * The id of the business whose mart pages use a slug, cached as Config\Tracker::$businessCacheTtl says
     * @param string $slug
     * @param int    $ttl
     * @return int|null null when no business has it
     */
    public function businessIdForSlug(string $slug, int $ttl): ?int
    {
        $cache = service('cache');
        $key   = 'tracker_business_' . hash('sha256', $slug);
        $found = $cache->get($key);
        if ($found === null) {
            $row   = $this->db->table('business_master')->select('id')->where('business_slug', $slug)->get()->getRowArray();
            $found = $row === null ? 0 : (int) $row['id']; // 0 caches that there is none
            $cache->save($key, $found, $ttl);
        }

        return $found === 0 ? null : (int) $found;
    }
}
