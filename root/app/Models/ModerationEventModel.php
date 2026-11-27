<?php

namespace App\Models;

use App\Libraries\ActivityLog;
use App\Libraries\Notifier;
use CodeIgniter\Model;
use Throwable;

/**
 * Model for the moderation_event table: Otternova's review and moderation of a business, a product or service
 * (artifact_master) or a variant (artifact_variant), and the business changing what was flagged. See the AddModeration
 * migration for the columns.
 *
 * - Approve (AP) and flag (FL): a business or an artifact; review_status A or F. Nothing changes on the mart.
 * - Block (BL): moderation_status B and review_status F (a variant's artifact is flagged instead), and off the mart:
 *   the business closed (is_business_open N), the artifact or variant off sale (I). The status before goes in
 *   previous_status, and comes back on unblocking (UB), which approves the subject too.
 * - Remove (RM): moderation_status R for good, closed or off sale (an artifact discontinued, D); nothing more can be done
 *   to it.
 * - Resubmitted (RS): written by resubmitBusiness() and resubmitArtifact() when the business changes a flagged business or artifact; review_status P,
 *   for the staff to review it again.
 * Every write goes to log_activity in the same transaction, and the business's mart answers are made stale after it.
 * A flag, block, unblock or removal notifies the business's owners and managers (Notifier), in the same transaction.
 */
class ModerationEventModel extends Model
{
    use PublicIdTrait;
    use TransactionTrait;

    // subject_type
    public const SUBJECT_BUSINESS = 'B';
    public const SUBJECT_ARTIFACT = 'A';
    public const SUBJECT_VARIANT  = 'V';

    // event_action
    public const APPROVE     = 'AP';
    public const FLAG        = 'FL';
    public const BLOCK       = 'BL';
    public const UNBLOCK     = 'UB';
    public const REMOVE      = 'RM';
    public const RESUBMITTED = 'RS';

    /** The actions the staff take, and those a variant takes */
    public const STAFF_ACTIONS   = [self::APPROVE, self::FLAG, self::BLOCK, self::UNBLOCK, self::REMOVE];
    public const VARIANT_ACTIONS = [self::BLOCK, self::UNBLOCK, self::REMOVE];

    /** The actions that need a reason_code and a client_message: the business has to know what to fix */
    public const EXPLAINED_ACTIONS = [self::FLAG, self::BLOCK, self::REMOVE];

    // review_status of business_master and artifact_master
    public const REVIEW_UNREVIEWED = 'U';
    public const REVIEW_APPROVED   = 'A';
    public const REVIEW_FLAGGED    = 'F';
    public const REVIEW_PENDING    = 'P';

    // moderation_status of business_master, artifact_master and artifact_variant
    public const MODERATION_NONE    = 'N';
    public const MODERATION_BLOCKED = 'B';
    public const MODERATION_REMOVED = 'R';

    /** The actions the business's owners and managers are notified of, and the event of each */
    private const NOTIFIED = [
        self::FLAG    => Notifier::MODERATION_FLAGGED,
        self::BLOCK   => Notifier::MODERATION_BLOCKED,
        self::UNBLOCK => Notifier::MODERATION_UNBLOCKED,
        self::REMOVE  => Notifier::MODERATION_REMOVED,
    ];

    /** reason_code: why something was flagged, blocked or removed */
    public const REASONS = ['PROHIBITED', 'MISLEADING', 'INTELLECTUAL_PROPERTY', 'AUDIENCE', 'BUSINESS_TYPE', 'PRICING', 'OTHER'];

    /** Per subject_type: its table, its status column, the status that takes it off the mart and the one of removing it */
    private const TARGETS = [
        self::SUBJECT_BUSINESS => ['business_master', 'is_business_open', 'N', 'N'],
        self::SUBJECT_ARTIFACT => ['artifact_master', 'artifact_status', 'I', 'D'],
        self::SUBJECT_VARIANT  => ['artifact_variant', 'variant_status', 'I', 'I'],
    ];

    protected $table            = 'moderation_event';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [];

    /** public_id: CHAR(12), see PublicIdTrait */
    protected int $publicIdLength = 12;

    // created_at is maintained by the database; an event is never changed
    protected $useTimestamps = false;

    /**
     * Take a staff action on a business, an artifact or a variant, in one transaction
     * @param array{type: string, business_id: int, artifact_id?: int|null, variant_id?: int|null} $subject
     * @param string $action One of STAFF_ACTIONS (VARIANT_ACTIONS for a variant)
     * @param array{reason_code?: string|null, client_message?: string|null, internal_note?: string|null} $note Checked
     * @param int    $actorId auth_master.id of the staff member
     * @return array{review_status: string|null, moderation_status: string} The subject's, after the action
     * @throws ModerationRefusedException When the action does not apply to the subject as it stands; nothing is written
     * @throws Throwable When a write fails; nothing is written then
     */
    public function act(array $subject, string $action, array $note, int $actorId): array
    {
        $log   = new ActivityLog();
        $state = $this->inTransaction(fn (): array => $this->apply($subject, $action, $note, $actorId, $log));
        $this->refreshMart((int) $subject['business_id']);

        return $state;
    }

    /**
     * Inside the transaction of a save by the business: when the business was flagged (review_status F, which a block
     * sets too), it is now pending re-review (P), with an RS event
     * @param int         $businessId
     * @param int         $actorId auth_master.id of the client saving it
     * @param ActivityLog $log
     * @return void
     */
    public function resubmitBusiness(int $businessId, int $actorId, ActivityLog $log): void
    {
        $this->resubmit('business_master', $businessId, $businessId, null, $actorId, $log);
    }

    /**
     * Inside the transaction of a save by the business (the artifact, its variants or its images): when the artifact
     * was flagged (review_status F, which blocking it or one of its variants sets too), it is now pending re-review
     * (P), with an RS event
     * @param int         $artifactId
     * @param int         $actorId auth_master.id of the client saving it
     * @param ActivityLog $log
     * @return void
     */
    public function resubmitArtifact(int $artifactId, int $actorId, ActivityLog $log): void
    {
        $row = $this->db->table('artifact_master')->select('business_id')->where('id', $artifactId)->get()->getRowArray();
        if ($row !== null) {
            $this->resubmit('artifact_master', $artifactId, (int) $row['business_id'], $artifactId, $actorId, $log);
        }
    }

    /**
     * resubmitBusiness() and resubmitArtifact()
     * @param string      $table
     * @param int         $id
     * @param int         $businessId
     * @param int|null    $artifactId
     * @param int         $actorId
     * @param ActivityLog $log
     * @return void
     */
    private function resubmit(string $table, int $id, int $businessId, ?int $artifactId, int $actorId, ActivityLog $log): void
    {
        $row = $this->db->table($table)->select('id, review_status')->where('id', $id)->get()->getRowArray();
        if ($row === null || $row['review_status'] !== self::REVIEW_FLAGGED) {
            return;
        }
        $this->db->table($table)->where('id', $id)->update(['review_status' => self::REVIEW_PENDING]);
        $log->updated($actorId, 'Sent ' . ($artifactId === null ? 'business ' : 'artifact ') . $id . ' back for review', $table, $id, $row, ['review_status' => self::REVIEW_PENDING]);
        $this->writeEvent([
            'business_id'  => $businessId,
            'artifact_id'  => $artifactId,
            'subject_type' => $artifactId === null ? self::SUBJECT_BUSINESS : self::SUBJECT_ARTIFACT,
            'event_action' => self::RESUBMITTED,
        ], $actorId, $log);
    }

    /**
     * Every event of a business, newest first: on the business, its artifacts and their variants, with who wrote it
     * @param int $businessId
     * @param int $limit
     * @return list<array{public_id: string, subject_type: string, event_action: string, reason_code: string|null, client_message: string|null, internal_note: string|null, created_at: string, created_by_name: string|null, created_by_type: string|null, artifact_public_id: string|null, artifact_name: string|null, variant_public_id: string|null, variant_sku: string|null}>
     */
    public function historyOf(int $businessId, int $limit = 200): array
    {
        return $this->db->table($this->table . ' e')
            ->select('e.public_id, e.subject_type, e.event_action, e.reason_code, e.client_message, e.internal_note, e.created_at')
            ->select('u.full_name AS created_by_name, u.account_type AS created_by_type')
            ->select('a.public_id AS artifact_public_id, a.artifact_name, v.public_id AS variant_public_id, v.sku AS variant_sku')
            ->join('auth_master u', 'u.id = e.created_by', 'left')
            ->join('artifact_master a', 'a.id = e.artifact_id', 'left')
            ->join('artifact_variant v', 'v.id = e.variant_id', 'left')
            ->where('e.business_id', $businessId)
            ->orderBy('e.id', 'DESC')
            ->limit($limit)
            ->get()->getResultArray();
    }

    /**
     * What the business is told about a business or artifact that is flagged, pending re-review, blocked or removed:
     * the last flag, block or removal of it (and, for an artifact, of its variants). Null when nothing stands.
     * @param int      $businessId
     * @param int|null $artifactId Null for the business itself
     * @param string   $reviewStatus
     * @param string   $moderationStatus
     * @return array{event_action: string, reason_code: string|null, client_message: string|null, created_at: string}|null
     */
    public function noticeOf(int $businessId, ?int $artifactId, string $reviewStatus, string $moderationStatus): ?array
    {
        if ($moderationStatus === self::MODERATION_NONE && ! in_array($reviewStatus, [self::REVIEW_FLAGGED, self::REVIEW_PENDING], true)) {
            return null;
        }
        $builder = $this->db->table($this->table)
            ->select('event_action, reason_code, client_message, created_at')
            ->where('business_id', $businessId)
            ->whereIn('event_action', self::EXPLAINED_ACTIONS);
        if ($artifactId === null) {
            $builder->where('artifact_id', null);
        } else {
            $builder->where('artifact_id', $artifactId);
        }

        return $builder->orderBy('id', 'DESC')->limit(1)->get()->getRowArray();
    }

    /**
     * act() inside its transaction
     * @param array       $subject
     * @param string      $action
     * @param array       $note
     * @param int         $actorId
     * @param ActivityLog $log
     * @return array
     */
    private function apply(array $subject, string $action, array $note, int $actorId, ActivityLog $log): array
    {
        $type                          = $subject['type'];
        [$table, $column, $off, $gone] = self::TARGETS[$type];
        $id                            = (int) match ($type) {
            self::SUBJECT_BUSINESS => $subject['business_id'],
            self::SUBJECT_ARTIFACT => $subject['artifact_id'],
            self::SUBJECT_VARIANT  => $subject['variant_id'],
        };
        $reviewed = $type !== self::SUBJECT_VARIANT; // a variant has no review_status of its own: its artifact's stands
        if (! in_array($action, $reviewed ? self::STAFF_ACTIONS : self::VARIANT_ACTIONS, true)) {
            throw new ModerationRefusedException('Moderation.invalidAction');
        }
        if ($this->db->DBDriver === 'MySQLi') {
            $this->db->query('SELECT id FROM ' . $this->db->escapeIdentifiers($this->db->prefixTable($table)) . ' WHERE id = ? FOR UPDATE', [$id]);
        }
        $row        = $this->db->table($table)->select('id, ' . $column . ', moderation_status' . ($reviewed ? ', review_status' : ''))->where('id', $id)->get()->getRowArray();
        $moderation = $row['moderation_status'];
        if ($moderation === self::MODERATION_REMOVED) {
            throw new ModerationRefusedException('Moderation.removedAlready');
        }

        $previous = null;
        $changes  = [];
        switch ($action) {
            case self::APPROVE:
            case self::FLAG:
                if ($moderation === self::MODERATION_BLOCKED) {
                    throw new ModerationRefusedException($action === self::APPROVE ? 'Moderation.unblockFirst' : 'Moderation.blockedAlready');
                }
                $changes = ['review_status' => $action === self::APPROVE ? self::REVIEW_APPROVED : self::REVIEW_FLAGGED];
                break;
            case self::BLOCK:
                if ($moderation === self::MODERATION_BLOCKED) {
                    throw new ModerationRefusedException('Moderation.blockedAlready');
                }
                $previous = $row[$column];
                // a discontinued artifact stays discontinued: it is off the mart already
                $changes = ['moderation_status' => self::MODERATION_BLOCKED, $column => $previous === $gone && $type === self::SUBJECT_ARTIFACT ? $gone : $off]
                    + ($reviewed ? ['review_status' => self::REVIEW_FLAGGED] : []);
                break;
            case self::UNBLOCK:
                if ($moderation !== self::MODERATION_BLOCKED) {
                    throw new ModerationRefusedException('Moderation.notBlocked');
                }
                $changes = ['moderation_status' => self::MODERATION_NONE] + ($reviewed ? ['review_status' => self::REVIEW_APPROVED] : []);
                // given back what it was before the block, unless it changed while blocked (an artifact discontinued)
                $blocked = $this->lastBlockOf($type, $id);
                if ($blocked !== null && $blocked['previous_status'] !== null && $row[$column] === $off) {
                    $changes[$column] = $blocked['previous_status'];
                }
                break;
            case self::REMOVE:
                $previous = $row[$column];
                $changes  = ['moderation_status' => self::MODERATION_REMOVED, $column => $gone] + ($reviewed ? ['review_status' => self::REVIEW_FLAGGED] : []);
                break;
        }
        $changes = ActivityLog::changes($row, $changes);
        if ($changes !== []) {
            $this->db->table($table)->where('id', $id)->update($changes);
            $log->updated($actorId, self::summary($action, $type, $id), $table, $id, $row, $changes);
        }

        $artifactId = $subject['artifact_id'] ?? null;
        if ($type === self::SUBJECT_VARIANT) {
            if ($action !== self::UNBLOCK) {
                $this->flagArtifact((int) $artifactId, $actorId, $log); // the business has its product or service to fix
            }
            if (isset($changes['variant_status'])) {
                $artifacts = model(ArtifactMasterModel::class);
                $artifacts->refreshLowestPrices((int) $artifactId, (string) $artifacts->publicIdOf((int) $artifactId), $actorId, $log);
            }
        }
        $this->writeEvent([
            'business_id'     => (int) $subject['business_id'],
            'artifact_id'     => $artifactId,
            'variant_id'      => $subject['variant_id'] ?? null,
            'subject_type'    => $type,
            'event_action'    => $action,
            'reason_code'     => $note['reason_code'] ?? null,
            'client_message'  => $note['client_message'] ?? null,
            'internal_note'   => $note['internal_note'] ?? null,
            'previous_status' => $previous,
        ], $actorId, $log);
        if (isset(self::NOTIFIED[$action])) {
            $this->notify($subject, $id, $action, $note, $actorId);
        }

        $after = $changes + $row;

        return ['review_status' => $reviewed ? $after['review_status'] : null, 'moderation_status' => $after['moderation_status']];
    }

    /**
     * Notify the business's owners and managers of an action, inside the transaction: what it was taken on (the
     * business, or the product or service, with the variant's SKU), the reason and the message to them, and the page of
     * the Client app to fix it on
     * @param array  $subject
     * @param int    $id      The subject's id
     * @param string $action  One of NOTIFIED
     * @param array  $note
     * @param int    $actorId
     * @return void
     */
    private function notify(array $subject, int $id, string $action, array $note, int $actorId): void
    {
        $businessId = (int) $subject['business_id'];
        if ($subject['type'] === self::SUBJECT_BUSINESS) {
            $name   = (string) ($this->db->table('business_master')->select('business_name')->where('id', $businessId)->get()->getRow()->business_name ?? '');
            $target = '/admin/business';
            $table  = 'business_master';
        } else {
            $artifact = $this->db->table('artifact_master')->select('public_id, artifact_name')->where('id', (int) $subject['artifact_id'])->get()->getRowArray() ?? [];
            $name     = (string) ($artifact['artifact_name'] ?? '');
            $target   = '/admin/artifacts/edit/' . ($artifact['public_id'] ?? '');
            $table    = 'artifact_master';
            if ($subject['type'] === self::SUBJECT_VARIANT) {
                $sku   = (string) ($this->db->table('artifact_variant')->select('sku')->where('id', $id)->get()->getRow()->sku ?? '');
                $name .= $sku === '' ? '' : ' (' . $sku . ')';
                $table = 'artifact_variant';
            }
        }
        $reason = $note['reason_code'] ?? null;
        (new Notifier())->toBusiness($businessId, [BusinessClientLinkModel::ROLE_OWNER, BusinessClientLinkModel::ROLE_MANAGER], self::NOTIFIED[$action], [
            'subject' => $name,
            'reason'  => in_array($reason, self::REASONS, true) ? 'lang:moderation_reason.' . $reason : 'lang:moderation_reason.OTHER',
            'message' => trim((string) ($note['client_message'] ?? '')),
        ], $target, [$table, $id], $actorId);
    }

    /**
     * Flag the artifact of a variant blocked or removed, unless it is blocked or removed itself
     * @param int         $artifactId
     * @param int         $actorId
     * @param ActivityLog $log
     * @return void
     */
    private function flagArtifact(int $artifactId, int $actorId, ActivityLog $log): void
    {
        $row = $this->db->table('artifact_master')->select('id, review_status, moderation_status')->where('id', $artifactId)->get()->getRowArray();
        if ($row === null || $row['moderation_status'] !== self::MODERATION_NONE || $row['review_status'] === self::REVIEW_FLAGGED) {
            return;
        }
        $this->db->table('artifact_master')->where('id', $artifactId)->update(['review_status' => self::REVIEW_FLAGGED]);
        $log->updated($actorId, 'Flagged artifact ' . $artifactId . ' for its variant', 'artifact_master', $artifactId, $row, ['review_status' => self::REVIEW_FLAGGED]);
    }

    /**
     * The last block of a subject
     * @param string $type
     * @param int    $id
     * @return array|null
     */
    private function lastBlockOf(string $type, int $id): ?array
    {
        $builder = $this->db->table($this->table)->select('previous_status')->where('subject_type', $type)->where('event_action', self::BLOCK);
        match ($type) {
            self::SUBJECT_BUSINESS => $builder->where('business_id', $id),
            self::SUBJECT_ARTIFACT => $builder->where('artifact_id', $id),
            self::SUBJECT_VARIANT  => $builder->where('variant_id', $id),
        };

        return $builder->orderBy('id', 'DESC')->limit(1)->get()->getRowArray();
    }

    /**
     * Insert an event, logged
     * @param array       $event
     * @param int         $actorId
     * @param ActivityLog $log
     * @return void
     */
    private function writeEvent(array $event, int $actorId, ActivityLog $log): void
    {
        $row = ['public_id' => $this->newPublicId()] + $event + ['created_by' => $actorId];
        $this->db->table($this->table)->insert($row);
        $log->inserted($actorId, self::summary($event['event_action'], $event['subject_type'], (int) ($event['variant_id'] ?? $event['artifact_id'] ?? $event['business_id'])) . ' (event)',
            $this->table, (int) $this->db->insertID(), $row);
    }

    /**
     * The log line of an action
     * @param string $action
     * @param string $type
     * @param int    $id
     * @return string
     */
    private static function summary(string $action, string $type, int $id): string
    {
        $verbs = [self::APPROVE => 'Approved', self::FLAG => 'Flagged', self::BLOCK => 'Blocked', self::UNBLOCK => 'Unblocked',
            self::REMOVE => 'Removed', self::RESUBMITTED => 'Resubmitted'];
        $nouns = [self::SUBJECT_BUSINESS => 'business', self::SUBJECT_ARTIFACT => 'artifact', self::SUBJECT_VARIANT => 'variant'];

        return $verbs[$action] . ' ' . $nouns[$type] . ' ' . $id;
    }

    /**
     * Make the business's cached mart answers stale (App\Libraries\MartCache); one that will not clear is only logged
     * @param int $businessId
     * @return void
     */
    private function refreshMart(int $businessId): void
    {
        try {
            service('martCache')->forget($businessId);
        } catch (Throwable $e) {
            log_message('error', 'The mart cache could not be cleared after a moderation: {message}', ['message' => $e->getMessage()]);
        }
    }
}
