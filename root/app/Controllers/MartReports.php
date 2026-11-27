<?php

namespace App\Controllers;

use App\Libraries\ApiResponse;
use App\Libraries\ContactFormGuard;
use App\Libraries\MartReportGuard;
use App\Models\ArtifactMasterModel;
use App\Models\BusinessMasterModel;
use App\Models\ModerationEventModel;
use App\Models\ModerationReportModel;
use CodeIgniter\HTTP\ResponseInterface;
use Config\App;
use Config\Auth;
use Config\MartReportForm;
use Throwable;

/**
 * The report form of otternova_mart: no sign-in needed. A visitor reports a business, or one of its products or
 * services, to Otternova, for its staff to review (App\Models\ModerationReportModel); the business is never told.
 * The checks are App\Libraries\MartReportGuard's. A report about an audience the business declared (LGBT-friendly,
 * naturist, adults only) is kept but filtered as spam, unless it is about child safety; the form warns the visitor
 * of it beforehand (declared_audiences of token()), and it is answered as any other, so a bad-faith reporter learns
 * nothing. otternova_mart calls these from its server and passes on the visitor's address in X-Forwarded-For, as for
 * MartMessages.
 * Any business but one Otternova removed takes reports: a closed or blocked one too, as its orders go on.
 */
class MartReports extends BaseController
{
    use ContactInputTrait;

    /**
     * GET /mart/report-token?business_slug=
     * A single-use token for one report of the business, as for MartMessages::token(), and the audiences the business
     * declared (MartReportGuard::AUDIENCE_ constants: lgbt, naturist, adult), for the form's warning that reports
     * about them are ignored.
     * { form_token, expires_in, declared_audiences }
     * @return ResponseInterface
     */
    public function token(): ResponseInterface
    {
        $slug = $this->request->getGet('business_slug');

        return $this->forBusiness('v1/mart/report-token', is_string($slug) ? $slug : null, static function (array $business): ResponseInterface {
            return ApiResponse::success([
                'form_token'         => service('martReportGuard')->forBusiness((int) $business['id'])->issueToken(),
                'expires_in'         => config(MartReportForm::class)->tokenMaxAge,
                'declared_audiences' => MartReportGuard::audiencesOf($business),
            ]);
        });
    }

    /**
     * POST /mart/report { business_slug, artifact_public_id?, reason_code, details?, email?, locale, form_token, website? }
     * Stores a report. reason_code is one of ModerationReportModel::REASONS; details are needed for OFFENSIVE and
     * OTHER; email is the visitor's, given only to be contacted. website is the honeypot.
     * A honeypot hit gets the same 201 as a stored report, so a bot learns nothing; so does a filtered one.
     * 400 for a form token that is not this business's, used, too new or stale (Report.formExpired: reload); 404 for
     * a product or service not the business's; 422 with errors by field; 429 for too many reports from the sender
     * (Report.tooMany) or of the business today (Report.businessBusy).
     * @return ResponseInterface
     */
    public function submit(): ResponseInterface
    {
        try {
            $input = $this->request->getJSON(true);
        } catch (Throwable) {
            $input = null;
        }
        if (! is_array($input)) {
            return ApiResponse::error(lang('Report.invalid'), 400);
        }
        $slug = $input['business_slug'] ?? null;

        return $this->forBusiness('v1/mart/report', is_string($slug) ? $slug : null, function (array $business) use ($input): ResponseInterface {
            return $this->receive($business, $input);
        });
    }

    /**
     * The checks and the storing of submit()
     * @param array $business
     * @param array $input
     * @return ResponseInterface
     * @throws Throwable When the report cannot be stored
     */
    private function receive(array $business, array $input): ResponseInterface
    {
        $config     = config(MartReportForm::class);
        $businessId = (int) $business['id'];
        $guard      = service('martReportGuard')->forBusiness($businessId);

        $honeypot = $input[$config->honeypotField] ?? '';
        if (! is_string($honeypot) || trim($honeypot) !== '') {
            return $this->received();
        }
        $token = $input['form_token'] ?? null;
        if ($guard->checkToken($token) !== ContactFormGuard::TOKEN_OK) {
            return ApiResponse::error(lang('Report.formExpired'), 400);
        }

        [$row, $errors] = $this->validated($input, $config);
        if ($errors !== []) {
            return ApiResponse::error(lang('Report.invalid'), 422, $errors);
        }
        $artifactId = null;
        $artifact   = $this->cleanText($input['artifact_public_id'] ?? null, true);
        if ($artifact !== '') {
            $found = model(ArtifactMasterModel::class)->select('id, moderation_status')
                ->where('business_id', $businessId)->where('public_id', $artifact)->first();
            if ($found === null || $found['moderation_status'] === ModerationEventModel::MODERATION_REMOVED) {
                return ApiResponse::error(lang('Report.artifactNotFound'), 404);
            }
            $artifactId = (int) $found['id'];
        }

        $ip      = $this->request->getIPAddress();
        $blocked = $guard->isSenderBlocked($row['reporter_email'], null, $ip);
        if ($blocked !== null) {
            return ApiResponse::error(lang($blocked === MartReportGuard::BLOCKED_BUSINESS ? 'Report.businessBusy' : 'Report.tooMany'), 429);
        }
        $guard->recordSender($row['reporter_email'], null, $ip);
        $guard->consumeToken($token);

        // the same report from the same address: kept, filtered, so the staff can see it was sent again
        $ipHash    = hash_hmac('sha256', $ip, hash_hmac('sha256', 'mart-report-ip', config(Auth::class)->jwtSecret));
        $duplicate = $guard->isDuplicate($ipHash, $artifactId . "\n" . $row['reason_code'] . "\n" . $row['details']);
        $reason    = $duplicate ? ModerationReportModel::FILTER_DUPLICATE : $this->filterReason($guard, $business, $row);

        model(ModerationReportModel::class)->receive($row + [
            'business_id'   => $businessId,
            'artifact_id'   => $artifactId,
            'ip_hash'       => $ipHash,
            'report_status' => $reason === null ? ModerationReportModel::STATUS_NEW : ModerationReportModel::STATUS_FILTERED,
            'filter_reason' => $reason,
        ]);
        $guard->remember($ipHash, $artifactId . "\n" . $row['reason_code'] . "\n" . $row['details']);

        return $this->received();
    }

    /**
     * Why a report is filtered as spam, or null: about an audience the business declared (never for child safety), or
     * link-stuffed or with spam words (ContactFormGuard::spamReason())
     */
    private function filterReason(MartReportGuard $guard, array $business, array $row): ?string
    {
        $details = (string) $row['details'];
        if ($row['reason_code'] !== ModerationReportModel::REASON_CHILD_SAFETY
            && $guard->audienceReason(MartReportGuard::audiencesOf($business), $details) !== null) {
            return ModerationReportModel::FILTER_AUDIENCE;
        }

        return $details === '' ? null : $guard->spamReason('', '', $details);
    }

    /**
     * The fields of the form, trimmed and checked
     * @param array          $input
     * @param MartReportForm $config
     * @return array{0: array{reason_code: string, details: string|null, reporter_email: string|null, locale: string}, 1: array<string, string>}
     */
    private function validated(array $input, MartReportForm $config): array
    {
        $errors = [];
        $reason = strtoupper($this->cleanText($input['reason_code'] ?? null, true));
        if (! in_array($reason, ModerationReportModel::REASONS, true)) {
            $errors['reason_code'] = lang('Report.reasonRequired');
        }
        $details = $this->cleanText($input['details'] ?? null, false);
        if ($details === '' && in_array($reason, ModerationReportModel::DETAILS_REQUIRED, true)) {
            $errors['details'] = lang('Report.detailsRequired');
        } elseif (mb_strlen($details, 'UTF-8') > $config->detailsMaxLength) {
            $errors['details'] = lang('Report.detailsTooLong', [$config->detailsMaxLength]);
        }
        $email = mb_strtolower($this->cleanText($input['email'] ?? null, true), 'UTF-8');
        if ($email !== '' && (strlen($email) > $config->emailMaxLength || ! filter_var($email, FILTER_VALIDATE_EMAIL))) {
            $errors['email'] = lang('Report.emailInvalid');
        }
        $locale = is_string($input['locale'] ?? null) ? $input['locale'] : '';
        if (! in_array($locale, config(App::class)->supportedLocales, true)) {
            $errors['locale'] = lang('Report.localeInvalid');
        }

        return [[
            'reason_code'    => $reason,
            'details'        => $details === '' ? null : $details,
            'reporter_email' => $email === '' ? null : $email,
            'locale'         => $locale,
        ], $errors];
    }

    /**
     * Finds the business of the slug, then answers with $answer unless Otternova removed it; a failure is logged and
     * answered 500
     * @param string                             $endpoint
     * @param string|null                        $slug
     * @param callable(array): ResponseInterface $answer Given business_master's id, moderation_status and audiences
     * @return ResponseInterface
     */
    private function forBusiness(string $endpoint, ?string $slug, callable $answer): ResponseInterface
    {
        if ($slug === null || trim($slug) === '') {
            return ApiResponse::error(lang('Mart.slugRequired'), 400);
        }
        try {
            $businessId = service('martCache')->businessIdOf(trim($slug));
            $business   = $businessId === null ? null : model(BusinessMasterModel::class)
                ->select('id, moderation_status, lgbt_audience, naturist_audience, age_restricted')->find($businessId);
            if ($business === null || $business['moderation_status'] === ModerationEventModel::MODERATION_REMOVED) {
                return ApiResponse::error(lang('Mart.businessNotFound'), 404);
            }

            return $answer($business);
        } catch (Throwable $e) {
            log_message('error', $endpoint . ': {message}', ['message' => $e->getMessage()]);

            return ApiResponse::error(lang('Report.saveFailed'), 500);
        }
    }

    /**
     * @return ResponseInterface
     */
    private function received(): ResponseInterface
    {
        return ApiResponse::success(null, 201, lang('Report.received'));
    }
}
