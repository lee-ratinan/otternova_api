<?php

namespace App\Controllers;

use App\Libraries\ApiResponse;
use App\Libraries\SubscriptionBilling;
use App\Models\LegalDocumentModel;
use CodeIgniter\HTTP\ResponseInterface;
use Config\App;
use Throwable;

/**
 * The Terms of Service and Privacy Policy for the Admin app, behind the jwt:S filter: the versions and who accepted
 * them (department:EXE,CXD, Client Experience for customers' questions), and publishing or cancelling a version
 * (department:EXE only). A version cannot be edited once published: a change is a new version. Every time is UTC;
 * times in the body carry their offset ("2026-12-01T00:00:00+07:00").
 */
class LegalAdmin extends BaseController
{
    use BillingRequestTrait;

    /** The language every version must have, the one shown when there is none in the reader's */
    private const REQUIRED_LOCALE = LegalDocumentModel::FALLBACK_LOCALE;

    /**
     * POST /staff/legal/versions
     * Every version, newest first, with state (S scheduled, C current, E ended), locales and acceptances
     * @return ResponseInterface {rows: [...]}
     */
    public function versions(): ResponseInterface
    {
        try {
            $rows = model(LegalDocumentModel::class)->listVersions(SubscriptionBilling::now());
        } catch (Throwable $e) {
            return $this->failed('v1/staff/legal/versions', $e, 'Legal.loadFailed');
        }

        return ApiResponse::success(['rows' => $rows]);
    }

    /**
     * POST /staff/legal/version { public_id }
     * One version with all its texts
     * @return ResponseInterface {version: {..., texts: [{locale, body, body_sha256, change_summary}]}}
     */
    public function version(): ResponseInterface
    {
        $input = $this->jsonInput() ?? [];
        try {
            $version = model(LegalDocumentModel::class)->versionForStaff($this->text($input, 'public_id'), SubscriptionBilling::now());
        } catch (Throwable $e) {
            return $this->failed('v1/staff/legal/version', $e, 'Legal.loadFailed');
        }
        if ($version === null) {
            return ApiResponse::error(lang('Legal.versionNotFound'), 404);
        }

        return ApiResponse::success(['version' => $version]);
    }

    /**
     * POST /staff/legal/version/create { audience, document_type, version_label, effective_from, requires_reacceptance,
     *   texts: {locale: {body, change_summary}} }
     * Publishes a new version for the Client app (audience B, when left out) or the marketplace (M). Each audience's
     * documents are a series of their own. effective_from empty is now; otherwise it must not be in the past, and
     * must come after every version of the document set so far. texts must have en-US; every locale must be one the
     * apps support.
     * @return ResponseInterface {public_id}
     */
    public function create(): ResponseInterface
    {
        $endpoint = 'v1/staff/legal/version/create';
        $input    = $this->jsonInput();
        if ($input === null) {
            return ApiResponse::error(lang('Legal.invalidRequest'), 422);
        }
        $now      = SubscriptionBilling::now();
        $audience = $this->text($input, 'audience');
        $audience = $audience === '' ? LegalDocumentModel::AUDIENCE_BUSINESS : $audience;
        $type     = $this->text($input, 'document_type');
        $label   = $this->text($input, 'version_label');
        $when    = $this->text($input, 'effective_from');
        $from    = $when === '' ? $now : SubscriptionBilling::parseTime($when);
        $material = $this->text($input, 'requires_reacceptance');

        $errors = [];
        if (! in_array($audience, LegalDocumentModel::AUDIENCES, true)) {
            $errors['audience'] = lang('Legal.invalidAudience');
        }
        if (! in_array($type, LegalDocumentModel::TYPES, true)) {
            $errors['document_type'] = lang('Legal.invalidType');
        }
        if (! preg_match(LegalDocumentModel::LABEL_PATTERN, $label)) {
            $errors['version_label'] = lang('Legal.invalidLabel');
        }
        if ($from === null) {
            $errors['effective_from'] = lang('Legal.invalidTime');
        } elseif ($from < $now) {
            $errors['effective_from'] = lang('Legal.timeInPast');
        }
        if (! in_array($material, ['Y', 'N'], true)) {
            $errors['requires_reacceptance'] = lang('Legal.invalidReacceptance');
        }
        [$texts, $textErrors] = $this->texts($input['texts'] ?? null);
        $errors += $textErrors;
        if ($errors !== []) {
            return ApiResponse::error(lang('Legal.invalidFields'), 422, $errors);
        }

        $legal = model(LegalDocumentModel::class);
        try {
            $latest = $legal->latestEffectiveFrom($audience, $type);
            if ($latest !== null && $from <= $latest) {
                $errors['effective_from'] = lang('Legal.timeNotAfterLatest', [$latest]);
            }
            if ($legal->where('audience', $audience)->where('document_type', $type)->where('version_label', $label)->countAllResults() > 0) {
                $errors['version_label'] = lang('Legal.labelTaken');
            }
            if ($errors !== []) {
                return ApiResponse::error(lang('Legal.invalidFields'), 422, $errors);
            }
            $publicId = $legal->publish([
                'audience'              => $audience,
                'document_type'         => $type,
                'version_label'         => $label,
                'effective_from'        => $from,
                'requires_reacceptance' => $material,
            ], $texts, $this->actorId());
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'Legal.saveFailed');
        }

        return ApiResponse::success(['public_id' => $publicId], 200, lang('Legal.published'));
    }

    /**
     * POST /staff/legal/version/cancel { public_id }
     * Takes back a version that has not taken effect yet; one in effect, or that was, stays for good (409)
     * @return ResponseInterface
     */
    public function cancel(): ResponseInterface
    {
        $input = $this->jsonInput() ?? [];
        $legal = model(LegalDocumentModel::class);
        try {
            $version = $legal->findByPublicId($this->text($input, 'public_id'));
            if ($version === null) {
                return ApiResponse::error(lang('Legal.versionNotFound'), 404);
            }
            if ($version['effective_from'] <= SubscriptionBilling::now()) {
                return ApiResponse::error(lang('Legal.cannotCancel'), 409);
            }
            $legal->cancelScheduled($version, $this->actorId());
        } catch (Throwable $e) {
            return $this->failed('v1/staff/legal/version/cancel', $e, 'Legal.saveFailed');
        }

        return ApiResponse::success(null, 200, lang('Legal.cancelled'));
    }

    /**
     * POST /staff/legal/acceptances { start, length, search, filters: {version: public_id, audience: B|M} }
     * One page of acceptances, newest first. search matches the email address, the name and the IP address.
     * @return ResponseInterface {records_total, records_filtered, rows}
     */
    public function acceptances(): ResponseInterface
    {
        $input = $this->jsonInput();
        if ($input === null) {
            return ApiResponse::error(lang('Legal.invalidRequest'), 422);
        }
        [$start, $length] = $this->page($input);
        $filters          = is_array($input['filters'] ?? null) ? $input['filters'] : [];
        $legal            = model(LegalDocumentModel::class);
        try {
            $versionId = null;
            $publicId  = $this->text($filters, 'version');
            if ($publicId !== '') {
                $version = $legal->findByPublicId($publicId);
                if ($version === null) {
                    return ApiResponse::error(lang('Legal.versionNotFound'), 404);
                }
                $versionId = (int) $version['id'];
            }
            $audience = $this->text($filters, 'audience');
            if ($audience !== '' && ! in_array($audience, LegalDocumentModel::AUDIENCES, true)) {
                return ApiResponse::error(lang('Legal.invalidAudience'), 422, ['audience' => lang('Legal.invalidAudience')]);
            }
            $data = $legal->acceptancesForStaff($start, $length, $versionId, mb_substr($this->text($input, 'search'), 0, 128), $audience === '' ? null : $audience);
        } catch (Throwable $e) {
            return $this->failed('v1/staff/legal/acceptances', $e, 'Legal.loadFailed');
        }

        return ApiResponse::success($data);
    }

    /**
     * The texts of a new version, checked
     * @param mixed $texts {locale: {body, change_summary}}
     * @return array{array<string, array{body: string, change_summary: string}>, array<string, string>} texts, errors
     */
    private function texts(mixed $texts): array
    {
        $supported = config(App::class)->supportedLocales;
        $checked   = [];
        $errors    = [];
        foreach (is_array($texts) ? $texts : [] as $locale => $text) {
            $body    = is_array($text) && is_string($text['body'] ?? null) ? str_replace("\r\n", "\n", trim($text['body'])) : '';
            $summary = is_array($text) && is_string($text['change_summary'] ?? null) ? trim($text['change_summary']) : '';
            if ($body === '' && $summary === '') {
                continue; // a language left empty in the form
            }
            if (! in_array($locale, $supported, true)) {
                $errors['texts.' . $locale] = lang('Legal.unknownLocale');
            } elseif ($body === '') {
                $errors['texts.' . $locale . '.body'] = lang('Legal.bodyRequired');
            } elseif (strlen($body) > LegalDocumentModel::BODY_MAX_BYTES || ! mb_check_encoding($body, 'UTF-8')) {
                $errors['texts.' . $locale . '.body'] = lang('Legal.bodyTooLong', [LegalDocumentModel::BODY_MAX_BYTES]);
            } elseif (mb_strlen($summary, 'UTF-8') > LegalDocumentModel::SUMMARY_MAX_LENGTH) {
                $errors['texts.' . $locale . '.change_summary'] = lang('Legal.summaryTooLong', [LegalDocumentModel::SUMMARY_MAX_LENGTH]);
            } else {
                $checked[$locale] = ['body' => $body, 'change_summary' => $summary];
            }
        }
        if (! isset($checked[self::REQUIRED_LOCALE]) && ! isset($errors['texts.' . self::REQUIRED_LOCALE . '.body'])) {
            $errors['texts.' . self::REQUIRED_LOCALE . '.body'] = lang('Legal.englishRequired');
        }

        return [$checked, $errors];
    }
}
