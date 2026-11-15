<?php

namespace App\Models;

use App\Libraries\ActivityLog;
use CodeIgniter\Model;
use Throwable;

/**
 * Model for the webpage_image table: the pictures of a section of a business's own page, in sort_order within each
 * image slot of the section's design. Each row's file is named after its public_id. The writes take a callback that
 * puts the file in place or deletes it inside the transaction, so a row and its file never disagree when either fails.
 */
class WebpageImageModel extends Model
{
    use PublicIdTrait;
    use TransactionTrait;

    protected $table            = 'webpage_image';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = ['public_id', 'webpage_id', 'section_id', 'slot_code', 'image_path', 'sort_order'];

    /** public_id: CHAR(12), see PublicIdTrait */
    protected int $publicIdLength = 12;
    protected $beforeInsert       = ['fillPublicId'];

    // created_at and updated_at are maintained by the database
    protected $useTimestamps = false;

    /** Why addImage() refused */
    public const FULL_PAGE = 'page';
    public const FULL_SLOT = 'slot';

    /**
     * Add an image after the slot's others, unless the page has $pageCap images already or the slot $slotMax. The
     * page's row is locked first (on MySQL), so two uploads at once cannot both take the last place.
     * @param array                    $section  The webpage_section row
     * @param string                   $slotCode
     * @param int                      $pageCap  PlanLimits::IMAGE_PER_WEBPAGE of the business's plan
     * @param int|null                 $slotMax  The slot's max; null is the page's cap only
     * @param int                      $actorId
     * @param callable(string): string $urlOf    image_path for a public_id
     * @param callable(string): void   $place    Puts the file of a public_id in place; throws when it cannot
     * @return array|string The row written, or FULL_PAGE or FULL_SLOT
     * @throws Throwable
     */
    public function addImage(array $section, string $slotCode, int $pageCap, ?int $slotMax, int $actorId, callable $urlOf, callable $place): array|string
    {
        $webpageId = (int) $section['webpage_id'];
        $sectionId = (int) $section['id'];
        $refused   = null;
        // a closure, not an arrow function, so $refused comes back out by reference
        $row       = $this->withPublicId(function (string $publicId) use ($webpageId, $sectionId, $slotCode, $pageCap, $slotMax, $actorId, $urlOf, $place, &$refused) {
            return $this->inTransaction(function () use ($publicId, $webpageId, $sectionId, $slotCode, $pageCap, $slotMax, $actorId, $urlOf, $place, &$refused) {
                if ($this->db->DBDriver === 'MySQLi') {
                    $this->db->query('SELECT id FROM ' . $this->db->escapeIdentifiers($this->db->prefixTable('webpage_master')) . ' WHERE id = ? FOR UPDATE', [$webpageId]);
                }
                if ($this->db->table($this->table)->where('webpage_id', $webpageId)->countAllResults() >= $pageCap) {
                    $refused = self::FULL_PAGE;

                    return false;
                }
                $slot = $this->db->table($this->table)->select('COUNT(*) AS images, MAX(sort_order) AS last_sort')
                    ->where('section_id', $sectionId)->where('slot_code', $slotCode)->get()->getRowArray();
                if ($slotMax !== null && (int) $slot['images'] >= $slotMax) {
                    $refused = self::FULL_SLOT;

                    return false;
                }

                $row = [
                    'public_id'  => $publicId,
                    'webpage_id' => $webpageId,
                    'section_id' => $sectionId,
                    'slot_code'  => $slotCode,
                    'image_path' => $urlOf($publicId),
                    'sort_order' => $slot['last_sort'] === null ? 0 : (int) $slot['last_sort'] + 1,
                ];
                $this->db->table($this->table)->insert($row);
                $row = ['id' => (int) $this->db->insertID()] + $row;
                (new ActivityLog())->inserted($actorId, 'Uploaded web page image ' . $publicId, $this->table, $row['id'], $row);
                $place($publicId);

                return $row;
            });
        });

        return $row === false ? $refused : $row;
    }

    /**
     * An image of the page by its public_id, or null when it has none such
     * @param list<int> $webpageIds The pages it may be on: the business's
     * @param mixed     $publicId
     * @return array|null
     */
    public function imageOf(array $webpageIds, mixed $publicId): ?array
    {
        $image = $this->findByPublicId($publicId);

        return $image !== null && in_array((int) $image['webpage_id'], $webpageIds, true) ? $image : null;
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
            (new ActivityLog())->deleted($actorId, 'Deleted web page image ' . $row['public_id'], $this->table, (int) $row['id'], $row);
            $remove();
        });
    }
}
