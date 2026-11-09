<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Settings for the visitor tracker of the website and the marketplace (POST /v1/track, public/js/tracker.js);
 * see App\Controllers\Tracker and App\Libraries\TrackerGuard
 */
class Tracker extends BaseConfig
{
    /**
     * The pages allowed to send events, as scheme://host[:port]; from LINK_WWW and LINK_MART in .env.
     * When empty, as in a development copy with neither set, any origin is let in.
     * @var list<string>
     */
    public array $allowedOrigins = [];

    /** A batch bigger than this many bytes is dropped whole; the script sends far smaller ones */
    public int $maxBodyBytes = 16384;

    /** Events kept from one batch; the rest are dropped */
    public int $maxEvents = 50;

    /** Batches accepted per IP address within a minute; the script sends a few per page */
    public int $maxPerIpPerMinute = 120;

    /** An event the script says happened longer ago than this many milliseconds is dated this long ago */
    public int $maxEventAgeMs = HOUR * 1000;

    /** The most visible time one engage event can add, in milliseconds */
    public int $maxVisibleMs = 30 * MINUTE * 1000;

    /** Seconds a business slug's id (or that there is none) is cached for */
    public int $businessCacheTtl = 10 * MINUTE;

    /** Custom event props kept per event, and the longest text value kept */
    public int $maxProps = 10;
    public int $maxPropLength = 128;

    /** User agents of crawlers and headless browsers, whose batches are dropped (case-insensitive) */
    public string $botPattern = '/bot\b|crawl|spider|slurp|scrape|headless|lighthouse|pagespeed|preview|facebookexternalhit|embedly|curl|wget|python-requests|go-http-client|httpclient|java\//i';

    // --- the tracker jobs: php spark tracker:process, tracker:rollup and tracker:geoip-update ------------------

    /** Raw events tracker:process merges per round; it goes on until none is left */
    public int $processBatchSize = 5000;

    /** Days a processed raw event is kept, so that a bad merge can be run again; then tracker:process deletes it */
    public int $rawRetentionDays = 3;

    /** Days after which tracker:rollup zeroes the last part of a visit's IP address (IPv4 /24, IPv6 /48) */
    public int $ipAnonymiseDays = 30;

    /** Days a visit, its pages and an idle visitor are kept; tracker_daily is kept for good */
    public int $visitRetentionDays = 395;

    /** Days back tracker:rollup rebuilds each run, so that visits merged late (open across midnight) are counted */
    public int $rollupDays = 2;

    /** The MaxMind GeoLite2-City database, which tracker:geoip-update downloads; no locations while it is missing */
    public string $geoipDatabase = WRITEPATH . 'geoip/GeoLite2-City.mmdb';

    /** The MaxMind account of the download, from MAXMIND_ACCOUNT_ID and MAXMIND_LICENSE_KEY in .env */
    public string $maxmindAccountId  = '';
    public string $maxmindLicenseKey = '';

    /** Where tracker:geoip-update downloads the database from */
    public string $geoipDownloadUrl = 'https://download.maxmind.com/geoip/databases/GeoLite2-City/download?suffix=tar.gz';

    /** Referrer hosts that make a visit's source_type search or social; a host matches itself and its subdomains */
    public array $searchHosts = [
        'google.com', 'google.co.th', 'bing.com', 'yahoo.com', 'duckduckgo.com', 'baidu.com', 'yandex.ru',
        'naver.com', 'ecosia.org', 'search.brave.com',
    ];
    public array $socialHosts = [
        'facebook.com', 'fb.com', 'instagram.com', 'line.me', 'twitter.com', 'x.com', 't.co', 'tiktok.com',
        'youtube.com', 'linkedin.com', 'lnkd.in', 'pinterest.com', 'reddit.com', 'threads.net',
    ];

    public function __construct()
    {
        parent::__construct();

        $this->maxmindAccountId  = (string) env('MAXMIND_ACCOUNT_ID', $this->maxmindAccountId);
        $this->maxmindLicenseKey = (string) env('MAXMIND_LICENSE_KEY', $this->maxmindLicenseKey);
        $this->geoipDatabase     = (string) env('GEOIP_DATABASE', $this->geoipDatabase);

        foreach (['LINK_WWW', 'LINK_MART'] as $name) {
            $origin = self::originOf((string) env($name, ''));
            if ($origin !== null && ! in_array($origin, $this->allowedOrigins, true)) {
                $this->allowedOrigins[] = $origin;
            }
        }
    }

    /**
     * The origin of a URL, as a browser sends it in the Origin header
     * @param string $url
     * @return string|null scheme://host[:port], lower case; null when it is no http(s) URL
     */
    public static function originOf(string $url): ?string
    {
        $parts = parse_url(trim($url));
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])
            || ! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return null;
        }

        return strtolower($parts['scheme'] . '://' . $parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }
}
