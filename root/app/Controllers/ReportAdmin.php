<?php

namespace App\Controllers;

use App\Libraries\ApiResponse;
use App\Models\BusinessMasterModel;
use App\Models\ModerationReportModel;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * The Admin app's review of the reports mart visitors send about a business or its products and services
 * (App\Models\ModerationReportModel). Reading them: any staff member; resolving them: the Executive Office and Client
 * Experience (Config\Routes), who act on a report with ModerationAdmin (flag, block, remove) and mark it here.
 */
class ReportAdmin extends BaseController
{
    use BillingRequestTrait;

    /** The longest staff_note */
    private const NOTE_MAX_LENGTH = 2000;

    /**
     * POST /staff/businesses/reports { business_slug }
     * Every report of the business, newest first: new, filtered, dismissed and actioned. The reporter's email address
     * is for the staff only; reports from one sender share their sender_key.
     * @return ResponseInterface
     */
    public function list(): ResponseInterface
    {
        $slug = $this->text($this->jsonInput() ?? [], 'business_slug');
        if ($slug === '') {
            return ApiResponse::error(lang('Report.invalidRequest'), 422);
        }
        try {
            $business = model(BusinessMasterModel::class)->select('id')->where('business_slug', $slug)->first();
            if ($business === null) {
                return ApiResponse::error(lang('Report.businessNotFound'), 404);
            }
            $rows = model(ModerationReportModel::class)->listOf((int) $business['id']);
        } catch (Throwable $e) {
            return $this->failed('v1/staff/businesses/reports', $e, 'Report.loadFailed');
        }

        return ApiResponse::success($rows);
    }

    /**
     * POST /staff/reports/resolve { public_id, report_status: N|S|D|A, staff_note? }
     * Mark a report dismissed (D), actioned (A), filtered as spam (S), or new again (N), with a note for the staff.
     * { public_id, report_status }
     * @return ResponseInterface
     */
    public function resolve(): ResponseInterface
    {
        $input = $this->jsonInput();
        if ($input === null) {
            return ApiResponse::error(lang('Report.invalidRequest'), 422);
        }
        $status = strtoupper($this->text($input, 'report_status'));
        $note   = $this->text($input, 'staff_note');
        $errors = [];
        if (! in_array($status, ModerationReportModel::STATUSES, true)) {
            $errors['report_status'] = lang('Report.invalidStatus');
        }
        if (mb_strlen($note) > self::NOTE_MAX_LENGTH) {
            $errors['staff_note'] = lang('Report.noteTooLong', [self::NOTE_MAX_LENGTH]);
        }
        if ($errors !== []) {
            return ApiResponse::error(lang('Report.invalidFields'), 422, $errors);
        }
        try {
            $model  = model(ModerationReportModel::class);
            $report = $model->findByPublicId($input['public_id'] ?? null);
            if ($report === null) {
                return ApiResponse::error(lang('Report.notFound'), 404);
            }
            $model->resolve($report, $status, $note === '' ? null : $note, $this->actorId());
        } catch (Throwable $e) {
            return $this->failed('v1/staff/reports/resolve', $e, 'Report.saveFailed');
        }

        return ApiResponse::success(['public_id' => $report['public_id'], 'report_status' => $status], 200, lang('Report.resolved.' . $status));
    }
}
