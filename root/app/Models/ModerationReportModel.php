<?php

namespace App\Models;

use App\Libraries\ActivityLog;
use App\Libraries\Notifier;
use CodeIgniter\I18n\Time;
use CodeIgniter\Model;
use Throwable;

/**
 * Model for the moderation_report table: a mart visitor's report of a business or one of its products or services
 * (see the AddModerationReport migration). Taken by App\Controllers\MartReports, reviewed by the staff through
 * App\Controllers\ReportAdmin. A report changes nothing of the business: the staff act on it with
 * ModerationEventModel, and mark it actioned or dismissed here. The business is never told of a report.
 *
 * A new report (N) tells the Executive Office and Client Experience (Notifier::REPORT_SUBMITTED): the first one of a
 * business in a day, so a burst of them is one notification, and every child-safety one. A filtered one (S) tells no
 * one and is never counted. The staff's changes go to log_activity; the visitors' reports do not, as they have no
 * account to log.
 */
class ModerationReportModel extends Model
{
    use PublicIdTrait;
    use TransactionTrait;

    // report_status
    public const STATUS_NEW       = 'N';
    public const STATUS_FILTERED  = 'S';
    public const STATUS_DISMISSED = 'D';
    public const STATUS_ACTIONED  = 'A';
    public const STATUSES         = [self::STATUS_NEW, self::STATUS_FILTERED, self::STATUS_DISMISSED, self::STATUS_ACTIONED];

    /** reason_code: what a visitor may report. There is no reason for who a business welcomes: see MartReportGuard */
    public const REASON_CHILD_SAFETY = 'CHILD_SAFETY';
    public const REASONS             = ['PROHIBITED', 'SCAM', 'MISLEADING', 'INTELLECTUAL_PROPERTY', 'OFFENSIVE', self::REASON_CHILD_SAFETY, 'OTHER'];

    /** The reasons that need details: they say nothing by themselves */
    public const DETAILS_REQUIRED = ['OFFENSIVE', 'OTHER'];

    // filter_reason, beside ContactFormGuard::spamReason()'s
    public const FILTER_AUDIENCE  = 'audience_keyword'; // about what the business declared: LGBT-friendly, naturist, adults only
    public const FILTER_DUPLICATE = 'duplicate';        // the same report from the same sender within the duplicate window

    /** The staff's departments told of new reports, and who resolves them (Config\Routes) */
    public const STAFF_DEPARTMENTS = ['EXE', 'CXD'];

    protected $table            = 'moderation_report';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [];

    /** public_id: CHAR(12), see PublicIdTrait */
    protected int $publicIdLength = 12;

    // created_at is maintained by the database
    protected $useTimestamps = false;

    /**
     * Store a report, and tell the staff of a new one, in one transaction
     * @param array{business_id: int, artifact_id: int|null, reason_code: string, details: string|null, reporter_email: string|null, locale: string, ip_hash: string, report_status: string, filter_reason: string|null} $row
     * @return string Its public_id
     * @throws Throwable When it cannot be stored; nothing is then
     */
    public function receive(array $row): string
    {
        return $this->inTransaction(function () use ($row): string {
            $row = ['public_id' => $this->newPublicId()] + $row;
            $this->db->table($this->table)->insert($row);
            $id = (int) $this->db->insertID();
            if ($row['report_status'] === self::STATUS_NEW && $this->shouldNotify($row, $id)) {
                $this->notifyStaff($row, $id);
            }

            return $row['public_id'];
        });
    }

    /**
     * Every report of a business, newest first, with what it is about and who reviewed it; ip_hash is left out, but
     * reports from one sender share their sender_key (its first 12 characters)
     * @param int $businessId
     * @param int $limit
     * @return list<array{public_id: string, artifact_public_id: string|null, artifact_name: string|null, reason_code: string, details: string|null, reporter_email: string|null, locale: string, sender_key: string, report_status: string, filter_reason: string|null, staff_note: string|null, reviewed_by_name: string|null, reviewed_at: string|null, created_at: string}>
     */
    public function listOf(int $businessId, int $limit = 200): array
    {
        $rows = $this->db->table($this->table . ' r')
            ->select('r.public_id, a.public_id AS artifact_public_id, a.artifact_name, r.reason_code, r.details, r.reporter_email, r.locale, r.ip_hash')
            ->select('r.report_status, r.filter_reason, r.staff_note, u.full_name AS reviewed_by_name, r.reviewed_at, r.created_at')
            ->join('artifact_master a', 'a.id = r.artifact_id', 'left')
            ->join('auth_master u', 'u.id = r.reviewed_by', 'left')
            ->where('r.business_id', $businessId)
            ->orderBy('r.id', 'DESC')
            ->limit($limit)
            ->get()->getResultArray();

        return array_map(static function (array $row): array {
            $row['sender_key'] = substr((string) $row['ip_hash'], 0, 12);
            unset($row['ip_hash']);

            return $row;
        }, $rows);
    }

    /**
     * A report by its public_id
     * @param mixed $publicId From the request
     * @return array|null
     */
    public function findByPublicId(mixed $publicId): ?array
    {
        if (! is_string($publicId) || ! preg_match('/^[0-9A-Za-z_-]{' . $this->publicIdLength . '}$/', $publicId)) {
            return null;
        }

        return $this->db->table($this->table)->where('public_id', $publicId)->get()->getRowArray();
    }

    /**
     * The staff's decision on a report: dismissed, actioned, filtered as spam, or back to new; logged
     * @param array       $report  findByPublicId()
     * @param string      $status  One of STATUSES
     * @param string|null $note
     * @param int         $actorId auth_master.id of the staff member
     * @return void
     * @throws Throwable When the write fails; nothing is written then
     */
    public function resolve(array $report, string $status, ?string $note, int $actorId): void
    {
        $log = new ActivityLog();
        $this->inTransaction(function () use ($report, $status, $note, $actorId, $log): bool {
            $changes = ActivityLog::changes($report, ['report_status' => $status, 'staff_note' => $note]);
            if ($changes === []) {
                return true;
            }
            $changes += ['reviewed_by' => $actorId, 'reviewed_at' => Time::now('UTC')->toDateTimeString()];
            $this->db->table($this->table)->where('id', $report['id'])->update($changes);
            $log->updated($actorId, 'Reviewed moderation report ' . $report['public_id'], $this->table, (int) $report['id'], $report, $changes);
            if ($status !== self::STATUS_NEW) {
                (new Notifier())->markSubjectRead($this->table, (int) $report['id']);
            }

            return true;
        });
    }

    /**
     * Whether a new report tells the staff: always for child safety, else when no other new report of the business
     * came in the last day
     */
    private function shouldNotify(array $row, int $id): bool
    {
        if ($row['reason_code'] === self::REASON_CHILD_SAFETY) {
            return true;
        }

        return $this->db->table($this->table)
            ->where('business_id', $row['business_id'])
            ->where('report_status', self::STATUS_NEW)
            ->where('id !=', $id)
            ->where('created_at >=', Time::now('UTC')->subDays(1)->toDateTimeString())
            ->countAllResults() === 0;
    }

    /**
     * Notifier::REPORT_SUBMITTED to STAFF_DEPARTMENTS, to the business's page of the Admin app
     */
    private function notifyStaff(array $row, int $id): void
    {
        $business = $this->db->table('business_master')->select('business_name, business_slug')->where('id', $row['business_id'])->get()->getRowArray() ?? [];
        $artifact = $row['artifact_id'] === null ? null
            : $this->db->table('artifact_master')->select('artifact_name')->where('id', $row['artifact_id'])->get()->getRowArray();
        (new Notifier())->toDepartments(self::STAFF_DEPARTMENTS, Notifier::REPORT_SUBMITTED, [
            'business' => (string) ($business['business_name'] ?? ''),
            'subject'  => (string) ($artifact['artifact_name'] ?? $business['business_name'] ?? ''),
            'reason'   => 'lang:report_reason.' . $row['reason_code'],
        ], '/admin/clients/businesses/view/' . rawurlencode((string) ($business['business_slug'] ?? '')) . '#reports', [$this->table, $id]);
    }
}
