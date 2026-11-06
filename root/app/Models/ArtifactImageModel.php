<?php

namespace App\Models;

use App\Libraries\ActivityLog;
use CodeIgniter\Model;
use Throwable;

/**
 * Model for the artifact_image table: the pictures of an artifact, in sort_order, the lowest being the cover.
 * Each row's file is named after its public_id. The writes take a callback that puts the file in place or deletes it
 * inside the transaction, so a row and its file never disagree when either fails.
 */
class ArtifactImageModel extends Model
{
    use PublicIdTrait;
    use TransactionTrait;

    protected $table            = 'artifact_image';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = ['public_id', 'artifact_id', 'variant_id', 'image_path', 'sort_order'];

    /** public_id: CHAR(12), see PublicIdTrait */
    protected int $publicIdLength = 12;
    protected $beforeInsert       = ['fillPublicId'];

    // created_at and updated_at are maintained by the database
    protected $useTimestamps = false;

    /**
     * The images of an artifact as the API sends them, cover first
     * @param int $artifactId
     * @return list<array{public_id: string, variant_public_id: string|null, url: string, sort_order: int}>
     */
    public function listOfArtifact(int $artifactId): array
    {
        $rows = $this->db->table($this->table . ' i')
            ->select('i.public_id, v.public_id AS variant_public_id, i.image_path AS url, i.sort_order')
            ->join('artifact_variant v', 'v.id = i.variant_id', 'left')
            ->where('i.artifact_id', $artifactId)
            ->orderBy('i.sort_order')
            ->orderBy('i.id')
            ->get()
            ->getResultArray();

        return array_map(static fn (array $row): array => ['sort_order' => (int) $row['sort_order']] + $row, $rows);
    }

    /**
     * Add an image after the artifact's others, unless it has $max already. The artifact's row is locked first
     * (on MySQL), so two uploads at once cannot both take the last place.
     * @param int                     $artifactId
     * @param int|null                $variantId
     * @param int                     $max
     * @param int                     $actorId auth_master.id of the uploader
     * @param callable(string): string $urlOf  image_path for a public_id
     * @param callable(string): void   $place  Puts the file of a public_id in place; throws when it cannot
     * @return array|null The row written, or null when the artifact has $max images already
     * @throws Throwable
     */
    public function addImage(int $artifactId, ?int $variantId, int $max, int $actorId, callable $urlOf, callable $place): ?array
    {
        $row = $this->withPublicId(fn (string $publicId) => $this->inTransaction(function () use ($publicId, $artifactId, $variantId, $max, $actorId, $urlOf, $place) {
            if ($this->db->DBDriver === 'MySQLi') {
                $this->db->query('SELECT id FROM ' . $this->db->escapeIdentifiers($this->db->prefixTable('artifact_master')) . ' WHERE id = ? FOR UPDATE', [$artifactId]);
            }
            $last = $this->db->table($this->table)
                ->select('COUNT(*) AS images, MAX(sort_order) AS last_sort')
                ->where('artifact_id', $artifactId)
                ->get()
                ->getRowArray();
            if ((int) $last['images'] >= $max) {
                return false;
            }

            $row = [
                'public_id'   => $publicId,
                'artifact_id' => $artifactId,
                'variant_id'  => $variantId,
                'image_path'  => $urlOf($publicId),
                'sort_order'  => $last['last_sort'] === null ? 0 : (int) $last['last_sort'] + 1,
            ];
            $this->db->table($this->table)->insert($row);
            $row = ['id' => (int) $this->db->insertID()] + $row;
            (new ActivityLog())->inserted($actorId, 'Uploaded artifact image ' . $publicId, $this->table, $row['id'], $row);
            $place($publicId);

            return $row;
        }));

        return $row === false ? null : $row;
    }

    /**
     * Delete an image, with $remove deleting its file in the same transaction
     * @param array    $row     The stored row
     * @param int      $actorId
     * @param callable $remove  Throws when the file cannot be deleted, which keeps the row
     * @return void
     * @throws Throwable
     */
    public function deleteImage(array $row, int $actorId, callable $remove): void
    {
        $this->inTransaction(function () use ($row, $actorId, $remove) {
            $this->db->table($this->table)->where('id', $row['id'])->delete();
            (new ActivityLog())->deleted($actorId, 'Deleted artifact image ' . $row['public_id'], $this->table, (int) $row['id'], $row);
            $remove();
        });
    }

    /**
     * Give the artifact's images sort_order 0, 1, 2... in the order of $publicIds, which must list each of them
     * exactly once
     * @param int          $artifactId
     * @param list<string> $publicIds
     * @param int          $actorId
     * @return bool False, writing nothing, when $publicIds is not exactly the artifact's images
     * @throws Throwable
     */
    public function reorder(int $artifactId, array $publicIds, int $actorId): bool
    {
        return $this->inTransaction(function () use ($artifactId, $publicIds, $actorId) {
            $stored = [];
            foreach ($this->db->table($this->table)->where('artifact_id', $artifactId)->get()->getResultArray() as $row) {
                $stored[$row['public_id']] = $row;
            }
            if (count($publicIds) !== count($stored) || count(array_unique($publicIds)) !== count($publicIds) || array_diff($publicIds, array_keys($stored)) !== []) {
                return false;
            }

            $log = new ActivityLog();
            foreach (array_values($publicIds) as $sort => $publicId) {
                $row = $stored[$publicId];
                if ((int) $row['sort_order'] !== $sort) {
                    $this->db->table($this->table)->where('id', $row['id'])->update(['sort_order' => $sort]);
                    $log->updated($actorId, 'Reordered artifact images', $this->table, (int) $row['id'], $row, ['sort_order' => $sort]);
                }
            }

            return true;
        });
    }
}
