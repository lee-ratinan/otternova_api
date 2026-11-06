<?php

namespace App\Models;

use App\Libraries\ActivityLog;
use CodeIgniter\Model;
use Throwable;

/**
 * Model for the artifact_review table: a customer's rating and review of an artifact. A review starts pending (P);
 * approved (A) shows it to other customers, hidden (H) takes it down. Only the approved ones count towards the
 * artifact's rating_sum, rating_count and rating_average, worked out again in the transaction of every change of
 * review_status. Reviews are never deleted.
 */
class ArtifactReviewModel extends Model
{
    use PublicIdTrait;
    use TransactionTrait;

    // review_status
    public const STATUS_PENDING  = 'P';
    public const STATUS_APPROVED = 'A';
    public const STATUS_HIDDEN   = 'H';

    protected $table            = 'artifact_review';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [];

    /** public_id: CHAR(12), see PublicIdTrait */
    protected int $publicIdLength = 12;

    // created_at and updated_at are maintained by the database
    protected $useTimestamps = false;

    /**
     * One page of the reviews of a business's artifacts, newest first
     * @param int   $businessId
     * @param int   $start
     * @param int   $length
     * @param array $filters review_status, artifact_id: each only when set
     * @return array{records_total: int, records_filtered: int, rows: list<array>}
     */
    public function listOfBusiness(int $businessId, int $start, int $length, array $filters = []): array
    {
        $total    = $this->ofBusiness($businessId)->countAllResults();
        $builder  = $this->ofBusiness($businessId);
        foreach (['review_status' => 'r.review_status', 'artifact_id' => 'r.artifact_id'] as $filter => $column) {
            if (isset($filters[$filter])) {
                $builder->where($column, $filters[$filter]);
            }
        }
        $filtered = $builder->countAllResults(false);
        $rows     = $builder
            ->select('r.id')
            ->orderBy('r.created_at', 'DESC')
            ->orderBy('r.id', 'DESC')
            ->limit($length, $start)
            ->get()
            ->getResultArray();

        return ['records_total' => $total, 'records_filtered' => $filtered, 'rows' => $this->payloads(array_map('intval', array_column($rows, 'id')))];
    }

    /**
     * Reviews as the API sends them, in the order of $ids
     * @param list<int> $ids
     * @return list<array{public_id: string, artifact_public_id: string, artifact_name: string, customer_name: string, rating: int, review_title: string, review_message: string, review_status: string, reply_message: string|null, replied_at: string|null, created_at: string}>
     */
    public function payloads(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $rows = $this->db->table($this->table . ' r')
            ->select('r.id, r.public_id, a.public_id AS artifact_public_id, a.artifact_name, c.customer_name, r.rating, r.review_title,'
                . ' r.review_message, r.review_status, r.reply_message, r.replied_at, r.created_at')
            ->join('artifact_master a', 'a.id = r.artifact_id')
            ->join('customer_master c', 'c.id = r.customer_id')
            ->whereIn('r.id', $ids)
            ->get()
            ->getResultArray();
        $byId = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            unset($row['id']);
            $row['rating'] = (int) $row['rating'];
            $byId[$id]     = $row;
        }

        return array_values(array_filter(array_map(static fn (int $id) => $byId[$id] ?? null, $ids)));
    }

    /**
     * Change a review's status and work out its artifact's rating again, in one transaction logged to log_activity.
     * The artifact's row is locked first (on MySQL), so two changes at once cannot both work from the old figures.
     * @param array  $review  The artifact_review row
     * @param string $status  STATUS_APPROVED or STATUS_HIDDEN
     * @param int    $actorId
     * @return bool False when the review has that status already; nothing is written then
     * @throws Throwable When a write fails; nothing is written then
     */
    public function setStatus(array $review, string $status, int $actorId): bool
    {
        if ($review['review_status'] === $status) {
            return false;
        }

        return $this->inTransaction(function () use ($review, $status, $actorId) {
            $log        = new ActivityLog();
            $artifactId = (int) $review['artifact_id'];
            if ($this->db->DBDriver === 'MySQLi') {
                $this->db->query('SELECT id FROM ' . $this->db->escapeIdentifiers($this->db->prefixTable('artifact_master')) . ' WHERE id = ? FOR UPDATE', [$artifactId]);
            }
            $this->db->table($this->table)->where('id', $review['id'])->update(['review_status' => $status]);
            $log->updated($actorId, ($status === self::STATUS_APPROVED ? 'Approved' : 'Hid') . ' artifact review ' . $review['public_id'], $this->table, (int) $review['id'], $review, ['review_status' => $status]);

            $totals = $this->db->table($this->table)
                ->select('COALESCE(SUM(rating), 0) AS rating_sum, COUNT(*) AS rating_count')
                ->where('artifact_id', $artifactId)
                ->where('review_status', self::STATUS_APPROVED)
                ->get()
                ->getRowArray();
            $sum     = (int) $totals['rating_sum'];
            $count   = (int) $totals['rating_count'];
            $rating  = ['rating_sum' => $sum, 'rating_count' => $count, 'rating_average' => number_format($count === 0 ? 0 : round($sum / $count, 2), 2, '.', '')];
            $stored  = $this->db->table('artifact_master')->select('rating_sum, rating_count, rating_average')->where('id', $artifactId)->get()->getRowArray();
            $stored['rating_average'] = number_format((float) $stored['rating_average'], 2, '.', '');
            $changes = ActivityLog::changes($stored, $rating);
            if ($changes !== []) {
                $this->db->table('artifact_master')->where('id', $artifactId)->update($changes);
                $log->updated($actorId, 'Worked out the rating of artifact ' . $artifactId . ' again', 'artifact_master', $artifactId, $stored, $changes);
            }

            return true;
        });
    }

    /**
     * The reviews of a business's artifacts, to build a query on
     * @param int $businessId
     * @return \CodeIgniter\Database\BaseBuilder
     */
    private function ofBusiness(int $businessId)
    {
        return $this->db->table($this->table . ' r')
            ->join('artifact_master a', 'a.id = r.artifact_id')
            ->where('a.business_id', $businessId);
    }
}
