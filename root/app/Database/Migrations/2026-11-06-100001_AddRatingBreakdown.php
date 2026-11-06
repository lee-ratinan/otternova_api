<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Adds how many approved reviews gave each number of stars, so a rating can be shown with its breakdown, not only its
 * average:
 * - artifact_master.rating_1_count ... rating_5_count, after rating_average: the approved artifact_review rows of the
 *   artifact with that rating
 * - business_master.rating_sum, rating_count, rating_average and rating_1_count ... rating_5_count: the same for every
 *   artifact of the business together (the sums of its artifacts' columns; rating_average = rating_sum / rating_count,
 *   rounded, 0 when none)
 * ArtifactReviewModel::setStatus() keeps them up to date as reviews are approved or hidden; this fills them for the
 * reviews already there, working the artifacts' rating_sum, rating_count and rating_average out again with them.
 * Depends on artifact_master, artifact_review and business_master.
 */
class AddRatingBreakdown extends Migration
{
    private const STARS = [1, 2, 3, 4, 5];

    public function up()
    {
        $counts = [];
        $after  = 'rating_average';
        foreach (self::STARS as $star) {
            $counts['rating_' . $star . '_count'] = ['type' => 'INT', 'unsigned' => true, 'default' => 0, 'after' => $after];
            $after                                 = 'rating_' . $star . '_count';
        }
        $this->forge->addColumn('artifact_master', $counts);
        $this->forge->addColumn('business_master', [
            'rating_sum'     => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'rating_count'   => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'rating_average' => ['type' => 'DECIMAL', 'constraint' => '3,2', 'default' => '0.00'], // 0.00-5.00
        ] + array_map(static fn (array $column): array => array_diff_key($column, ['after' => true]), $counts));

        $this->backfill();
    }

    public function down()
    {
        $counts = array_map(static fn (int $star): string => 'rating_' . $star . '_count', self::STARS);
        $this->rebuildingTables(function () use ($counts): void {
            $this->forge->dropColumn('artifact_master', $counts);
            $this->forge->dropColumn('business_master', ['rating_sum', 'rating_count', 'rating_average', ...$counts]);
        });
    }

    /**
     * Fill the new columns from the approved reviews there already
     * @return void
     */
    private function backfill(): void
    {
        $byArtifact = [];
        $rows       = $this->db->table('artifact_review')
            ->select('artifact_id, rating, COUNT(*) AS reviews')
            ->where('review_status', 'A')
            ->groupBy('artifact_id, rating')
            ->get()
            ->getResultArray();
        foreach ($rows as $row) {
            $byArtifact[(int) $row['artifact_id']][(int) $row['rating']] = (int) $row['reviews'];
        }
        foreach ($byArtifact as $artifactId => $stars) {
            $this->db->table('artifact_master')->where('id', $artifactId)->update(self::totals($stars));
        }

        $sums = implode(', ', array_map(static fn (string $column): string => 'SUM(' . $column . ') AS ' . $column, ['rating_sum', 'rating_count', ...array_map(static fn (int $star): string => 'rating_' . $star . '_count', self::STARS)]));
        $rows = $this->db->table('artifact_master')->select('business_id, ' . $sums, false)->where('rating_count >', 0)->groupBy('business_id')->get()->getResultArray();
        foreach ($rows as $row) {
            $stars = [];
            foreach (self::STARS as $star) {
                $stars[$star] = (int) $row['rating_' . $star . '_count'];
            }
            $this->db->table('business_master')->where('id', (int) $row['business_id'])->update(self::totals($stars));
        }
    }

    /**
     * The rating columns from the number of reviews with each rating
     * @param array<int, int> $stars rating => reviews
     * @return array<string, int|string>
     */
    private static function totals(array $stars): array
    {
        $totals = [];
        $sum    = 0;
        $count  = 0;
        foreach (self::STARS as $star) {
            $reviews                            = $stars[$star] ?? 0;
            $totals['rating_' . $star . '_count'] = $reviews;
            $sum                               += $star * $reviews;
            $count                             += $reviews;
        }

        return ['rating_sum' => $sum, 'rating_count' => $count, 'rating_average' => number_format($count === 0 ? 0 : round($sum / $count, 2), 2, '.', '')] + $totals;
    }

    /**
     * SQLite (the test database) drops columns by rebuilding the table; the legacy rename keeps the other tables'
     * foreign keys pointing at it (see AddBusinessAudience)
     * @param callable $work
     * @return void
     */
    private function rebuildingTables(callable $work): void
    {
        $sqlite = $this->db->DBDriver === 'SQLite3';
        if ($sqlite) {
            $this->db->query('PRAGMA legacy_alter_table = ON');
        }
        try {
            $work();
        } finally {
            if ($sqlite) {
                $this->db->query('PRAGMA legacy_alter_table = OFF');
            }
        }
    }
}
