<?php

namespace App\Models;

use App\Libraries\ActivityLog;
use CodeIgniter\Model;
use Throwable;

/**
 * The rows that keep an uploaded image's URL, in whichever table Config\ImageUpload names for its used-for.
 * The table and column come from the config only, never from the request.
 */
class ImageTargetModel extends Model
{
    use TransactionTrait;

    protected $returnType = 'array';

    /**
     * @param string       $table
     * @param list<string> $columns
     * @param int          $id
     * @return array|null The row's id and $columns
     */
    public function findTarget(string $table, array $columns, int $id): ?array
    {
        return $this->db->table($table)
            ->select(array_unique(array_merge(['id'], $columns)))
            ->where('id', $id)
            ->get()
            ->getRowArray();
    }

    /**
     * Save the image URL with its log_activity row; $place puts the file where the URL points, inside the
     * transaction, so the row is never left pointing at a file that failed to land
     * @param string   $table
     * @param string   $column
     * @param array    $row     The stored row, from findTarget()
     * @param string   $url
     * @param int      $actorId auth_master.id of the uploader
     * @param string   $summary
     * @param callable $place   Throws when the file could not be put in place
     * @return void
     * @throws Throwable
     */
    public function setImageUrl(string $table, string $column, array $row, string $url, int $actorId, string $summary, callable $place): void
    {
        $this->inTransaction(function () use ($table, $column, $row, $url, $actorId, $summary, $place) {
            $this->db->table($table)->where('id', $row['id'])->update([$column => $url]);
            (new ActivityLog())->updated($actorId, $summary, $table, (int) $row['id'], $row, [$column => $url]);
            $place();
        });
    }
}
