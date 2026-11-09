<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use CodeIgniter\I18n\Time;
use Config\Tracker;
use MaxMind\Db\Reader;
use PharData;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

/**
 * php spark tracker:geoip-update — downloads MaxMind's GeoLite2-City database to Config\Tracker::$geoipDatabase,
 * which tracker:process reads to locate visitors. Needs a free MaxMind account: MAXMIND_ACCOUNT_ID and
 * MAXMIND_LICENSE_KEY in .env. The new file only replaces the old one once it opens, so a failed download changes
 * nothing. MaxMind updates it twice a week; once a week is plenty:
 *   15 4 * * 3 cd /path/to/root && php spark tracker:geoip-update >> writable/logs/tracker-geoip.log 2>&1
 */
class TrackerGeoipUpdate extends BaseCommand
{
    protected $group       = 'Tracker';
    protected $name        = 'tracker:geoip-update';
    protected $description = 'Downloads the MaxMind GeoLite2-City database the tracker locates visitors with.';
    protected $usage       = 'tracker:geoip-update';

    /**
     * @param array $params
     * @return int EXIT_SUCCESS, or EXIT_ERROR when it failed
     */
    public function run(array $params)
    {
        $config = config(Tracker::class);
        if ($config->maxmindAccountId === '' || $config->maxmindLicenseKey === '') {
            CLI::error('tracker:geoip-update: set MAXMIND_ACCOUNT_ID and MAXMIND_LICENSE_KEY in .env first');

            return EXIT_ERROR;
        }

        $work = WRITEPATH . 'cache/geoip-' . bin2hex(random_bytes(6));
        try {
            mkdir($work, 0700, true);
            $archive = $work . '/GeoLite2-City.tar.gz';
            $this->download($config, $archive);
            $mmdb = $this->extract($archive, $work);

            // a file that does not open as a MaxMind database never replaces a good one
            (new Reader($mmdb))->close();
            $target = $config->geoipDatabase;
            if (! is_dir(dirname($target))) {
                mkdir(dirname($target), 0755, true);
            }
            if (! rename($mmdb, $target)) {
                throw new RuntimeException('could not move the database to ' . $target);
            }
        } catch (Throwable $e) {
            log_message('error', 'tracker:geoip-update: {message}', ['message' => $e->getMessage()]);
            CLI::error('tracker:geoip-update failed: ' . $e->getMessage());

            return EXIT_ERROR;
        } finally {
            $this->remove($work);
        }
        CLI::write(Time::now('UTC')->toDateTimeString() . ' UTC tracker:geoip-update saved ' . $config->geoipDatabase
            . ' (' . round(filesize($config->geoipDatabase) / 1048576, 1) . ' MB)');

        return EXIT_SUCCESS;
    }

    private function download(Tracker $config, string $archive): void
    {
        $response = service('curlrequest', ['timeout' => 300, 'http_errors' => false])->get($config->geoipDownloadUrl, [
            'auth'            => [$config->maxmindAccountId, $config->maxmindLicenseKey],
            // MaxMind redirects to its storage; curl does not pass the credentials on to another host
            'allow_redirects' => ['max' => 5, 'strict' => true, 'protocols' => ['https']],
        ]);
        $status = $response->getStatusCode();
        if ($status !== 200) {
            throw new RuntimeException('MaxMind answered HTTP ' . $status . ($status === 401 ? ' (check the account id and license key)' : ''));
        }
        if (file_put_contents($archive, $response->getBody()) === false || filesize($archive) === 0) {
            throw new RuntimeException('the download was empty');
        }
    }

    /**
     * @return string The path of the .mmdb file taken out of the archive
     */
    private function extract(string $archive, string $work): string
    {
        $tar = (new PharData($archive))->decompress(); // GeoLite2-City.tar
        foreach (new RecursiveIteratorIterator($tar) as $file) {
            if (str_ends_with($file->getFilename(), '.mmdb')) {
                $target = $work . '/GeoLite2-City.mmdb';
                if (file_put_contents($target, $file->getContent()) === false) {
                    throw new RuntimeException('could not write ' . $target);
                }

                return $target;
            }
        }

        throw new RuntimeException('the download held no .mmdb file');
    }

    private function remove(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/*') ?: [] as $file) {
            is_dir($file) ? $this->remove($file) : unlink($file);
        }
        rmdir($dir);
    }
}
