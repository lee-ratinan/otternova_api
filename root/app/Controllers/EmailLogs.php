<?php

namespace App\Controllers;

use App\Libraries\ApiResponse;
use App\Models\EmailLogModel;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * The email_log log for the Admin app, behind the jwt:S and department:EXE,CXD,ENG filters: the Executive Office,
 * Client Experience to answer "did the customer get the email?", and Engineering to look into problems. It holds
 * addresses and subjects, never the body. Every time is UTC.
 */
class EmailLogs extends BaseController
{
    use BillingRequestTrait;

    /** An email_type as EmailService writes it */
    private const TYPE_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/';

    /**
     * POST /staff/logs/emails { start, length, search, filters: {email_type, status} }
     * One page of the log, newest first. search matches the recipient, where it went and the subject.
     * @return ResponseInterface
     */
    public function emails(): ResponseInterface
    {
        $input = $this->jsonInput();
        if ($input === null) {
            return ApiResponse::error(lang('EmailLog.invalidRequest'), 422);
        }
        [$start, $length] = $this->page($input);
        $filters          = is_array($input['filters'] ?? null) ? $input['filters'] : [];

        $type = $this->text($filters, 'email_type');
        if ($type !== '' && ! preg_match(self::TYPE_PATTERN, $type)) {
            return ApiResponse::error(lang('EmailLog.invalidType'), 422, ['email_type' => lang('EmailLog.invalidType')]);
        }
        $status   = $this->text($filters, 'status');
        $statuses = [EmailLogModel::STATUS_SENT, EmailLogModel::STATUS_FAILED, EmailLogModel::STATUS_SUPPRESSED];
        if ($status !== '' && ! in_array($status, $statuses, true)) {
            return ApiResponse::error(lang('EmailLog.invalidStatus'), 422, ['status' => lang('EmailLog.invalidStatus')]);
        }

        try {
            $data = model(EmailLogModel::class)->listForStaff(
                $start,
                $length,
                $type === '' ? null : $type,
                $status === '' ? null : $status,
                mb_substr($this->text($input, 'search'), 0, 254)
            );
        } catch (Throwable $e) {
            return $this->failed('v1/staff/logs/emails', $e, 'EmailLog.loadFailed');
        }

        return ApiResponse::success($data);
    }

    /**
     * POST /staff/logs/email-types
     * Every email_type the log has rows of, for the type filter
     * @return ResponseInterface
     */
    public function types(): ResponseInterface
    {
        try {
            $types = model(EmailLogModel::class)->loggedTypes();
        } catch (Throwable $e) {
            return $this->failed('v1/staff/logs/email-types', $e, 'EmailLog.loadFailed');
        }

        return ApiResponse::success(['types' => $types]);
    }
}
