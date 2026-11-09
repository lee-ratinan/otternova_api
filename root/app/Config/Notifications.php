<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * In-app notifications (notification_master); see App\Libraries\Notifier
 */
class Notifications extends BaseConfig
{
    /** Days a read notification is kept before `php spark notifications:cleanup` deletes it; unread ones are kept */
    public int $readRetentionDays = 30;

    /** How many notifications POST .../notifications/latest gives, for the header's dropdown */
    public int $latestLimit = 10;

    /** The most POST .../notifications/list gives at once */
    public int $maxPageLength = 50;
}
