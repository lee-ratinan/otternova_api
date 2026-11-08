<?php

namespace App\Models;

use App\Libraries\ActivityLog;
use CodeIgniter\Model;
use Throwable;

/**
 * Model for the resource_type table: a kind of resource a business's services may need besides a person ("Massage
 * room"), with its name and description in other languages in resource_type_translation. The real resources are in
 * resource_master. Types are never deleted; type_status I retires one: it cannot be newly linked to a service, and
 * its resources are not offered.
 */
class ResourceTypeModel extends Model
{
    use PublicIdTrait;
    use TransactionTrait;
    use TranslationWriteTrait;

    // type_status
    public const STATUS_ACTIVE   = 'A';
    public const STATUS_INACTIVE = 'I';

    /** The translation table and its texts */
    private const TRANSLATIONS = 'resource_type_translation';
    private const TEXTS        = ['type_name', 'type_description'];

    protected $table            = 'resource_type';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = ['public_id', 'business_id', 'type_name', 'type_description', 'buffer_minutes', 'type_status'];

    /** public_id: CHAR(12), see PublicIdTrait */
    protected int $publicIdLength = 12;
    protected $beforeInsert       = ['fillPublicId'];

    // created_at and updated_at are maintained by the database
    protected $useTimestamps = false;

    protected $validationRules = [
        'business_id'      => 'required|is_natural_no_zero',
        'type_name'        => 'required|max_length[255]',
        'type_description' => 'permit_empty|max_length[10000]',
        'buffer_minutes'   => 'required|is_natural|less_than_equal_to[1440]',
        'type_status'      => 'required|in_list[A,I]',
    ];

    /**
     * The types of a business by name, each with its translations, how many resources it has (active ones and all)
     * and how many services need it. The ids stay inside.
     * @param int $businessId
     * @return list<array>
     */
    public function listOfBusiness(int $businessId): array
    {
        $rows = $this->db->table($this->table)
            ->select('id, public_id, type_name, type_description, buffer_minutes, type_status')
            ->where('business_id', $businessId)
            ->orderBy('type_name')
            ->orderBy('id')
            ->get()
            ->getResultArray();
        if ($rows === []) {
            return [];
        }
        $ids       = array_map('intval', array_column($rows, 'id'));
        $resources = [];
        foreach ($this->db->table('resource_master')
            ->select("resource_type_id, COUNT(*) AS resource_count, SUM(CASE WHEN resource_status = 'A' THEN 1 ELSE 0 END) AS active_count")
            ->where('business_id', $businessId)->whereIn('resource_type_id', $ids)
            ->groupBy('resource_type_id')->get()->getResultArray() as $row) {
            $resources[(int) $row['resource_type_id']] = $row;
        }
        $services = [];
        foreach ($this->db->table('business_service_resource_link')
            ->select('resource_type_id, COUNT(*) AS service_count')
            ->where('business_id', $businessId)->whereIn('resource_type_id', $ids)
            ->groupBy('resource_type_id')->get()->getResultArray() as $row) {
            $services[(int) $row['resource_type_id']] = (int) $row['service_count'];
        }
        $names = $this->translationsOf(self::TRANSLATIONS, 'resource_type_id', $ids, self::TEXTS);

        return array_map(static function (array $row) use ($resources, $services, $names): array {
            $id = (int) $row['id'];

            return [
                'public_id'             => $row['public_id'],
                'type_name'             => $row['type_name'],
                'type_description'      => $row['type_description'],
                'buffer_minutes'        => (int) $row['buffer_minutes'],
                'type_status'           => $row['type_status'],
                'resource_count'        => (int) ($resources[$id]['resource_count'] ?? 0),
                'active_resource_count' => (int) ($resources[$id]['active_count'] ?? 0),
                'service_count'         => $services[$id] ?? 0,
                'translations'          => $names[$id] ?? [],
            ];
        }, $rows);
    }

    /**
     * Every type of a business by public_id, with the internal id, for the caller's own use
     * @param int $businessId
     * @return array<string, array{id: int, public_id: string, type_name: string, type_status: string}>
     */
    public function byPublicIdOf(int $businessId): array
    {
        $out = [];
        foreach ($this->db->table($this->table)->select('id, public_id, type_name, type_status')
            ->where('business_id', $businessId)->get()->getResultArray() as $row) {
            $out[$row['public_id']] = ['id' => (int) $row['id']] + $row;
        }

        return $out;
    }

    /**
     * Whether another type of the business has this name (the database refuses it; this tells the form first)
     * @param int      $businessId
     * @param string   $name
     * @param int|null $exceptId The type being edited
     * @return bool
     */
    public function nameTaken(int $businessId, string $name, ?int $exceptId = null): bool
    {
        $builder = $this->db->table($this->table)->where('business_id', $businessId)->where('type_name', $name);
        if ($exceptId !== null) {
            $builder->where('id !=', $exceptId);
        }

        return $builder->countAllResults() > 0;
    }

    /**
     * Create a type with its translations in one transaction, logged to log_activity in it
     * @param array $type  Checked resource_type columns, business_id included
     * @param array $names language_code => [type_name, type_description], from TranslationInputTrait
     * @param int   $actorId
     * @return string The new type's public_id
     * @throws Throwable When a write fails; nothing is written then
     */
    public function createWithNames(array $type, array $names, int $actorId): string
    {
        $log = new ActivityLog();

        return $this->withPublicId(fn (string $publicId) => $this->inTransaction(function () use ($type, $names, $actorId, $log, $publicId) {
            $row = ['public_id' => $publicId] + $type;
            $this->db->table($this->table)->insert($row);
            $id = (int) $this->db->insertID();
            $log->inserted($actorId, 'Created resource type ' . $publicId . ' of business ' . $row['business_id'], $this->table, $id, $row);
            $this->writeTranslations(self::TRANSLATIONS, 'resource_type_id', $id, 'resource type ' . $publicId, [], $names, $actorId, $log);

            return $publicId;
        }));
    }

    /**
     * Update a type and its translations in one transaction; only what changes is written and logged
     * @param array      $stored  The resource_type row
     * @param array      $type    Checked columns to save
     * @param array|null $names   language_code => [type_name, type_description]; a language left out loses its texts.
     *                            Null leaves them as they are
     * @param int        $actorId
     * @return void
     * @throws Throwable When a write fails; nothing is written then
     */
    public function updateWithNames(array $stored, array $type, ?array $names, int $actorId): void
    {
        $id      = (int) $stored['id'];
        $changes = ActivityLog::changes($stored, array_diff_key($type, ['business_id' => true]));
        $log     = new ActivityLog();
        $this->inTransaction(function () use ($stored, $id, $changes, $names, $actorId, $log) {
            if ($changes !== []) {
                $this->db->table($this->table)->where('id', $id)->update($changes);
                $log->updated($actorId, 'Updated resource type ' . $stored['public_id'], $this->table, $id, $stored, $changes);
            }
            if ($names !== null) {
                $storedNames = $this->translationsOf(self::TRANSLATIONS, 'resource_type_id', [$id], self::TEXTS)[$id] ?? [];
                $this->writeTranslations(self::TRANSLATIONS, 'resource_type_id', $id, 'resource type ' . $stored['public_id'], $storedNames, $names, $actorId, $log, true);
            }
        });
    }
}
