<?php

namespace App\Controllers;

use App\Libraries\ApiResponse;
use App\Models\LogActivityModel;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * The log_activity log for the Admin app, behind the jwt:S and department:EXE filters: the log holds the changes to
 * every table, the ledger and the accounts included, so it is the Executive Office's only. Every time is UTC.
 */
class ActivityLogs extends BaseController
{
    use BillingRequestTrait;

    /** A table_involved as the API writes it */
    private const TABLE_PATTERN = '/^[a-z][a-z0-9_]{0,127}$/';

    /**
     * POST /staff/logs/activities { start, length, search, filters: {table_involved, table_id_updated} }
     * One page of the log, newest first. table_id_updated needs table_involved: the two are one record's history.
     * search matches the summary, the changes and the actor's name and email, and only within one record's
     * history; it is ignored otherwise, so it never scans the whole log.
     * @return ResponseInterface
     */
    public function activities(): ResponseInterface
    {
        $input = $this->jsonInput();
        if ($input === null) {
            return ApiResponse::error(lang('LogActivity.invalidRequest'), 422);
        }
        [$start, $length] = $this->page($input);
        $filters          = is_array($input['filters'] ?? null) ? $input['filters'] : [];

        $table = $this->text($filters, 'table_involved');
        if ($table !== '' && ! preg_match(self::TABLE_PATTERN, $table)) {
            return ApiResponse::error(lang('LogActivity.invalidTable'), 422, ['table_involved' => lang('LogActivity.invalidTable')]);
        }
        $recordId = $this->text($filters, 'table_id_updated');
        if ($recordId !== '') {
            if (filter_var($recordId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4294967295]]) === false) {
                return ApiResponse::error(lang('LogActivity.invalidRecordId'), 422, ['table_id_updated' => lang('LogActivity.invalidRecordId')]);
            }
            if ($table === '') {
                return ApiResponse::error(lang('LogActivity.recordNeedsTable'), 422, ['table_involved' => lang('LogActivity.recordNeedsTable')]);
            }
        }

        try {
            $data = model(LogActivityModel::class)->listForStaff(
                $start,
                $length,
                $table === '' ? null : $table,
                $recordId === '' ? null : (int) $recordId,
                $this->text($input, 'search')
            );
        } catch (Throwable $e) {
            return $this->failed('v1/staff/logs/activities', $e, 'LogActivity.loadFailed');
        }

        return ApiResponse::success($data);
    }

    /**
     * POST /staff/logs/tables
     * Every table the log has rows of, by name, for the table filter
     * @return ResponseInterface
     */
    public function tables(): ResponseInterface
    {
        try {
            $tables = model(LogActivityModel::class)->loggedTables();
        } catch (Throwable $e) {
            return $this->failed('v1/staff/logs/tables', $e, 'LogActivity.loadFailed');
        }

        return ApiResponse::success(['tables' => $tables]);
    }
}
