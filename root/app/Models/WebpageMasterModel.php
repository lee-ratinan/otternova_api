<?php

namespace App\Models;

use App\Libraries\ActivityLog;
use CodeIgniter\Model;
use Throwable;

/**
 * Model for the webpage_master table: the pages a business designs itself, and their names and meta tags per
 * language (webpage_master_translation). The home page is the one with page_slug HOME_SLUG; it is always the first
 * one made, has no names of its own (the language file's Webpage.homeName) and is never deleted.
 * Every write is logged to log_activity in the same transaction. The controller checks the values
 * (App\Controllers\Webpages); this model only stores them, and keeps a business within its plan's page cap.
 */
class WebpageMasterModel extends Model
{
    use PublicIdTrait;
    use TransactionTrait;

    protected $table            = 'webpage_master';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = ['public_id', 'business_id', 'page_slug', 'page_status', 'sort_order'];

    /** public_id: CHAR(12), see PublicIdTrait */
    protected int $publicIdLength = 12;
    protected $beforeInsert       = ['fillPublicId'];

    // created_at and updated_at are maintained by the database
    protected $useTimestamps = false;

    public const TRANSLATION_TABLE = 'webpage_master_translation';

    /** page_slug of the home page, which no other page may have */
    public const HOME_SLUG = 'home';

    /** page_status */
    public const STATUS_DRAFT     = 'D';
    public const STATUS_PUBLISHED = 'P';
    public const STATUSES         = [self::STATUS_DRAFT, self::STATUS_PUBLISHED];

    /** The longest page_slug, as its column allows */
    public const MAX_SLUG = 64;

    /** The columns of a translation the client sets, besides language_code */
    public const NAME_FIELDS = ['page_name', 'meta_title', 'meta_description'];

    /**
     * The business's pages in menu order, the home page first
     * @param int $businessId
     * @return list<array> The rows, each with image_count
     */
    public function pagesOf(int $businessId): array
    {
        $images = $this->db->table('webpage_image')->select('webpage_id, COUNT(*) AS image_count')->groupBy('webpage_id')->getCompiledSelect();

        return $this->db->table($this->table . ' p')
            ->select('p.*, COALESCE(i.image_count, 0) AS image_count')
            ->join('(' . $images . ') i', 'i.webpage_id = p.id', 'left', false)
            ->where('p.business_id', $businessId)
            ->orderBy('p.page_slug = ' . $this->db->escape(self::HOME_SLUG), 'DESC', false)
            ->orderBy('p.sort_order')->orderBy('p.id')
            ->get()->getResultArray();
    }

    /**
     * A page of the business by its public_id, or null when it has none such
     * @param int   $businessId
     * @param mixed $publicId From the request
     * @return array|null
     */
    public function pageOf(int $businessId, mixed $publicId): ?array
    {
        $page = $this->findByPublicId($publicId);

        return $page !== null && (int) $page['business_id'] === $businessId ? $page : null;
    }

    /**
     * The business's home page, or null before it has made one
     * @param int $businessId
     * @return array|null
     */
    public function homeOf(int $businessId): ?array
    {
        return $this->where('business_id', $businessId)->where('page_slug', self::HOME_SLUG)->first();
    }

    /**
     * The names of pages, by page and language
     * @param list<int> $webpageIds
     * @return array<int, array<string, array{page_name: string, meta_title: string|null, meta_description: string|null}>>
     */
    public function namesOf(array $webpageIds): array
    {
        if ($webpageIds === []) {
            return [];
        }
        $names = [];
        foreach ($this->db->table(self::TRANSLATION_TABLE)->whereIn('webpage_id', $webpageIds)->orderBy('id')->get()->getResultArray() as $row) {
            $names[(int) $row['webpage_id']][$row['language_code']] = array_intersect_key($row, array_flip(self::NAME_FIELDS));
        }

        return $names;
    }

    /**
     * The languages in which another page of the business has the name $names gives it
     * @param int                   $businessId
     * @param array<string, string> $names     language_code => page_name
     * @param int|null              $exceptId  The page itself, when it is being renamed
     * @return list<string> language_code
     */
    public function namesTaken(int $businessId, array $names, ?int $exceptId): array
    {
        $taken = [];
        foreach ($names as $code => $name) {
            $builder = $this->db->table(self::TRANSLATION_TABLE)->where('business_id', $businessId)->where('language_code', $code)->where('page_name', $name);
            if ($exceptId !== null) {
                $builder->where('webpage_id !=', $exceptId);
            }
            if ($builder->countAllResults() > 0) {
                $taken[] = $code;
            }
        }

        return $taken;
    }

    /**
     * Whether another page of the business has the slug
     * @param int      $businessId
     * @param string   $slug
     * @param int|null $exceptId
     * @return bool
     */
    public function slugTaken(int $businessId, string $slug, ?int $exceptId): bool
    {
        $builder = $this->db->table($this->table)->where('business_id', $businessId)->where('page_slug', $slug);
        if ($exceptId !== null) {
            $builder->where('id !=', $exceptId);
        }

        return $builder->countAllResults() > 0;
    }

    /**
     * Make a page, a draft at the end of the menu, unless the business has $cap pages already. The website's row is
     * locked first (on MySQL), so two at once cannot both take the last place.
     * @param int                 $businessId
     * @param string              $slug      HOME_SLUG for the home page
     * @param bool                $exactSlug False: $slug is made free with -2, -3...; true: it is used as it is
     * @param array<string, array> $names    language_code => NAME_FIELDS; [] for the home page
     * @param int                 $cap       PlanLimits::WEBPAGES of the business's plan
     * @param int                 $actorId
     * @param string              $businessSlug For the log
     * @return array|null The page, or null when the business has $cap pages already
     * @throws Throwable
     */
    public function createPage(int $businessId, string $slug, bool $exactSlug, array $names, int $cap, int $actorId, string $businessSlug): ?array
    {
        $page = $this->withPublicId(fn (string $publicId) => $this->inTransaction(function () use ($publicId, $businessId, $slug, $exactSlug, $names, $cap, $actorId, $businessSlug) {
            if ($this->db->DBDriver === 'MySQLi') {
                $this->db->query('SELECT id FROM ' . $this->db->escapeIdentifiers($this->db->prefixTable('business_website')) . ' WHERE business_id = ? FOR UPDATE', [$businessId]);
            }
            $last = $this->db->table($this->table)->select('COUNT(*) AS pages, MAX(sort_order) AS last_sort')->where('business_id', $businessId)->get()->getRowArray();
            if ((int) $last['pages'] >= $cap) {
                return false;
            }
            if (! $exactSlug) {
                $slug = $this->freeSlug($businessId, $slug);
            }
            $row = [
                'public_id'   => $publicId,
                'business_id' => $businessId,
                'page_slug'   => $slug,
                'page_status' => self::STATUS_DRAFT,
                'sort_order'  => $slug === self::HOME_SLUG || $last['last_sort'] === null ? 0 : (int) $last['last_sort'] + 1,
            ];
            $this->db->table($this->table)->insert($row);
            $row = ['id' => (int) $this->db->insertID()] + $row;
            $log = new ActivityLog();
            $log->inserted($actorId, 'Created web page ' . $slug . ' of business ' . $businessSlug, $this->table, $row['id'], $row);
            $this->insertNames($businessId, $row['id'], $names, $actorId, $log);

            return $row;
        }));

        return $page === false ? null : $page;
    }

    /**
     * Change a page: the parts given; a null part stays as it is
     * @param array                     $page    The stored row
     * @param array<string, array>|null $names   language_code => NAME_FIELDS; replaces the names it had
     * @param string|null               $slug
     * @param string|null               $status
     * @param int                       $actorId
     * @param string                    $businessSlug
     * @return array The page as stored now
     * @throws Throwable
     */
    public function editPage(array $page, ?array $names, ?string $slug, ?string $status, int $actorId, string $businessSlug): array
    {
        return $this->inTransaction(function () use ($page, $names, $slug, $status, $actorId, $businessSlug) {
            $log     = new ActivityLog();
            $id      = (int) $page['id'];
            $changes = array_filter(['page_slug' => $slug, 'page_status' => $status], static fn ($value): bool => $value !== null);
            $changes = ActivityLog::changes($page, $changes);
            if ($changes !== []) {
                $this->db->table($this->table)->where('id', $id)->update($changes);
                $log->updated($actorId, 'Edited web page ' . $page['page_slug'] . ' of business ' . $businessSlug, $this->table, $id, $page, $changes);
            }
            if ($names !== null) {
                $table = $this->db->table(self::TRANSLATION_TABLE);
                foreach ($table->where('webpage_id', $id)->get()->getResultArray() as $old) {
                    $this->db->table(self::TRANSLATION_TABLE)->where('id', $old['id'])->delete();
                    $log->deleted($actorId, 'Replaced the names of web page ' . $page['page_slug'], self::TRANSLATION_TABLE, (int) $old['id'], $old);
                }
                $this->insertNames((int) $page['business_id'], $id, $names, $actorId, $log);
            }

            return $this->db->table($this->table)->where('id', $id)->get()->getRowArray();
        });
    }

    /**
     * Delete a page, its names, sections, texts and images with it (the foreign keys cascade). The image files are
     * the caller's to delete, once this has succeeded.
     * @param array  $page    The stored row; never the home page, which the controller refuses
     * @param int    $actorId
     * @param string $businessSlug
     * @return list<string> The public_ids of the images deleted with it
     * @throws Throwable
     */
    public function deletePage(array $page, int $actorId, string $businessSlug): array
    {
        return $this->inTransaction(function () use ($page, $actorId, $businessSlug) {
            $images = array_column($this->db->table('webpage_image')->select('public_id')->where('webpage_id', $page['id'])->get()->getResultArray(), 'public_id');
            $this->db->table($this->table)->where('id', $page['id'])->delete();
            (new ActivityLog())->deleted($actorId, 'Deleted web page ' . $page['page_slug'] . ' of business ' . $businessSlug, $this->table, (int) $page['id'], $page);

            return $images;
        });
    }

    /**
     * Put the business's pages in the menu order of $publicIds, which lists each of them once, the home page first
     * @param int          $businessId
     * @param list<string> $publicIds
     * @param int          $actorId
     * @return bool False, writing nothing, when $publicIds is not exactly the pages, or the home page is not first
     * @throws Throwable
     */
    public function reorder(int $businessId, array $publicIds, int $actorId): bool
    {
        return $this->inTransaction(function () use ($businessId, $publicIds, $actorId) {
            $stored = [];
            foreach ($this->db->table($this->table)->where('business_id', $businessId)->get()->getResultArray() as $row) {
                $stored[$row['public_id']] = $row;
            }
            if (count($publicIds) !== count($stored) || count(array_unique($publicIds)) !== count($publicIds) || array_diff($publicIds, array_keys($stored)) !== []
                || $stored[$publicIds[0] ?? '']['page_slug'] !== self::HOME_SLUG) {
                return false;
            }
            $log = new ActivityLog();
            foreach (array_values($publicIds) as $sort => $publicId) {
                $row = $stored[$publicId];
                if ((int) $row['sort_order'] !== $sort) {
                    $this->db->table($this->table)->where('id', $row['id'])->update(['sort_order' => $sort]);
                    $log->updated($actorId, 'Reordered web pages', $this->table, (int) $row['id'], $row, ['sort_order' => $sort]);
                }
            }

            return true;
        });
    }

    /**
     * The business's published pages in menu order, the home page first, each with its name in $language (else the
     * default language's), for the storefront's menu
     * @param int    $businessId
     * @param string $language
     * @param string $default  The website's default language
     * @return list<array{public_id: string, slug: string, is_home: bool, name: string|null}> name null for the home page
     */
    public function menuOf(int $businessId, string $language, string $default): array
    {
        $pages = array_values(array_filter($this->pagesOf($businessId), static fn (array $page): bool => $page['page_status'] === self::STATUS_PUBLISHED));
        $names = $this->namesOf(array_map('intval', array_column($pages, 'id')));

        return array_map(static function (array $page) use ($names, $language, $default): array {
            $own = $names[(int) $page['id']] ?? [];

            return [
                'public_id' => $page['public_id'],
                'slug'      => $page['page_slug'],
                'is_home'   => $page['page_slug'] === self::HOME_SLUG,
                'name'      => ($own[$language] ?? $own[$default] ?? null)['page_name'] ?? null,
            ];
        }, $pages);
    }

    /**
     * A published page as the storefront shows it, in $language, a text it has none in being the default
     * language's; null when the business has no published page with the slug (a draft is never shown)
     * @param int    $businessId
     * @param string $slug     HOME_SLUG for the home page
     * @param string $language
     * @param string $default  The website's default language
     * @return array{page: array, menu: list<array>, sections: list<array>}|null
     *         page {public_id, slug, is_home, name (null for the home page), meta: {title, description}};
     *         sections {public_id, section_type, design_code, texts: {slot_code: text}, images: {slot_code: [url]}}
     */
    public function publishedPage(int $businessId, string $slug, string $language, string $default): ?array
    {
        $page = $this->where('business_id', $businessId)->where('page_slug', $slug)->where('page_status', self::STATUS_PUBLISHED)->first();
        if ($page === null) {
            return null;
        }
        $names = $this->namesOf([(int) $page['id']])[(int) $page['id']] ?? [];
        $name  = $names[$language] ?? $names[$default] ?? null;

        $sections = array_map(static function (array $section) use ($language, $default): array {
            $images = [];
            foreach ($section['images'] as $image) {
                $images[$image['slot_code']][] = $image['url'];
            }

            return [
                'public_id'    => $section['public_id'],
                'section_type' => $section['section_type'],
                'design_code'  => $section['design_code'],
                'texts'        => ($section['texts'][$language] ?? []) + ($section['texts'][$default] ?? []),
                'images'       => $images,
            ];
        }, model(WebpageSectionModel::class)->sectionsOf((int) $page['id']));

        return [
            'page'     => [
                'public_id' => $page['public_id'],
                'slug'      => $page['page_slug'],
                'is_home'   => $page['page_slug'] === self::HOME_SLUG,
                'name'      => $name['page_name'] ?? null,
                'meta'      => ['title' => $name['meta_title'] ?? null, 'description' => $name['meta_description'] ?? null],
            ],
            'menu'     => $this->menuOf($businessId, $language, $default),
            'sections' => $sections,
        ];
    }

    /**
     * $slug, or $slug-2, $slug-3... whichever the business has no page with yet; never HOME_SLUG for another page
     * @param int    $businessId
     * @param string $slug
     * @return string
     */
    private function freeSlug(int $businessId, string $slug): string
    {
        if ($slug === self::HOME_SLUG && $this->homeOf($businessId) === null) {
            return $slug;
        }
        $taken = array_flip(array_column($this->db->table($this->table)->select('page_slug')->where('business_id', $businessId)->get()->getResultArray(), 'page_slug'));
        $taken[self::HOME_SLUG] = true;
        for ($n = 1; ; $n++) {
            $suffix    = $n === 1 ? '' : '-' . $n;
            $candidate = rtrim(substr($slug, 0, self::MAX_SLUG - strlen($suffix)), '-') . $suffix;
            if (! isset($taken[$candidate])) {
                return $candidate;
            }
        }
    }

    /**
     * @param int                  $businessId
     * @param int                  $webpageId
     * @param array<string, array> $names
     * @param int                  $actorId
     * @param ActivityLog          $log
     * @return void
     */
    private function insertNames(int $businessId, int $webpageId, array $names, int $actorId, ActivityLog $log): void
    {
        foreach ($names as $code => $name) {
            $row = ['business_id' => $businessId, 'webpage_id' => $webpageId, 'language_code' => $code] + array_intersect_key($name, array_flip(self::NAME_FIELDS));
            $this->db->table(self::TRANSLATION_TABLE)->insert($row);
            $log->inserted($actorId, 'Named web page ' . $webpageId . ' in ' . $code, self::TRANSLATION_TABLE, (int) $this->db->insertID(), $row);
        }
    }
}
