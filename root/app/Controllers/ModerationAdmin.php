<?php

namespace App\Controllers;

use App\Libraries\ApiResponse;
use App\Models\BusinessMasterModel;
use App\Models\ModerationEventModel;
use App\Models\ModerationRefusedException;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * Otternova's review and moderation of the businesses, their products and services and their variants, for the Admin
 * app (App\Models\ModerationEventModel). Approve, flag, block and unblock: the Executive Office and Client Experience;
 * remove: the Executive Office only (Config\Routes). The history: any staff member.
 *
 * Every action takes { business_slug, subject: "business"|"artifact"|"variant", public_id (of the artifact or variant),
 * reason_code, client_message, internal_note }. Flag, block and remove need a reason_code (ModerationEventModel::REASONS)
 * and a client_message, which the business is shown; internal_note stays with the staff. A variant is blocked,
 * unblocked or removed only. 409 when the action does not apply as the subject stands (removed already, not blocked,
 * blocked already, or approving what is blocked). The answer: the subject's review_status (null for a variant) and
 * moderation_status after the action.
 */
class ModerationAdmin extends BaseController
{
    use BillingRequestTrait;

    private const SUBJECTS = [
        'business' => ModerationEventModel::SUBJECT_BUSINESS,
        'artifact' => ModerationEventModel::SUBJECT_ARTIFACT,
        'variant'  => ModerationEventModel::SUBJECT_VARIANT,
    ];

    /** The longest client_message and internal_note */
    private const NOTE_MAX_LENGTH = 2000;

    /** POST /staff/moderation/approve */
    public function approve(): ResponseInterface
    {
        return $this->act(ModerationEventModel::APPROVE, 'v1/staff/moderation/approve');
    }

    /** POST /staff/moderation/flag */
    public function flag(): ResponseInterface
    {
        return $this->act(ModerationEventModel::FLAG, 'v1/staff/moderation/flag');
    }

    /** POST /staff/moderation/block */
    public function block(): ResponseInterface
    {
        return $this->act(ModerationEventModel::BLOCK, 'v1/staff/moderation/block');
    }

    /** POST /staff/moderation/unblock */
    public function unblock(): ResponseInterface
    {
        return $this->act(ModerationEventModel::UNBLOCK, 'v1/staff/moderation/unblock');
    }

    /** POST /staff/moderation/remove: for good */
    public function remove(): ResponseInterface
    {
        return $this->act(ModerationEventModel::REMOVE, 'v1/staff/moderation/remove');
    }

    /**
     * POST /staff/businesses/moderation { business_slug }
     * Every review and moderation event of the business, its artifacts and their variants, newest first
     * @return ResponseInterface
     */
    public function history(): ResponseInterface
    {
        $slug = $this->text($this->jsonInput() ?? [], 'business_slug');
        if ($slug === '') {
            return ApiResponse::error(lang('Moderation.invalidRequest'), 422);
        }
        try {
            $business = model(BusinessMasterModel::class)->select('id')->where('business_slug', $slug)->first();
            if ($business === null) {
                return ApiResponse::error(lang('Moderation.businessNotFound'), 404);
            }
            $events = model(ModerationEventModel::class)->historyOf((int) $business['id']);
        } catch (Throwable $e) {
            return $this->failed('v1/staff/businesses/moderation', $e, 'Moderation.loadFailed');
        }

        return ApiResponse::success($events);
    }

    /**
     * One action, checked and taken
     * @param string $action
     * @param string $endpoint
     * @return ResponseInterface
     */
    private function act(string $action, string $endpoint): ResponseInterface
    {
        $input = $this->jsonInput();
        if ($input === null) {
            return ApiResponse::error(lang('Moderation.invalidRequest'), 422);
        }
        $slug   = $this->text($input, 'business_slug');
        $type   = self::SUBJECTS[strtolower($this->text($input, 'subject'))] ?? null;
        $errors = [];
        if ($slug === '') {
            $errors['business_slug'] = lang('Moderation.invalidRequest');
        }
        if ($type === null) {
            $errors['subject'] = lang('Moderation.invalidSubject');
        } elseif ($type === ModerationEventModel::SUBJECT_VARIANT && ! in_array($action, ModerationEventModel::VARIANT_ACTIONS, true)) {
            $errors['subject'] = lang('Moderation.invalidAction');
        }
        $note = [];
        foreach (['client_message', 'internal_note'] as $field) {
            $value        = $this->text($input, $field);
            $note[$field] = $value === '' ? null : $value;
            if (mb_strlen($value) > self::NOTE_MAX_LENGTH) {
                $errors[$field] = lang('Moderation.tooLong', [self::NOTE_MAX_LENGTH]);
            }
        }
        $reason              = strtoupper($this->text($input, 'reason_code'));
        $note['reason_code'] = $reason === '' ? null : $reason;
        if ($reason !== '' && ! in_array($reason, ModerationEventModel::REASONS, true)) {
            $errors['reason_code'] = lang('Moderation.invalidReason');
        }
        if (in_array($action, ModerationEventModel::EXPLAINED_ACTIONS, true)) {
            if ($reason === '') {
                $errors['reason_code'] = lang('Moderation.reasonRequired');
            }
            if ($note['client_message'] === null) {
                $errors['client_message'] = lang('Moderation.messageRequired');
            }
        }
        if ($errors !== []) {
            return ApiResponse::error(lang('Moderation.invalidFields'), 422, $errors);
        }

        try {
            [$subject, $notFound] = $this->subject($slug, $type, $this->text($input, 'public_id'));
            if ($subject === null) {
                return ApiResponse::error(lang($notFound), 404);
            }
            $state = model(ModerationEventModel::class)->act($subject, $action, $note, $this->actorId());
        } catch (ModerationRefusedException $e) {
            return ApiResponse::error(lang($e->langKey), 409);
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'Moderation.saveFailed');
        }

        return ApiResponse::success($state, 200, lang('Moderation.done.' . $action));
    }

    /**
     * The subject of an action, its artifact or variant within the business only
     * @param string $slug
     * @param string $type
     * @param string $publicId Of the artifact or variant
     * @return array{0: array|null, 1: string} [subject as ModerationEventModel::act() takes it, or null and the language line of what was not found]
     */
    private function subject(string $slug, string $type, string $publicId): array
    {
        $db       = db_connect();
        $business = $db->table('business_master')->select('id')->where('business_slug', $slug)->get()->getRowArray();
        if ($business === null) {
            return [null, 'Moderation.businessNotFound'];
        }
        $subject = ['type' => $type, 'business_id' => (int) $business['id']];
        if ($type === ModerationEventModel::SUBJECT_BUSINESS) {
            return [$subject, ''];
        }
        $table = $type === ModerationEventModel::SUBJECT_ARTIFACT ? 'artifact_master' : 'artifact_variant';
        $row   = $publicId === '' ? null : $db->table($table)->select($type === ModerationEventModel::SUBJECT_ARTIFACT ? 'id AS artifact_id' : 'id AS variant_id, artifact_id')
            ->where('business_id', $subject['business_id'])->where('public_id', $publicId)
            ->get()->getRowArray();
        if ($row === null) {
            return [null, $type === ModerationEventModel::SUBJECT_ARTIFACT ? 'Moderation.artifactNotFound' : 'Moderation.variantNotFound'];
        }

        return [$subject + array_map('intval', $row), ''];
    }
}
