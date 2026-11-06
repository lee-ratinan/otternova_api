<?php

namespace App\Models;

use App\Libraries\ActivityLog;
use CodeIgniter\Model;
use Throwable;

/**
 * Model for the artifact_review table: a customer's rating and review of an artifact. A review starts pending (P);
 * approved (A) shows it to other customers, hidden (H) takes it down. Only the approved ones count towards the
 * rating of the artifact (rating_sum, rating_count, rating_average and rating_1_count ... rating_5_count, the reviews
 * with each number of stars) and of its business (the same columns of business_master, the sums of its artifacts'),
 * worked out again in the transaction of every change of review_status. Reviews are never deleted.
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

    /** The numbers of stars a review gives */
    public const STARS = [1, 2, 3, 4, 5];

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
     * Change a review's status and work out the rating of its artifact and of the artifact's business again, in one
     * transaction logged to log_activity. The business's row, then the artifact's, is locked first (on MySQL), in the
     * order ArtifactMasterModel::saveArtifact() locks them, so two changes at once cannot both work from the old figures.
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
            $businessId = (int) $this->db->table('artifact_master')->select('business_id')->where('id', $artifactId)->get()->getRow()->business_id;
            if ($this->db->DBDriver === 'MySQLi') {
                foreach (['business_master' => $businessId, 'artifact_master' => $artifactId] as $table => $id) {
                    $this->db->query('SELECT id FROM ' . $this->db->escapeIdentifiers($this->db->prefixTable($table)) . ' WHERE id = ? FOR UPDATE', [$id]);
                }
            }
            $this->db->table($this->table)->where('id', $review['id'])->update(['review_status' => $status]);
            $log->updated($actorId, ($status === self::STATUS_APPROVED ? 'Approved' : 'Hid') . ' artifact review ' . $review['public_id'], $this->table, (int) $review['id'], $review, ['review_status' => $status]);

            // the artifact: counted again from its approved reviews
            $stars = array_fill_keys(self::STARS, 0);
            $rows  = $this->db->table($this->table)
                ->select('rating, COUNT(*) AS reviews')
                ->where('artifact_id', $artifactId)
                ->where('review_status', self::STATUS_APPROVED)
                ->groupBy('rating')
                ->get()
                ->getResultArray();
            foreach ($rows as $row) {
                $stars[(int) $row['rating']] = (int) $row['reviews'];
            }
            $this->writeRating('artifact_master', $artifactId, self::ratingOf($stars), $actorId, $log, 'artifact ' . $artifactId);

            // the business: the sums of its artifacts', the one just worked out included
            $counts = array_map(static fn (int $star): string => 'rating_' . $star . '_count', self::STARS);
            $sums   = $this->db->table('artifact_master')
                ->select(implode(', ', array_map(static fn (string $column): string => 'COALESCE(SUM(' . $column . '), 0) AS ' . $column, $counts)), false)
                ->where('business_id', $businessId)
                ->get()
                ->getRowArray();
            $stars = [];
            foreach (self::STARS as $star) {
                $stars[$star] = (int) $sums['rating_' . $star . '_count'];
            }
            $this->writeRating('business_master', $businessId, self::ratingOf($stars), $actorId, $log, 'business ' . $businessId);

            return true;
        });
    }

    /**
     * The rating of a business, from business_master
     * @param int $businessId
     * @return array{rating_count: int, rating_average: string, rating_breakdown: array<int, int>}
     */
    public function ratingOfBusiness(int $businessId): array
    {
        $row = $this->db->table('business_master')
            ->select('rating_count, rating_average, ' . implode(', ', array_map(static fn (int $star): string => 'rating_' . $star . '_count', self::STARS)))
            ->where('id', $businessId)
            ->get()
            ->getRowArray() ?? [];

        return [
            'rating_count'     => (int) ($row['rating_count'] ?? 0),
            'rating_average'   => number_format((float) ($row['rating_average'] ?? 0), 2, '.', ''),
            'rating_breakdown' => self::breakdownOf($row),
        ];
    }

    /**
     * How many approved reviews gave each number of stars, from a row with rating_1_count ... rating_5_count
     * @param array $row An artifact_master or business_master row
     * @return array<int, int> stars (1-5) => reviews; sent as an object { "1": n, ..., "5": n }
     */
    public static function breakdownOf(array $row): array
    {
        $breakdown = [];
        foreach (self::STARS as $star) {
            $breakdown[$star] = (int) ($row['rating_' . $star . '_count'] ?? 0);
        }

        return $breakdown;
    }

    /**
     * Every rating column from the number of reviews with each number of stars
     * @param array<int, int> $stars stars => reviews
     * @return array<string, int|string>
     */
    private static function ratingOf(array $stars): array
    {
        $sum    = 0;
        $count  = 0;
        $counts = [];
        foreach (self::STARS as $star) {
            $counts['rating_' . $star . '_count'] = $stars[$star] ?? 0;
            $sum                                 += $star * ($stars[$star] ?? 0);
            $count                               += $stars[$star] ?? 0;
        }

        return ['rating_sum' => $sum, 'rating_count' => $count, 'rating_average' => number_format($count === 0 ? 0 : round($sum / $count, 2), 2, '.', '')] + $counts;
    }

    /**
     * Write the rating columns of an artifact_master or business_master row that changed, logged
     * @param string                    $table
     * @param int                       $id
     * @param array<string, int|string> $rating ratingOf()
     * @param int                       $actorId
     * @param ActivityLog               $log
     * @param string                    $what   for the log, as "artifact 12"
     * @return void
     */
    private function writeRating(string $table, int $id, array $rating, int $actorId, ActivityLog $log, string $what): void
    {
        $stored = $this->db->table($table)->select(implode(', ', array_keys($rating)))->where('id', $id)->get()->getRowArray();
        $stored['rating_average'] = number_format((float) $stored['rating_average'], 2, '.', '');
        foreach ($stored as $column => $value) {
            if ($column !== 'rating_average') {
                $stored[$column] = (int) $value;
            }
        }
        $changes = ActivityLog::changes($stored, $rating);
        if ($changes !== []) {
            $this->db->table($table)->where('id', $id)->update($changes);
            $log->updated($actorId, 'Worked out the rating of ' . $what . ' again', $table, $id, $stored, $changes);
        }
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
