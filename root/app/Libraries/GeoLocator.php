<?php

namespace App\Libraries;

use MaxMind\Db\Reader;
use Throwable;

/**
 * Where an IP address is, from the MaxMind GeoLite2-City database that php spark tracker:geoip-update keeps in
 * Config\Tracker::$geoipDatabase. Without the file, every address is unknown, so the tracker works without it.
 * Answers are remembered for the life of the object, as the tracker job sees the same addresses again and again.
 */
class GeoLocator
{
    private ?Reader $reader = null;
    private bool $opened = false;

    /** @var array<string, array|null> */
    private array $seen = [];

    public function __construct(private readonly string $databasePath)
    {
    }

    /**
     * @param string $ip
     * @return array{country_code: string|null, subdivision_code: string|null, city: string|null,
     *               latitude: float|null, longitude: float|null}|null null when unknown
     */
    public function locate(string $ip): ?array
    {
        if (array_key_exists($ip, $this->seen)) {
            return $this->seen[$ip];
        }
        if (count($this->seen) > 10000) {
            $this->seen = [];
        }

        return $this->seen[$ip] = $this->lookUp($ip);
    }

    /**
     * @param string $ip
     * @return array|null
     */
    private function lookUp(string $ip): ?array
    {
        $reader = $this->reader();
        if ($reader === null || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return null;
        }
        try {
            $record = $reader->get($ip);
        } catch (Throwable) {
            return null;
        }
        if (! is_array($record)) {
            return null;
        }

        $country     = $record['country']['iso_code'] ?? null;
        $subdivision = $record['subdivisions'][0]['iso_code'] ?? null;
        $latitude    = $record['location']['latitude'] ?? null;
        $longitude   = $record['location']['longitude'] ?? null;
        $city        = $record['city']['names']['en'] ?? null;

        return [
            'country_code'     => is_string($country) && strlen($country) === 2 ? $country : null,
            // as in business_master.contact_subdivision_code: the country's own code, such as 10 for Bangkok
            'subdivision_code' => is_string($subdivision) ? mb_substr($subdivision, 0, 8) : null,
            'city'             => is_string($city) ? mb_substr($city, 0, 128, 'UTF-8') : null,
            // two decimals, about a kilometre: a city's area is all the database knows anyway
            'latitude'         => is_numeric($latitude) ? round((float) $latitude, 2) : null,
            'longitude'        => is_numeric($longitude) ? round((float) $longitude, 2) : null,
        ];
    }

    private function reader(): ?Reader
    {
        if (! $this->opened) {
            $this->opened = true;
            if (is_file($this->databasePath)) {
                try {
                    $this->reader = new Reader($this->databasePath);
                } catch (Throwable $e) {
                    log_message('error', 'GeoIP database could not be opened: {message}', ['message' => $e->getMessage()]);
                }
            }
        }

        return $this->reader;
    }
}
