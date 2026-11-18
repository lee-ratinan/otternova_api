<?php

namespace App\Controllers;

use App\Libraries\ApiResponse;
use App\Libraries\EngineeringDashboard;
use App\Libraries\ExecutiveDashboard;
use App\Libraries\FinanceDashboard;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * The figures behind the Admin app's dashboards, one endpoint per department's dashboard
 */
class Dashboards extends BaseController
{
    /**
     * POST /staff/dashboard/executive
     * Businesses, revenue, plans, the newest businesses, the inbox, Finance's queue, the ledger and the latest
     * activity; see App\Libraries\ExecutiveDashboard. Behind the jwt:S and department:EXE filters.
     * @return ResponseInterface
     */
    public function executive(): ResponseInterface
    {
        try {
            $data = (new ExecutiveDashboard(db_connect()))->summary();
        } catch (Throwable $e) {
            log_message('error', 'v1/staff/dashboard/executive: {message}', ['message' => $e->getMessage()]);

            return ApiResponse::error(lang('SubscriptionAdmin.loadFailed'), 500);
        }

        return ApiResponse::success($data);
    }

    /**
     * POST /staff/dashboard/engineering
     * The API's errors and failed emails from its log, the tracker's queue and the platform's use; see
     * App\Libraries\EngineeringDashboard. Behind the jwt:S and department:EXE,ENG filters.
     * @return ResponseInterface
     */
    public function engineering(): ResponseInterface
    {
        try {
            $data = (new EngineeringDashboard(db_connect()))->summary();
        } catch (Throwable $e) {
            log_message('error', 'v1/staff/dashboard/engineering: {message}', ['message' => $e->getMessage()]);

            return ApiResponse::error(lang('SubscriptionAdmin.loadFailed'), 500);
        }

        return ApiResponse::success($data);
    }

    /**
     * POST /staff/dashboard/finance
     * What waits for the Finance staff, this month's collections, renewals, price changes and the ledger's accounts;
     * see App\Libraries\FinanceDashboard. Behind the jwt:S and department:EXE,FIN filters.
     * @return ResponseInterface
     */
    public function finance(): ResponseInterface
    {
        try {
            $data = (new FinanceDashboard(db_connect()))->summary();
        } catch (Throwable $e) {
            log_message('error', 'v1/staff/dashboard/finance: {message}', ['message' => $e->getMessage()]);

            return ApiResponse::error(lang('SubscriptionAdmin.loadFailed'), 500);
        }

        return ApiResponse::success($data);
    }
}
