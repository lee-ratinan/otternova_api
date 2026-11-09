<?php

namespace App\Controllers;

use App\Models\TrackerEventRawModel;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\I18n\Time;
use Config\Tracker as TrackerConfig;
use Throwable;

/**
 * The visitor tracker of the website (www) and the marketplace (mart): no sign-in needed. public/js/tracker.js
 * sends batches of events here, which go to the tracker_event_raw table as they are, for the tracker job to merge
 * into visits. The checks that keep junk out are in App\Libraries\TrackerGuard.
 */
class Tracker extends BaseController
{
    private const ID_PATTERN   = '/^[0-9A-Za-z_-]{16}$/';
    private const NAME_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/';
    private const PROP_PATTERN = '/^[a-z][a-z0-9_]{0,31}$/';
    private const UTM_KEYS     = ['source', 'medium', 'campaign', 'term', 'content'];

    /**
     * POST /track
     * { site: www|mart, business_slug?, visitor_id, session_id, language?, timezone?, screen?: [w, h],
     *   events: [{ type: pageview|engage|event, path, ago_ms?, title?, query?, referrer?, utm?: {...},
     *              visible_ms?, scroll_pct?, name?, props?: {...} }] }
     * The script sends it with navigator.sendBeacon as text/plain, so no CORS preflight is needed and the answer
     * is never read. Hence always 204 with no body, also for a batch dropped as junk, so a bot learns nothing.
     * business_slug is for the mart pages of a business; leave it out on the marketplace's own pages.
     * @return ResponseInterface
     */
    public function collect(): ResponseInterface
    {
        $config = config(TrackerConfig::class);

        try {
            $rows = $this->rows($config);
            if ($rows !== []) {
                model(TrackerEventRawModel::class)->store($rows);
            }
        } catch (Throwable $e) {
            log_message('error', 'Tracker events could not be saved: {message}', ['message' => $e->getMessage()]);
        }

        return $this->response->setStatusCode(204)->setBody('');
    }

    /**
     * The rows of the batch for tracker_event_raw; [] when the whole batch is to be dropped
     * @param TrackerConfig $config
     * @return list<array<string, mixed>>
     */
    private function rows(TrackerConfig $config): array
    {
        $guard     = service('trackerGuard');
        $userAgent = $this->request->getHeaderLine('User-Agent');
        if (! $guard->isAllowedOrigin($this->request->getHeaderLine('Origin')) || $guard->isBot($userAgent)) {
            return [];
        }

        $body = (string) $this->request->getBody();
        if ($body === '' || strlen($body) > $config->maxBodyBytes) {
            return [];
        }
        $input = json_decode($body, true, 8);
        if (! is_array($input)) {
            return [];
        }

        $site      = $input['site'] ?? null;
        $visitorId = $input['visitor_id'] ?? null;
        $sessionId = $input['session_id'] ?? null;
        $events    = $input['events'] ?? null;
        if (! in_array($site, TrackerEventRawModel::SITES, true) || ! $this->isId($visitorId) || ! $this->isId($sessionId)
            || ! is_array($events) || $events === [] || ! array_is_list($events)) {
            return [];
        }

        $businessId = null;
        $slug       = $input['business_slug'] ?? null;
        if ($site === TrackerEventRawModel::SITE_MART && $slug !== null && $slug !== '') {
            if (! is_string($slug) || strlen($slug) > 128) {
                return [];
            }
            $businessId = model(TrackerEventRawModel::class)->businessIdForSlug($slug, $config->businessCacheTtl);
            if ($businessId === null) {
                return [];
            }
        }

        // counted only now, so that a stray request does not use up a visitor's allowance
        $ip = $this->request->getIPAddress();
        if ($guard->isThrottled($ip)) {
            return [];
        }

        $now     = Time::now('UTC');
        $context = array_filter([
            'language' => $this->line($input['language'] ?? null, 16),
            'timezone' => $this->line($input['timezone'] ?? null, 64),
            'screen'   => $this->screen($input['screen'] ?? null),
        ], static fn ($value): bool => $value !== null);
        $common = [
            'site'            => $site,
            'business_id'     => $businessId,
            'visitor_id'      => $visitorId,
            'session_id'      => $sessionId,
            'received_at'     => $now->toDateTimeString(),
            'ip_address'      => $ip,
            'user_agent'      => $this->line($userAgent, 255),
            'accept_language' => $this->line($this->request->getHeaderLine('Accept-Language'), 64),
            'processed_at'    => null,
        ];

        $rows = [];
        foreach (array_slice($events, 0, $config->maxEvents) as $event) {
            $row = is_array($event) ? $this->event($event, $context, $config) : null;
            if ($row === null) {
                continue;
            }
            $agoSeconds = intdiv($this->int($event['ago_ms'] ?? 0, 0, $config->maxEventAgeMs), 1000);
            $row['occurred_at'] = $now->subSeconds($agoSeconds)->toDateTimeString();
            $row['payload']     = $row['payload'] === [] ? null
                : json_encode($row['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $rows[] = $common + $row;
        }

        return $rows;
    }

    /**
     * One event of the batch, as the columns of its row that depend on it
     * @param array         $event
     * @param array         $context language, timezone and screen of the batch, kept with each pageview
     * @param TrackerConfig $config
     * @return array{event_type: string, event_name: string|null, path: string, referrer: string|null, payload: array}|null
     *         null when the event is to be dropped
     */
    private function event(array $event, array $context, TrackerConfig $config): ?array
    {
        $type = $event['type'] ?? null;
        $path = $this->path($event['path'] ?? null);
        if (! in_array($type, TrackerEventRawModel::TYPES, true) || $path === null) {
            return null;
        }

        $name     = null;
        $referrer = null;
        $payload  = [];
        switch ($type) {
            case TrackerEventRawModel::TYPE_PAGEVIEW:
                $referrer = $this->url($event['referrer'] ?? null);
                $payload  = $context + array_filter([
                    'title' => $this->line($event['title'] ?? null, 200),
                    'query' => $this->line($event['query'] ?? null, 512),
                    'utm'   => $this->utm($event['utm'] ?? null),
                ], static fn ($value): bool => $value !== null);
                break;

            case TrackerEventRawModel::TYPE_ENGAGE:
                $payload = [
                    'visible_ms' => $this->int($event['visible_ms'] ?? 0, 0, $config->maxVisibleMs),
                    'scroll_pct' => $this->int($event['scroll_pct'] ?? 0, 0, 100),
                ];
                break;

            case TrackerEventRawModel::TYPE_EVENT:
                $name = $event['name'] ?? null;
                if (! is_string($name) || preg_match(self::NAME_PATTERN, $name) !== 1) {
                    return null;
                }
                $props = $this->props($event['props'] ?? null, $config);
                if ($props !== []) {
                    $payload['props'] = $props;
                }
                break;
        }

        return ['event_type' => $type, 'event_name' => $name, 'path' => $path, 'referrer' => $referrer, 'payload' => $payload];
    }

    /**
     * @param mixed $value
     * @return bool Whether it is a visitor or session id the script makes
     */
    private function isId(mixed $value): bool
    {
        return is_string($value) && preg_match(self::ID_PATTERN, $value) === 1;
    }

    /**
     * A page's path: starting with /, without its query string or fragment, at most 512 characters
     * @param mixed $value
     * @return string|null
     */
    private function path(mixed $value): ?string
    {
        $value = $this->line($value, 2048);
        if ($value === null || ! str_starts_with($value, '/') || str_starts_with($value, '//')) {
            return null;
        }
        $value = preg_replace('/[?#].*$/s', '', $value) ?? '';

        return mb_substr($value, 0, 512, 'UTF-8');
    }

    /**
     * An http(s) URL, at most 512 characters
     * @param mixed $value
     * @return string|null
     */
    private function url(mixed $value): ?string
    {
        $value = $this->line($value, 512);
        if ($value === null || ! preg_match('#^https?://[^/\s]+#i', $value)) {
            return null;
        }

        return $value;
    }

    /**
     * The utm_* parameters of the page's address, without their utm_ prefix
     * @param mixed $value
     * @return array<string, string>|null
     */
    private function utm(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }
        $utm = [];
        foreach (self::UTM_KEYS as $key) {
            $text = $this->line($value[$key] ?? null, 128);
            if ($text !== null) {
                $utm[$key] = $text;
            }
        }

        return $utm === [] ? null : $utm;
    }

    /**
     * @param mixed $value [width, height] in CSS pixels
     * @return string|null Such as 390x844
     */
    private function screen(mixed $value): ?string
    {
        if (! is_array($value) || count($value) !== 2 || ! array_is_list($value)) {
            return null;
        }
        [$width, $height] = [$this->int($value[0], 0, 99999), $this->int($value[1], 0, 99999)];

        return $width > 0 && $height > 0 ? $width . 'x' . $height : null;
    }

    /**
     * The props of a custom event: up to Config\Tracker::$maxProps names of lower-case letters, digits and _, each
     * with a text, number or true/false; anything else is left out
     * @param mixed         $value
     * @param TrackerConfig $config
     * @return array<string, string|int|float|bool>
     */
    private function props(mixed $value, TrackerConfig $config): array
    {
        if (! is_array($value) || array_is_list($value)) {
            return [];
        }
        $props = [];
        foreach ($value as $key => $item) {
            if (count($props) >= $config->maxProps) {
                break;
            }
            if (! is_string($key) || preg_match(self::PROP_PATTERN, $key) !== 1) {
                continue;
            }
            if (is_string($item)) {
                $item = $this->line($item, $config->maxPropLength);
            } elseif (is_float($item) && ! is_finite($item)) {
                $item = null;
            } elseif (! is_int($item) && ! is_float($item) && ! is_bool($item)) {
                $item = null;
            }
            if ($item !== null) {
                $props[$key] = $item;
            }
        }

        return $props;
    }

    /**
     * A text on one line: UTF-8, no control characters, trimmed and cut to a length
     * @param mixed $value
     * @param int   $maxLength In characters
     * @return string|null null when missing, empty, not a string or not UTF-8
     */
    private function line(mixed $value, int $maxLength): ?string
    {
        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8')) {
            return null;
        }
        $value = trim(preg_replace('/[\p{Cc}\p{Cf}]+/u', ' ', $value) ?? '');

        return $value === '' ? null : mb_substr($value, 0, $maxLength, 'UTF-8');
    }

    /**
     * A whole number within bounds; the lower bound when it is no number
     * @param mixed $value
     * @param int   $min
     * @param int   $max
     * @return int
     */
    private function int(mixed $value, int $min, int $max): int
    {
        if (! is_int($value) && ! (is_float($value) && is_finite($value))) {
            return $min;
        }

        return (int) max($min, min($max, $value));
    }
}
