<?php

namespace App\Commands;

use App\Libraries\TrackerMerger;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use CodeIgniter\I18n\Time;
use Config\Tracker;
use Throwable;

/**
 * php spark tracker:process — merges the tracker's raw events into visits, pages and visitors (see
 * App\Libraries\TrackerMerger), round after round until none is left or $maxSeconds have passed, then deletes the raw
 * events merged more than Config\Tracker::$rawRetentionDays ago. Two runs never merge at once (a MySQL lock), so it
 * is safe to run often; every 5 minutes:
 *   *\/5 * * * * cd /path/to/root && php spark tracker:process >> writable/logs/tracker-process.log 2>&1
 */
class TrackerProcess extends BaseCommand
{
    private const LOCK = 'otternova_tracker_process';

    protected $group       = 'Tracker';
    protected $name        = 'tracker:process';
    protected $description = 'Merges the raw tracker events into visits, pages and visitors.';
    protected $usage       = 'tracker:process [--max-seconds 240]';
    protected $options     = ['--max-seconds' => 'Stop starting new rounds after this many seconds (default 240)'];

    /**
     * @param array $params
     * @return int EXIT_SUCCESS, or EXIT_ERROR when it failed
     */
    public function run(array $params)
    {
        $maxSeconds = max(1, (int) (CLI::getOption('max-seconds') ?? 240));
        $db         = db_connect();
        $mysql      = $db->DBDriver === 'MySQLi';
        if ($mysql && (int) ($db->query('SELECT GET_LOCK(?, 0) AS got', [self::LOCK])->getRow()->got ?? 0) !== 1) {
            CLI::write(Time::now('UTC')->toDateTimeString() . ' UTC tracker:process skipped: another run is going');

            return EXIT_SUCCESS;
        }

        $merger  = new TrackerMerger(config(Tracker::class), service('geoLocator'), $db);
        $started = microtime(true);
        $merged  = 0;
        try {
            do {
                $count = $merger->processBatch();
                $merged += $count;
            } while ($count > 0 && microtime(true) - $started < $maxSeconds);
            $deleted = $merger->deleteProcessed();
        } catch (Throwable $e) {
            log_message('error', 'tracker:process: {message}', ['message' => $e->getMessage()]);
            CLI::error('tracker:process failed after merging ' . $merged . ' events: ' . $e->getMessage());

            return EXIT_ERROR;
        } finally {
            if ($mysql) {
                $db->query('SELECT RELEASE_LOCK(?)', [self::LOCK]);
            }
        }
        CLI::write(Time::now('UTC')->toDateTimeString() . ' UTC tracker:process merged ' . $merged . ' events, deleted ' . $deleted . ' old ones');

        return EXIT_SUCCESS;
    }
}
