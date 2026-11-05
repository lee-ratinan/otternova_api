<?php

namespace App\Commands;

use App\Libraries\SubscriptionBilling;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * php spark billing:run — the scheduled work of the subscriptions (see SubscriptionBilling::runSchedule()): voids
 * unpaid first invoices, ends cancellations at the period end, makes renewal invoices, suspends what is unpaid after
 * the grace days and expires what has been suspended too long. Safe to run again at any time; run it every hour:
 *   0 * * * * cd /path/to/root && php spark billing:run >> writable/logs/billing-run.log 2>&1
 */
class BillingRun extends BaseCommand
{
    protected $group       = 'Billing';
    protected $name        = 'billing:run';
    protected $description = 'Renews, suspends, expires and voids subscriptions and invoices that are due.';
    protected $usage       = 'billing:run';

    /**
     * @param array $params
     * @return int EXIT_SUCCESS, or EXIT_ERROR when the run or one of its subscriptions failed
     */
    public function run(array $params)
    {
        try {
            $counts = (new SubscriptionBilling())->runSchedule();
        } catch (Throwable $e) {
            log_message('error', 'billing:run: {message}', ['message' => $e->getMessage()]);
            CLI::error('billing:run failed: ' . $e->getMessage());

            return EXIT_ERROR;
        }

        $summary = [];
        foreach ($counts as $name => $count) {
            $summary[] = $name . ' ' . $count;
        }
        CLI::write(SubscriptionBilling::now() . ' UTC billing:run ' . implode(', ', $summary));

        return $counts['failed'] > 0 ? EXIT_ERROR : EXIT_SUCCESS;
    }
}
