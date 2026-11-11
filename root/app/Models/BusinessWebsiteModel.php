<?php

namespace App\Models;

use App\Libraries\ActivityLog;
use CodeIgniter\Model;
use Throwable;

/**
 * How a business's web pages look (business_website), in which languages and with what fonts, meta tags and main-page
 * text in each (business_website_language), and the artifacts its main page features (business_website_featured).
 * A business has no business_website row until its settings are first saved, and reads as DEFAULT_COLORS, no logo,
 * no languages and nothing featured until then. Every write is logged to log_activity in the same transaction.
 * The controller checks the values (App\Controllers\Websites); this model only stores them.
 */
class BusinessWebsiteModel extends Model
{
    use TransactionTrait;

    protected $table         = 'business_website';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $allowedFields = ['business_id', 'background_color', 'default_color', 'heading_color', 'accent_color', 'surface_color', 'contrast_color', 'logo_path', 'logo_shape'];

    public const LANGUAGE_TABLE = 'business_website_language';
    public const FEATURED_TABLE = 'business_website_featured';

    /** The colour columns and their defaults, as in the migration: the storefront's own theme */
    public const DEFAULT_COLORS = [
        'background_color' => '#ffffff',
        'default_color'    => '#5a6577',
        'heading_color'    => '#10243d',
        'accent_color'     => '#ff7533',
        'surface_color'    => '#ffffff',
        'contrast_color'   => '#ffffff',
    ];

    /** The columns of a language row the client sets, besides language_code */
    public const LANGUAGE_FIELDS = ['heading_font', 'nav_font', 'default_font', 'meta_title', 'meta_description', 'meta_keywords', 'hero_badge', 'hero_headline', 'hero_paragraph'];

    /** business_website_featured.section */
    public const SECTION_BEST_SELLERS = 'B';
    public const SECTION_TRENDING     = 'T';
    public const SECTIONS             = [self::SECTION_BEST_SELLERS, self::SECTION_TRENDING];

    /** Artifacts per section; sort_order runs from 1 to this */
    public const MAX_FEATURED = 3;

    /**
     * The business's website row, or what it reads as before it has one (id null)
     * @param int $businessId
     * @return array
     */
    public function websiteOf(int $businessId): array
    {
        return $this->where('business_id', $businessId)->first()
            ?? ['id' => null, 'business_id' => $businessId] + self::DEFAULT_COLORS + ['logo_path' => null, 'logo_shape' => null];
    }

    /**
     * The business's languages, the default (lowest sort_order) first
     * @param int $businessId
     * @return list<array> language_code and LANGUAGE_FIELDS
     */
    public function languagesOf(int $businessId): array
    {
        return $this->db->table(self::LANGUAGE_TABLE)
            ->select('language_code, ' . implode(', ', self::LANGUAGE_FIELDS))
            ->where('business_id', $businessId)
            ->orderBy('sort_order')->orderBy('id')
            ->get()->getResultArray();
    }

    /**
     * The featured artifacts of each section, in their order, whatever their status (the page leaves the inactive
     * ones out; the client is shown them so they can be replaced)
     * @param int $businessId
     * @return array<string, list<array{public_id: string, artifact_name: string, artifact_type: string, artifact_status: string}>> by section
     */
    public function featuredOf(int $businessId): array
    {
        $rows = $this->db->table(self::FEATURED_TABLE . ' f')
            ->select('f.section, a.public_id, a.artifact_name, a.artifact_type, a.artifact_status')
            ->join('artifact_master a', 'a.id = f.artifact_id')
            ->where('f.business_id', $businessId)
            ->orderBy('f.section')->orderBy('f.sort_order')
            ->get()->getResultArray();
        $featured = array_fill_keys(self::SECTIONS, []);
        foreach ($rows as $row) {
            $section = $row['section'];
            unset($row['section']);
            $featured[$section][] = $row;
        }

        return $featured;
    }

    /**
     * The business's active artifacts, the ones that may be featured, by name
     * @param int $businessId
     * @return list<array{id: int, public_id: string, artifact_name: string, artifact_type: string}>
     */
    public function featurableOf(int $businessId): array
    {
        $rows = $this->db->table('artifact_master')
            ->select('id, public_id, artifact_name, artifact_type')
            ->where('business_id', $businessId)
            ->where('artifact_status', 'A')
            ->orderBy('artifact_name')->orderBy('id')
            ->get()->getResultArray();

        return array_map(static fn (array $row): array => ['id' => (int) $row['id']] + $row, $rows);
    }

    /**
     * Save any of the three parts of the business's settings, in one transaction; a part given as null is left as
     * it is. The website row is made when the business has none yet.
     * @param int                            $businessId
     * @param array|null                     $colors    every key of DEFAULT_COLORS
     * @param list<array>|null               $languages language_code and LANGUAGE_FIELDS, the default first; replaces
     *                                                  the business's languages, a row being known by its language_code
     * @param array<string, list<int>>|null  $featured  artifact_master.id by section, in order; a section left out is emptied
     * @param int                            $actorId   auth_master.id of whoever makes the change
     * @param string                         $slug      business_master.business_slug, for the log
     * @return void
     * @throws Throwable When a write fails; nothing is written then
     */
    public function saveSettings(int $businessId, ?array $colors, ?array $languages, ?array $featured, int $actorId, string $slug): void
    {
        $log = new ActivityLog();
        $this->inTransaction(function () use ($businessId, $colors, $languages, $featured, $actorId, $slug, $log) {
            $website = $this->ensureWebsite($businessId, $actorId, $slug, $log);
            if ($colors !== null) {
                $change = array_intersect_key($colors, self::DEFAULT_COLORS);
                $this->db->table($this->table)->where('id', $website['id'])->update($change);
                $log->updated($actorId, 'Changed the website colours of business ' . $slug, $this->table, (int) $website['id'], $website, $change);
            }
            if ($languages !== null) {
                $this->replaceLanguages($businessId, $languages, $actorId, $slug, $log);
            }
            if ($featured !== null) {
                $this->replaceFeatured($businessId, $featured, $actorId, $slug, $log);
            }
        });
    }

    /**
     * Make $url the business's logo, in one transaction; $place puts the file there inside it, so a file that will
     * not go keeps the row as it was
     * @param int      $businessId
     * @param string   $url
     * @param string   $shape   business_website.logo_shape
     * @param int      $actorId
     * @param string   $slug    for the log
     * @param callable $place   Throws when the file cannot be put in place
     * @return string|null The logo URL it had before, whose file the caller removes
     * @throws Throwable
     */
    public function setLogo(int $businessId, string $url, string $shape, int $actorId, string $slug, callable $place): ?string
    {
        $log = new ActivityLog();

        return $this->inTransaction(function () use ($businessId, $url, $shape, $actorId, $slug, $place, $log) {
            $website = $this->ensureWebsite($businessId, $actorId, $slug, $log);
            $change  = ['logo_path' => $url, 'logo_shape' => $shape];
            $this->db->table($this->table)->where('id', $website['id'])->update($change);
            $log->updated($actorId, 'Uploaded the logo of business ' . $slug, $this->table, (int) $website['id'], $website, $change);
            $place();

            return $website['logo_path'];
        });
    }

    /**
     * Take the business's logo off, in one transaction; $remove deletes the file inside it
     * @param int      $businessId
     * @param int      $actorId
     * @param string   $slug   for the log
     * @param callable $remove Given the logo URL; throws when the file cannot be deleted
     * @return bool False when the business has no logo
     * @throws Throwable
     */
    public function removeLogo(int $businessId, int $actorId, string $slug, callable $remove): bool
    {
        $log = new ActivityLog();

        return (bool) $this->inTransaction(function () use ($businessId, $actorId, $slug, $remove, $log) {
            $website = $this->where('business_id', $businessId)->first();
            if ($website === null || $website['logo_path'] === null) {
                return false;
            }
            $change = ['logo_path' => null, 'logo_shape' => null];
            $this->db->table($this->table)->where('id', $website['id'])->update($change);
            $log->updated($actorId, 'Deleted the logo of business ' . $slug, $this->table, (int) $website['id'], $website, $change);
            $remove($website['logo_path']);

            return true;
        });
    }

    /**
     * The business's website row, made with the defaults when it has none; inside a transaction
     * @param int         $businessId
     * @param int         $actorId
     * @param string      $slug
     * @param ActivityLog $log
     * @return array
     */
    private function ensureWebsite(int $businessId, int $actorId, string $slug, ActivityLog $log): array
    {
        $website = $this->db->table($this->table)->where('business_id', $businessId)->get()->getRowArray();
        if ($website !== null) {
            return $website;
        }
        $row = ['business_id' => $businessId] + self::DEFAULT_COLORS;
        $this->db->table($this->table)->insert($row);
        $id = (int) $this->db->insertID();
        $log->inserted($actorId, 'Set up the website of business ' . $slug, $this->table, $id, $row);

        return $this->db->table($this->table)->where('id', $id)->get()->getRowArray();
    }

    /**
     * @param int         $businessId
     * @param list<array> $languages
     * @param int         $actorId
     * @param string      $slug
     * @param ActivityLog $log
     * @return void
     */
    private function replaceLanguages(int $businessId, array $languages, int $actorId, string $slug, ActivityLog $log): void
    {
        $table  = $this->db->table(self::LANGUAGE_TABLE);
        $stored = [];
        foreach ($table->where('business_id', $businessId)->get()->getResultArray() as $row) {
            $stored[$row['language_code']] = $row;
        }
        $wanted = [];
        foreach (array_values($languages) as $order => $language) {
            $wanted[$language['language_code']] = ['sort_order' => $order] + array_intersect_key($language, array_flip(self::LANGUAGE_FIELDS));
        }
        foreach ($stored as $code => $row) {
            if (! isset($wanted[$code])) {
                $this->db->table(self::LANGUAGE_TABLE)->where('id', $row['id'])->delete();
                $log->deleted($actorId, 'Removed ' . $code . ' from the website of business ' . $slug, self::LANGUAGE_TABLE, (int) $row['id'], $row);
            }
        }
        foreach ($wanted as $code => $values) {
            if (isset($stored[$code])) {
                $this->db->table(self::LANGUAGE_TABLE)->where('id', $stored[$code]['id'])->update($values);
                $log->updated($actorId, 'Changed the ' . $code . ' website of business ' . $slug, self::LANGUAGE_TABLE, (int) $stored[$code]['id'], $stored[$code], $values);
            } else {
                $row = ['business_id' => $businessId, 'language_code' => $code] + $values;
                $this->db->table(self::LANGUAGE_TABLE)->insert($row);
                $log->inserted($actorId, 'Added ' . $code . ' to the website of business ' . $slug, self::LANGUAGE_TABLE, (int) $this->db->insertID(), $row);
            }
        }
    }

    /**
     * Rewrites the featured rows that change; the unique (section, sort_order) key is why a row whose place changes
     * is deleted and inserted again rather than updated
     * @param int                      $businessId
     * @param array<string, list<int>> $featured
     * @param int                      $actorId
     * @param string                   $slug
     * @param ActivityLog              $log
     * @return void
     */
    private function replaceFeatured(int $businessId, array $featured, int $actorId, string $slug, ActivityLog $log): void
    {
        $stored = [];
        foreach ($this->db->table(self::FEATURED_TABLE)->where('business_id', $businessId)->get()->getResultArray() as $row) {
            $stored[$row['section'] . ' ' . $row['sort_order']] = $row;
        }
        $wanted = [];
        foreach (self::SECTIONS as $section) {
            foreach (array_values($featured[$section] ?? []) as $i => $artifactId) {
                $wanted[$section . ' ' . ($i + 1)] = ['section' => $section, 'sort_order' => $i + 1, 'artifact_id' => (int) $artifactId];
            }
        }
        // first take out what goes or changes, so nothing clashes with the unique keys on the way in
        foreach ($stored as $key => $row) {
            if (! isset($wanted[$key]) || (int) $row['artifact_id'] !== $wanted[$key]['artifact_id']) {
                $this->db->table(self::FEATURED_TABLE)->where('id', $row['id'])->delete();
                $log->deleted($actorId, 'Unfeatured an artifact on the website of business ' . $slug, self::FEATURED_TABLE, (int) $row['id'], $row);
            }
        }
        foreach ($wanted as $key => $values) {
            if (isset($stored[$key]) && (int) $stored[$key]['artifact_id'] === $values['artifact_id']) {
                continue;
            }
            $row = ['business_id' => $businessId] + $values;
            $this->db->table(self::FEATURED_TABLE)->insert($row);
            $log->inserted($actorId, 'Featured an artifact on the website of business ' . $slug, self::FEATURED_TABLE, (int) $this->db->insertID(), $row);
        }
    }
}
