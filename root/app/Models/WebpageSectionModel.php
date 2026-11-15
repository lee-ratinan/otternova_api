<?php

namespace App\Models;

use App\Libraries\ActivityLog;
use CodeIgniter\Model;
use Config\WebpageDesigns;
use Throwable;

/**
 * Model for the webpage_section table: the sections of a business's own page, top to bottom by sort_order, the hero
 * at 0 (Config\WebpageDesigns), and their texts per language and slot (webpage_section_text). Their images are
 * WebpageImageModel's; this one deletes those that no longer fit when a section changes its design, and leaves
 * their files to the caller. Every write is logged to log_activity in the same transaction.
 */
class WebpageSectionModel extends Model
{
    use PublicIdTrait;
    use TransactionTrait;

    protected $table            = 'webpage_section';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = ['public_id', 'webpage_id', 'section_type', 'design_code', 'sort_order'];

    /** public_id: CHAR(12), see PublicIdTrait */
    protected int $publicIdLength = 12;
    protected $beforeInsert       = ['fillPublicId'];

    // created_at and updated_at are maintained by the database
    protected $useTimestamps = false;

    public const TEXT_TABLE  = 'webpage_section_text';
    public const IMAGE_TABLE = 'webpage_image';

    /**
     * A section of the page by its public_id, or null when it has none such
     * @param int   $webpageId
     * @param mixed $publicId From the request
     * @return array|null
     */
    public function sectionOf(int $webpageId, mixed $publicId): ?array
    {
        $section = $this->findByPublicId($publicId);

        return $section !== null && (int) $section['webpage_id'] === $webpageId ? $section : null;
    }

    /**
     * The page's sections, top to bottom, with their texts and images
     * @param int $webpageId
     * @return list<array{public_id: string, section_type: string, design_code: string, sort_order: int, texts: array<string, array<string, string>>, images: list<array>}>
     *         texts by language_code and slot_code; images {public_id, slot_code, url, sort_order}, by slot and order
     */
    public function sectionsOf(int $webpageId): array
    {
        $sections = $this->db->table($this->table)->where('webpage_id', $webpageId)->orderBy('sort_order')->get()->getResultArray();
        if ($sections === []) {
            return [];
        }
        $ids   = array_map('intval', array_column($sections, 'id'));
        $texts = [];
        foreach ($this->db->table(self::TEXT_TABLE)->whereIn('section_id', $ids)->orderBy('id')->get()->getResultArray() as $row) {
            $texts[(int) $row['section_id']][$row['language_code']][$row['slot_code']] = $row['slot_text'];
        }
        $images = [];
        foreach ($this->db->table(self::IMAGE_TABLE)->whereIn('section_id', $ids)->orderBy('slot_code')->orderBy('sort_order')->orderBy('id')->get()->getResultArray() as $row) {
            $images[(int) $row['section_id']][] = ['public_id' => $row['public_id'], 'slot_code' => $row['slot_code'], 'url' => $row['image_path'], 'sort_order' => (int) $row['sort_order']];
        }

        return array_map(static fn (array $section): array => [
            'public_id'    => $section['public_id'],
            'section_type' => $section['section_type'],
            'design_code'  => $section['design_code'],
            'sort_order'   => (int) $section['sort_order'],
            'texts'        => $texts[(int) $section['id']] ?? [],
            'images'       => $images[(int) $section['id']] ?? [],
        ], $sections);
    }

    /**
     * Add a section of $designCode to the page: a hero at 0, any other after the page's last
     * @param int                                 $webpageId
     * @param string                              $designCode A key of WebpageDesigns::$designs
     * @param array<string, array<string, string>> $texts     language_code => slot_code => text, the empty ones left out
     * @param int                                 $actorId
     * @return array The section's row
     * @throws Throwable The database's refusal when the page has a hero already, among others
     */
    public function addSection(int $webpageId, string $designCode, array $texts, int $actorId): array
    {
        $type = config(WebpageDesigns::class)->designs[$designCode]['section_type'];

        return $this->withPublicId(fn (string $publicId) => $this->inTransaction(function () use ($publicId, $webpageId, $designCode, $type, $texts, $actorId) {
            $last = $this->db->table($this->table)->selectMax('sort_order', 'last_sort')->where('webpage_id', $webpageId)->get()->getRowArray()['last_sort'];
            $row  = [
                'public_id'    => $publicId,
                'webpage_id'   => $webpageId,
                'section_type' => $type,
                'design_code'  => $designCode,
                'sort_order'   => $type === WebpageDesigns::TYPE_HERO ? 0 : max(1, $last === null ? 1 : (int) $last + 1),
            ];
            $this->db->table($this->table)->insert($row);
            $row = ['id' => (int) $this->db->insertID()] + $row;
            $log = new ActivityLog();
            $log->inserted($actorId, 'Added a ' . $type . ' section to web page ' . $webpageId, $this->table, $row['id'], $row);
            $this->insertTexts($row['id'], $texts, $actorId, $log);

            return $row;
        }));
    }

    /**
     * Change a section's design and texts. A new design keeps the texts whose slot_code it has too (when no texts are
     * given), and the images that fit it: those of a slot_code it has, of the same size, as many as the slot takes
     * (a slot without max takes $imageCap); the rest are deleted.
     * @param array                                     $section  The stored row
     * @param string                                    $designCode
     * @param array<string, array<string, string>>|null $texts    Replace the texts it had; null keeps them
     * @param int                                       $imageCap PlanLimits::IMAGE_PER_WEBPAGE of the business's plan
     * @param int                                       $actorId
     * @return list<string> The public_ids of the images deleted, whose files the caller deletes
     * @throws Throwable
     */
    public function editSection(array $section, string $designCode, ?array $texts, int $imageCap, int $actorId): array
    {
        $designs = config(WebpageDesigns::class);
        $new     = $designs->designs[$designCode];
        $old     = $designs->design($section['design_code']);

        return $this->inTransaction(function () use ($section, $designCode, $texts, $imageCap, $actorId, $new, $old) {
            $log     = new ActivityLog();
            $id      = (int) $section['id'];
            $dropped = [];
            if ($designCode !== $section['design_code']) {
                $this->db->table($this->table)->where('id', $id)->update(['design_code' => $designCode]);
                $log->updated($actorId, 'Changed the design of web page section ' . $section['public_id'], $this->table, $id, $section, ['design_code' => $designCode]);

                $kept = [];
                foreach ($this->db->table(self::IMAGE_TABLE)->where('section_id', $id)->orderBy('sort_order')->orderBy('id')->get()->getResultArray() as $image) {
                    $slot    = $image['slot_code'];
                    $oldSlot = $old['images'][$slot] ?? null;
                    $newSlot = $new['images'][$slot] ?? null;
                    $fits    = $oldSlot !== null && $newSlot !== null
                        && $oldSlot['width'] === $newSlot['width'] && $oldSlot['height'] === $newSlot['height']
                        && ($kept[$slot] ?? 0) < ($newSlot['max'] ?? $imageCap);
                    if ($fits) {
                        $kept[$slot] = ($kept[$slot] ?? 0) + 1;
                        continue;
                    }
                    $this->db->table(self::IMAGE_TABLE)->where('id', $image['id'])->delete();
                    $log->deleted($actorId, 'Deleted web page image ' . $image['public_id'] . ' that the new design has no place for', self::IMAGE_TABLE, (int) $image['id'], $image);
                    $dropped[] = $image['public_id'];
                }
            }

            foreach ($this->db->table(self::TEXT_TABLE)->where('section_id', $id)->get()->getResultArray() as $text) {
                if ($texts !== null || ! isset($new['texts'][$text['slot_code']])) {
                    $this->db->table(self::TEXT_TABLE)->where('id', $text['id'])->delete();
                    $log->deleted($actorId, 'Replaced the texts of web page section ' . $section['public_id'], self::TEXT_TABLE, (int) $text['id'], $text);
                }
            }
            if ($texts !== null) {
                $this->insertTexts($id, $texts, $actorId, $log);
            }

            return $dropped;
        });
    }

    /**
     * Delete a section, its texts and images with it (the foreign keys cascade)
     * @param array $section The stored row
     * @param int   $actorId
     * @return list<string> The public_ids of the images deleted with it, whose files the caller deletes
     * @throws Throwable
     */
    public function deleteSection(array $section, int $actorId): array
    {
        return $this->inTransaction(function () use ($section, $actorId) {
            $images = array_column($this->db->table(self::IMAGE_TABLE)->select('public_id')->where('section_id', $section['id'])->get()->getResultArray(), 'public_id');
            $this->db->table($this->table)->where('id', $section['id'])->delete();
            (new ActivityLog())->deleted($actorId, 'Deleted web page section ' . $section['public_id'], $this->table, (int) $section['id'], $section);

            return $images;
        });
    }

    /**
     * @param int                                 $sectionId
     * @param array<string, array<string, string>> $texts
     * @param int                                 $actorId
     * @param ActivityLog                         $log
     * @return void
     */
    private function insertTexts(int $sectionId, array $texts, int $actorId, ActivityLog $log): void
    {
        foreach ($texts as $code => $slots) {
            foreach ($slots as $slot => $text) {
                $row = ['section_id' => $sectionId, 'language_code' => $code, 'slot_code' => $slot, 'slot_text' => $text];
                $this->db->table(self::TEXT_TABLE)->insert($row);
                $log->inserted($actorId, 'Wrote the ' . $slot . ' of web page section ' . $sectionId . ' in ' . $code, self::TEXT_TABLE, (int) $this->db->insertID(), $row);
            }
        }
    }
}
