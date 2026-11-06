<?php

namespace App\Models;

use App\Libraries\ActivityLog;
use CodeIgniter\Model;
use Throwable;

/**
 * Model for the artifact_category table: a business's own grouping of its artifacts, with its names in other
 * languages in artifact_category_translation. Categories are never deleted; category_status I hides one.
 */
class ArtifactCategoryModel extends Model
{
    use PublicIdTrait;
    use TransactionTrait;
    use TranslationWriteTrait;

    // category_status
    public const STATUS_ACTIVE   = 'A';
    public const STATUS_INACTIVE = 'I';

    /** The translation table and its text */
    private const TRANSLATIONS = 'artifact_category_translation';

    protected $table            = 'artifact_category';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = ['public_id', 'business_id', 'category_name', 'category_status', 'sort_order'];

    /** public_id: CHAR(12), see PublicIdTrait */
    protected int $publicIdLength = 12;
    protected $beforeInsert       = ['fillPublicId'];

    // created_at and updated_at are maintained by the database
    protected $useTimestamps = false;

    protected $validationRules = [
        'business_id'     => 'required|is_natural_no_zero',
        'category_name'   => 'required|max_length[255]',
        'category_status' => 'required|in_list[A,I]',
        'sort_order'      => 'required|is_natural|less_than_equal_to[65535]',
    ];

    /**
     * The categories of a business in their order, each with its names in other languages and how many artifacts it
     * has. The ids stay inside.
     * @param int $businessId
     * @return list<array{public_id: string, category_name: string, category_status: string, sort_order: int, artifact_count: int, translations: array<string, string>}>
     */
    public function listOfBusiness(int $businessId): array
    {
        $rows = $this->db->table($this->table . ' c')
            ->select('c.id, c.public_id, c.category_name, c.category_status, c.sort_order, COUNT(a.id) AS artifact_count')
            ->join('artifact_master a', 'a.category_id = c.id', 'left')
            ->where('c.business_id', $businessId)
            ->groupBy('c.id, c.public_id, c.category_name, c.category_status, c.sort_order')
            ->orderBy('c.sort_order')
            ->orderBy('c.category_name')
            ->get()
            ->getResultArray();
        $names = $this->translationsOf(self::TRANSLATIONS, 'category_id', array_map('intval', array_column($rows, 'id')), ['category_name']);

        return array_map(static fn (array $row): array => [
            'public_id'       => $row['public_id'],
            'category_name'   => $row['category_name'],
            'category_status' => $row['category_status'],
            'sort_order'      => (int) $row['sort_order'],
            'artifact_count'  => (int) $row['artifact_count'],
            'translations'    => self::flatTranslations($names[(int) $row['id']] ?? [], 'category_name'),
        ], $rows);
    }

    /**
     * Whether another category of the business has this name (the database refuses it; this tells the form first)
     * @param int      $businessId
     * @param string   $name
     * @param int|null $exceptId The category being edited
     * @return bool
     */
    public function nameTaken(int $businessId, string $name, ?int $exceptId = null): bool
    {
        $builder = $this->db->table($this->table)->where('business_id', $businessId)->where('category_name', $name);
        if ($exceptId !== null) {
            $builder->where('id !=', $exceptId);
        }

        return $builder->countAllResults() > 0;
    }

    /**
     * Create a category with its names in one transaction, logged to log_activity in it
     * @param array $category Checked artifact_category columns, business_id included
     * @param array $names    language_code => [category_name => text], from TranslationInputTrait
     * @param int   $actorId
     * @return string The new category's public_id
     * @throws Throwable When a write fails; nothing is written then
     */
    public function createWithNames(array $category, array $names, int $actorId): string
    {
        $log = new ActivityLog();

        return $this->withPublicId(fn (string $publicId) => $this->inTransaction(function () use ($category, $names, $actorId, $log, $publicId) {
            $row = ['public_id' => $publicId] + $category;
            $this->db->table($this->table)->insert($row);
            $id = (int) $this->db->insertID();
            $log->inserted($actorId, 'Created artifact category ' . $publicId . ' of business ' . $row['business_id'], $this->table, $id, $row);
            $this->writeTranslations(self::TRANSLATIONS, 'category_id', $id, 'artifact category ' . $publicId, [], $names, $actorId, $log);

            return $publicId;
        }));
    }

    /**
     * Update a category and its names in one transaction; only what changes is written and logged
     * @param array      $stored   The artifact_category row
     * @param array      $category Checked columns to save
     * @param array|null $names    language_code => [category_name => text]; a language left out loses its name.
     *                             Null leaves the names as they are
     * @param int        $actorId
     * @return void
     * @throws Throwable When a write fails; nothing is written then
     */
    public function updateWithNames(array $stored, array $category, ?array $names, int $actorId): void
    {
        $id      = (int) $stored['id'];
        $changes = ActivityLog::changes($stored, array_diff_key($category, ['business_id' => true]));
        $log     = new ActivityLog();
        $this->inTransaction(function () use ($stored, $id, $changes, $names, $actorId, $log) {
            if ($changes !== []) {
                $this->db->table($this->table)->where('id', $id)->update($changes);
                $log->updated($actorId, 'Updated artifact category ' . $stored['public_id'], $this->table, $id, $stored, $changes);
            }
            if ($names !== null) {
                $storedNames = $this->translationsOf(self::TRANSLATIONS, 'category_id', [$id], ['category_name'])[$id] ?? [];
                $this->writeTranslations(self::TRANSLATIONS, 'category_id', $id, 'artifact category ' . $stored['public_id'], $storedNames, $names, $actorId, $log, true);
            }
        });
    }
}
