<?php

namespace App\Commands;

use App\Libraries\TrackerRollup;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use CodeIgniter\I18n\Time;
use Config\Tracker;
use Throwable;

/**
 * php spark tracker:rollup — rebuilds tracker_daily for the last Config\Tracker::$rollupDays days, today and tomorrow
 * (UTC dates; each business's days are local, see App\Libraries\TrackerRollup), or the one day of --day; then zeroes
 * old IP addresses and deletes expired visits.
 * Safe to run again at any time. Hourly keeps today's numbers fresh, a few minutes after tracker:process:
 *   7 * * * * cd /path/to/root && php spark tracker:rollup >> writable/logs/tracker-rollup.log 2>&1
 * To fill in past days: php spark tracker:rollup --day 2026-11-01
 */
class TrackerRollupCommand extends BaseCommand
{
    protected $group       = 'Tracker';
    protected $name        = 'tracker:rollup';
    protected $description = 'Rebuilds the daily tracker totals, zeroes old IP addresses and deletes expired visits.';
    protected $usage       = 'tracker:rollup [--day YYYY-MM-DD]';
    protected $options     = ['--day' => 'Rebuild this UTC day only'];

    /**
     * @param array $params
     * @return int EXIT_SUCCESS, or EXIT_ERROR when it failed
     */
    public function run(array $params)
    {
        $config = config(Tracker::class);
        $day    = CLI::getOption('day');
        if ($day !== null) {
            $parsed = is_string($day) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $day, new \DateTimeZone('UTC')) : false;
            if ($parsed === false || $parsed->format('Y-m-d') !== $day) {
                CLI::error('tracker:rollup: --day must be a date as YYYY-MM-DD');

                return EXIT_ERROR;
            }
            $days = [$day];
        } else {
            $today = Time::today('UTC');
            $days  = [];
            // today east of UTC is already tomorrow in UTC
            for ($i = max(1, $config->rollupDays); $i >= -1; $i--) {
                $days[] = $today->subDays($i)->toDateString();
            }
        }

        $rollup = new TrackerRollup($config);
        try {
            foreach ($days as $one) {
                $rows = $rollup->rollupDay($one);
                CLI::write(Time::now('UTC')->toDateTimeString() . ' UTC tracker:rollup ' . $one . ': ' . $rows . ' rows');
            }
            $anonymised = $rollup->anonymiseIps();
            $purged     = $rollup->purge();
        } catch (Throwable $e) {
            log_message('error', 'tracker:rollup: {message}', ['message' => $e->getMessage()]);
            CLI::error('tracker:rollup failed: ' . $e->getMessage());

            return EXIT_ERROR;
        }
        CLI::write(Time::now('UTC')->toDateTimeString() . ' UTC tracker:rollup zeroed ' . $anonymised . ' IP addresses, deleted '
            . $purged['visits'] . ' visits and ' . $purged['visitors'] . ' visitors');

        return EXIT_SUCCESS;
    }
}
