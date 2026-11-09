<?php

namespace App\Libraries;

use App\Models\TrackerEventRawModel;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\I18n\Time;
use Config\Tracker;
use Throwable;

/**
 * Merges the raw events of the tracker (tracker_event_raw) into one row per visit (tracker_visit), per page of a
 * visit (tracker_visit_page) and per visitor (tracker_visitor); run by php spark tracker:process.
 * A session's events can come in many batches and be merged over several runs: each run adds its events' counts to
 * what is there, takes the earliest start and latest end, and the furthest funnel step, so the outcome is the same
 * as merging them all at once. Each round of raw events is merged in one transaction, together with marking them
 * processed, so a failed round is merged again in full by the next run.
 */
class TrackerMerger
{
    private const SEARCH_ENGINE = '/(^|\.)(google|bing|yahoo|yandex)\.(com?\.)?[a-z]{2,3}$/';

    private BaseConnection $db;
    private UserAgent $agent;

    public function __construct(
        private readonly Tracker $config,
        private readonly GeoLocator $geo,
        ?BaseConnection $db = null,
    ) {
        $this->db    = $db ?? db_connect();
        $this->agent = new UserAgent();
    }

    /**
     * Merge the oldest round of raw events not merged yet
     * @param Time|null $now For tests
     * @return int The raw events merged; 0 when none was left
     * @throws Throwable When the round could not be merged; it stays unmerged
     */
    public function processBatch(?Time $now = null): int
    {
        $now  = ($now ?? Time::now('UTC'))->toDateTimeString();
        $rows = $this->db->table('tracker_event_raw')
            ->where('processed_at', null)
            ->orderBy('id')
            ->limit(max(1, $this->config->processBatchSize))
            ->get()->getResultArray();
        if ($rows === []) {
            return 0;
        }

        $sessions = [];
        foreach ($rows as $row) {
            $row['business_id'] = (int) ($row['business_id'] ?? 0);
            $row['payload']     = is_string($row['payload']) ? (json_decode($row['payload'], true) ?: []) : [];
            $sessions[$row['site'] . '|' . $row['business_id'] . '|' . $row['session_id']][] = $row;
        }

        $this->db->resetTransStatus()->transException(true)->transBegin();
        try {
            $visitors = [];
            foreach ($sessions as $events) {
                usort($events, static fn (array $a, array $b): int => [$a['occurred_at'], (int) $a['id']] <=> [$b['occurred_at'], (int) $b['id']]);
                $visit = $this->mergeVisit($events);
                $this->mergePages((int) $visit['id'], $events);
                $this->collectVisitor($visitors, $visit, $events);
            }
            foreach ($visitors as $visitor) {
                $this->mergeVisitor($visitor);
            }
            foreach (array_chunk(array_column($rows, 'id'), 1000) as $ids) {
                $this->db->table('tracker_event_raw')->whereIn('id', $ids)->update(['processed_at' => $now]);
            }
            $this->db->transCommit();
        } catch (Throwable $e) {
            $this->db->transRollback();
            $this->db->resetTransStatus();

            throw $e;
        } finally {
            $this->db->transException(false);
        }

        return count($rows);
    }

    /**
     * Delete the raw events merged longer ago than Config\Tracker::$rawRetentionDays
     * @param Time|null $now For tests
     * @return int The rows deleted
     */
    public function deleteProcessed(?Time $now = null): int
    {
        $before = ($now ?? Time::now('UTC'))->subDays(max(0, $this->config->rawRetentionDays))->toDateTimeString();
        $this->db->table('tracker_event_raw')->where('processed_at IS NOT NULL')->where('processed_at <', $before)->delete();

        return $this->db->affectedRows();
    }

    /**
     * Add one session's new events to its visit, made if it is not there yet
     * @param list<array> $events In the order they happened
     * @return array The visit row as it is now, and new => whether it was made now, pageviews_added
     */
    private function mergeVisit(array $events): array
    {
        $first     = $events[0];
        $last      = $events[count($events) - 1];
        $pageviews = array_values(array_filter($events, static fn (array $e): bool => $e['event_type'] === TrackerEventRawModel::TYPE_PAGEVIEW));
        $landing   = $pageviews[0] ?? $first;

        $added = ['pageviews' => count($pageviews), 'events' => 0, 'visible_ms' => 0, 'funnel' => 0, 'order_value' => null, 'currency' => null];
        foreach ($events as $event) {
            if ($event['event_type'] === TrackerEventRawModel::TYPE_ENGAGE) {
                $added['visible_ms'] += (int) ($event['payload']['visible_ms'] ?? 0);
            } elseif ($event['event_type'] === TrackerEventRawModel::TYPE_EVENT) {
                $added['events']++;
                $added['funnel'] = max($added['funnel'], TrackerEventRawModel::FUNNEL[$event['event_name']] ?? 0);
                if ($event['event_name'] === 'checkout_confirm') {
                    $props = $event['payload']['props'] ?? [];
                    if (isset($props['value']) && is_numeric($props['value']) && $props['value'] >= 0) {
                        $added['order_value'] = ($added['order_value'] ?? 0) + (float) $props['value'];
                    }
                    if (isset($props['currency']) && is_string($props['currency']) && preg_match('/^[A-Z]{3}$/', $props['currency'])) {
                        $added['currency'] = $props['currency'];
                    }
                }
            }
        }

        $key   = ['site' => $first['site'], 'business_id' => $first['business_id'], 'session_id' => $first['session_id']];
        $visit = $this->db->table('tracker_visit')->where($key)->get()->getRowArray();

        if ($visit === null) {
            $context  = $landing['payload'];
            $utm      = is_array($context['utm'] ?? null) ? $context['utm'] : [];
            $referrer = $landing['referrer'];
            $location = $this->geo->locate($first['ip_address']) ?? [];
            $row      = $key + [
                'visitor_id'       => $first['visitor_id'],
                'started_at'       => $first['occurred_at'],
                'ended_at'         => $last['occurred_at'],
                'duration_s'       => (int) round($added['visible_ms'] / 1000),
                'pageviews'        => $added['pageviews'],
                'events'           => $added['events'],
                'is_bounce'        => $added['pageviews'] <= 1 && $added['events'] === 0 ? 1 : 0,
                'entry_path'       => $landing['path'],
                'exit_path'        => $last['path'],
                'referrer_url'     => $referrer,
                'referrer_host'    => $this->host($referrer),
                'utm_source'       => $utm['source'] ?? null,
                'utm_medium'       => $utm['medium'] ?? null,
                'utm_campaign'     => $utm['campaign'] ?? null,
                'source_type'      => $this->sourceType($referrer, $utm),
                'ip_address'       => $first['ip_address'],
                'country_code'     => $location['country_code'] ?? null,
                'subdivision_code' => $location['subdivision_code'] ?? null,
                'city'             => $location['city'] ?? null,
                'latitude'         => $location['latitude'] ?? null,
                'longitude'        => $location['longitude'] ?? null,
                'language'         => $this->language($context['language'] ?? null, $first['accept_language']),
                'screen'           => $context['screen'] ?? null,
                'timezone'         => $context['timezone'] ?? null,
                'funnel_stage'     => $added['funnel'],
                'order_value'      => $added['order_value'],
                'order_currency'   => $added['currency'],
            ] + $this->device((string) $first['user_agent']);
            $this->db->table('tracker_visit')->insert($row);

            return ['id' => (int) $this->db->insertID(), 'new' => true, 'pageviews_added' => $added['pageviews']] + $row;
        }

        $pageviewCount = (int) $visit['pageviews'] + $added['pageviews'];
        $eventCount    = (int) $visit['events'] + $added['events'];
        $changes       = [
            'started_at'   => min($visit['started_at'], $first['occurred_at']),
            'ended_at'     => max($visit['ended_at'], $last['occurred_at']),
            'duration_s'   => (int) $visit['duration_s'] + (int) round($added['visible_ms'] / 1000),
            'pageviews'    => $pageviewCount,
            'events'       => $eventCount,
            'is_bounce'    => $pageviewCount <= 1 && $eventCount === 0 ? 1 : 0,
            'funnel_stage' => max((int) $visit['funnel_stage'], $added['funnel']),
        ];
        if ($first['occurred_at'] < $visit['started_at'] && $pageviews !== []) {
            $changes['entry_path'] = $landing['path'];
        }
        if ($last['occurred_at'] >= $visit['ended_at']) {
            $changes['exit_path'] = $last['path'];
        }
        if ($added['order_value'] !== null) {
            $changes['order_value'] = round((float) ($visit['order_value'] ?? 0) + $added['order_value'], 2);
        }
        if ($added['currency'] !== null) {
            $changes['order_currency'] = $added['currency'];
        }
        // the first batch of a visit can lack these, when it only held a custom event
        foreach (['screen', 'timezone'] as $field) {
            if ($visit[$field] === null && isset($landing['payload'][$field])) {
                $changes[$field] = $landing['payload'][$field];
            }
        }
        $this->db->table('tracker_visit')->where('id', $visit['id'])->update($changes);

        return ['new' => false, 'pageviews_added' => $added['pageviews']] + $changes + $visit;
    }

    /**
     * Add one session's new events to the pages of its visit
     * @param int         $visitId
     * @param list<array> $events
     * @return void
     */
    private function mergePages(int $visitId, array $events): void
    {
        $pages = [];
        foreach ($events as $event) {
            $page = $pages[$event['path']] ?? ['views' => 0, 'visible_ms' => 0, 'max_scroll_pct' => 0, 'first_viewed_at' => $event['occurred_at']];
            if ($event['event_type'] === TrackerEventRawModel::TYPE_PAGEVIEW) {
                $page['views']++;
            } elseif ($event['event_type'] === TrackerEventRawModel::TYPE_ENGAGE) {
                $page['visible_ms'] += (int) ($event['payload']['visible_ms'] ?? 0);
                $page['max_scroll_pct'] = max($page['max_scroll_pct'], (int) ($event['payload']['scroll_pct'] ?? 0));
            }
            $pages[$event['path']] = $page;
        }

        $stored = [];
        foreach ($this->db->table('tracker_visit_page')->where('visit_id', $visitId)->whereIn('path', array_map('strval', array_keys($pages)))->get()->getResultArray() as $row) {
            $stored[$row['path']] = $row;
        }

        foreach ($pages as $path => $page) {
            $path = (string) $path;
            $old  = $stored[$path] ?? null;
            if ($old === null) {
                $this->db->table('tracker_visit_page')->insert([
                    'visit_id'        => $visitId,
                    'path'            => $path,
                    'views'           => $page['views'],
                    'visible_s'       => (int) round($page['visible_ms'] / 1000),
                    'max_scroll_pct'  => min(100, $page['max_scroll_pct']),
                    'first_viewed_at' => $page['first_viewed_at'],
                ]);
            } else {
                $this->db->table('tracker_visit_page')->where('id', $old['id'])->update([
                    'views'           => (int) $old['views'] + $page['views'],
                    'visible_s'       => (int) $old['visible_s'] + (int) round($page['visible_ms'] / 1000),
                    'max_scroll_pct'  => min(100, max((int) $old['max_scroll_pct'], $page['max_scroll_pct'])),
                    'first_viewed_at' => min($old['first_viewed_at'], $page['first_viewed_at']),
                ]);
            }
        }
    }

    /**
     * Gather what a merged visit adds to its visitor, so each visitor is written once per round
     * @param array       $visitors By site|business_id|visitor_id
     * @param array       $visit    From mergeVisit()
     * @param list<array> $events
     * @return void
     */
    private function collectVisitor(array &$visitors, array $visit, array $events): void
    {
        $key = $visit['site'] . '|' . $visit['business_id'] . '|' . $visit['visitor_id'];
        $now = $visitors[$key] ?? [
            'site'        => $visit['site'],
            'business_id' => (int) $visit['business_id'],
            'visitor_id'  => $visit['visitor_id'],
            'visits'      => 0,
            'pageviews'   => 0,
            'first_seen'  => $events[0]['occurred_at'],
            'last_seen'   => $events[0]['occurred_at'],
            'first_visit' => null,
            'country'     => null,
        ];
        if ($visit['new']) {
            $now['visits']++;
            if ($now['first_visit'] === null || $visit['started_at'] < $now['first_visit']['started_at']) {
                $now['first_visit'] = $visit;
            }
        }
        $now['pageviews'] += $visit['pageviews_added'];
        $now['first_seen'] = min($now['first_seen'], $events[0]['occurred_at']);
        if ($events[count($events) - 1]['occurred_at'] >= $now['last_seen']) {
            $now['last_seen'] = $events[count($events) - 1]['occurred_at'];
            $now['country']   = $visit['country_code'] ?? $now['country'];
        }
        $visitors[$key] = $now;
    }

    /**
     * @param array $visitor From collectVisitor()
     * @return void
     */
    private function mergeVisitor(array $visitor): void
    {
        $key    = ['site' => $visitor['site'], 'business_id' => $visitor['business_id'], 'visitor_id' => $visitor['visitor_id']];
        $stored = $this->db->table('tracker_visitor')->where($key)->get()->getRowArray();

        if ($stored === null) {
            $first = $visitor['first_visit'];
            $this->db->table('tracker_visitor')->insert($key + [
                'first_seen_at'       => $visitor['first_seen'],
                'last_seen_at'        => $visitor['last_seen'],
                'visits'              => max(1, $visitor['visits']),
                'pageviews'           => $visitor['pageviews'],
                'first_referrer_host' => $first['referrer_host'] ?? null,
                'first_utm_source'    => $first['utm_source'] ?? null,
                'first_utm_medium'    => $first['utm_medium'] ?? null,
                'first_utm_campaign'  => $first['utm_campaign'] ?? null,
                'last_country_code'   => $visitor['country'],
            ]);

            return;
        }

        $changes = [
            'first_seen_at' => min($stored['first_seen_at'], $visitor['first_seen']),
            'visits'        => (int) $stored['visits'] + $visitor['visits'],
            'pageviews'     => (int) $stored['pageviews'] + $visitor['pageviews'],
        ];
        if ($visitor['last_seen'] >= $stored['last_seen_at']) {
            $changes['last_seen_at'] = $visitor['last_seen'];
            if ($visitor['country'] !== null) {
                $changes['last_country_code'] = $visitor['country'];
            }
        }
        $this->db->table('tracker_visitor')->where('id', $stored['id'])->update($changes);
    }

    /**
     * device_type, browser and os of a user agent
     * @param string $userAgent
     * @return array{device_type: string|null, browser: string|null, os: string|null}
     */
    private function device(string $userAgent): array
    {
        if (trim($userAgent) === '') {
            return ['device_type' => null, 'browser' => null, 'os' => null];
        }
        $this->agent->parse($userAgent);

        $type = match (true) {
            $this->agent->isRobot() || preg_match($this->config->botPattern, $userAgent) === 1 => 'bot',
            preg_match('/iPad|Tablet|PlayBook|Silk|Android(?!.*Mobile)/i', $userAgent) === 1    => 'tablet',
            $this->agent->isMobile() || preg_match('/Mobi/i', $userAgent) === 1                  => 'mobile',
            default                                                                            => 'desktop',
        };
        $browser  = $this->agent->getBrowser();
        $platform = $this->agent->getPlatform();

        return [
            'device_type' => $type,
            'browser'     => $browser === '' ? null : mb_substr($browser, 0, 64),
            'os'          => $platform === '' || $platform === 'Unknown Platform' ? null : mb_substr($platform, 0, 64),
        ];
    }

    /**
     * direct, search, social, referral or campaign
     * @param string|null $referrer
     * @param array       $utm
     * @return string
     */
    private function sourceType(?string $referrer, array $utm): string
    {
        if (isset($utm['source']) || isset($utm['medium']) || isset($utm['campaign'])) {
            return 'campaign';
        }
        $host = $this->host($referrer);
        if ($host === null) {
            return 'direct';
        }
        if (preg_match(self::SEARCH_ENGINE, $host) === 1 || $this->hostIn($host, $this->config->searchHosts)) {
            return 'search';
        }

        return $this->hostIn($host, $this->config->socialHosts) ? 'social' : 'referral';
    }

    /**
     * @param string       $host
     * @param list<string> $hosts
     * @return bool Whether it is one of them or a subdomain of one
     */
    private function hostIn(string $host, array $hosts): bool
    {
        foreach ($hosts as $candidate) {
            if ($host === $candidate || str_ends_with($host, '.' . $candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string|null $url
     * @return string|null Its host in lower case, without www.
     */
    private function host(?string $url): ?string
    {
        $host = $url === null ? null : parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return null;
        }
        $host = strtolower($host);

        return mb_substr(str_starts_with($host, 'www.') ? substr($host, 4) : $host, 0, 255);
    }

    /**
     * The browser's language: what the script read, or else the first of the Accept-Language header
     * @param mixed       $fromScript
     * @param string|null $acceptLanguage
     * @return string|null Such as th-TH
     */
    private function language(mixed $fromScript, ?string $acceptLanguage): ?string
    {
        $language = is_string($fromScript) && $fromScript !== '' ? $fromScript : trim(explode(';', explode(',', (string) $acceptLanguage)[0])[0]);

        return preg_match('/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})*$/', $language) === 1 ? mb_substr($language, 0, 16) : null;
    }
}
