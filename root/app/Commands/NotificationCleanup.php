<?php

namespace App\Commands;

use App\Models\NotificationMasterModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use CodeIgniter\I18n\Time;
use Config\Notifications;
use Throwable;

/**
 * php spark notifications:cleanup — deletes the notifications read more than Config\Notifications::$readRetentionDays
 * ago; unread ones are kept. Safe to run again at any time; run it once a day:
 *   30 3 * * * cd /path/to/root && php spark notifications:cleanup >> writable/logs/notifications-cleanup.log 2>&1
 */
class NotificationCleanup extends BaseCommand
{
    protected $group       = 'Notifications';
    protected $name        = 'notifications:cleanup';
    protected $description = 'Deletes read notifications older than the configured number of days.';
    protected $usage       = 'notifications:cleanup';

    /**
     * @param array $params
     * @return int EXIT_SUCCESS, or EXIT_ERROR when it failed
     */
    public function run(array $params)
    {
        $days   = max(1, config(Notifications::class)->readRetentionDays);
        $before = Time::now('UTC')->subDays($days)->toDateTimeString();
        try {
            $count = model(NotificationMasterModel::class)->deleteReadBefore($before);
        } catch (Throwable $e) {
            log_message('error', 'notifications:cleanup: {message}', ['message' => $e->getMessage()]);
            CLI::error('notifications:cleanup failed: ' . $e->getMessage());

            return EXIT_ERROR;
        }
        CLI::write(Time::now('UTC')->toDateTimeString() . ' UTC notifications:cleanup deleted ' . $count . ' read before ' . $before);

        return EXIT_SUCCESS;
    }
}
