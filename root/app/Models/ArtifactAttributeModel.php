<?php

namespace App\Models;

use App\Libraries\ActivityLog;
use CodeIgniter\Model;
use Throwable;

/**
 * Model for the artifact_attribute table, the options a business's variants differ by (Color, Size), together with
 * artifact_attribute_value, the choices of each (Blue, Red), and the translation tables of both. Neither is ever
 * deleted: a value stays, as variants may use it.
 */
class ArtifactAttributeModel extends Model
{
    use PublicIdTrait;
    use TransactionTrait;
    use TranslationWriteTrait;

    /** The table of the values */
    public const VALUES = 'artifact_attribute_value';

    private const TRANSLATIONS       = 'artifact_attribute_translation';
    private const VALUE_TRANSLATIONS = 'artifact_attribute_value_translation';

    protected $table            = 'artifact_attribute';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = ['public_id', 'business_id', 'attribute_name'];

    /** public_id: CHAR(12), see PublicIdTrait; the values' are as long */
    protected int $publicIdLength = 12;
    protected $beforeInsert       = ['fillPublicId'];

    // created_at and updated_at are maintained by the database
    protected $useTimestamps = false;

    /**
     * The attributes of a business by name, each with its values in their order and the names of both in other
     * languages. The ids stay inside.
     * @param int $businessId
     * @return list<array{public_id: string, attribute_name: string, translations: array<string, string>, values: list<array>}>
     */
    public function listOfBusiness(int $businessId): array
    {
        $attributes = $this->db->table($this->table)
            ->select('id, public_id, attribute_name')
            ->where('business_id', $businessId)
            ->orderBy('attribute_name')
            ->get()
            ->getResultArray();
        if ($attributes === []) {
            return [];
        }
        $ids    = array_map('intval', array_column($attributes, 'id'));
        $names  = $this->translationsOf(self::TRANSLATIONS, 'attribute_id', $ids, ['attribute_name']);
        $values = $this->db->table(self::VALUES)
            ->select('id, attribute_id, public_id, attribute_value, sort_order')
            ->whereIn('attribute_id', $ids)
            ->orderBy('sort_order')
            ->orderBy('attribute_value')
            ->get()
            ->getResultArray();
        $valueNames = $this->translationsOf(self::VALUE_TRANSLATIONS, 'attribute_value_id', array_map('intval', array_column($values, 'id')), ['attribute_value']);
        $byAttribute = [];
        foreach ($values as $value) {
            $byAttribute[(int) $value['attribute_id']][] = [
                'public_id'       => $value['public_id'],
                'attribute_value' => $value['attribute_value'],
                'sort_order'      => (int) $value['sort_order'],
                'translations'    => self::flatTranslations($valueNames[(int) $value['id']] ?? [], 'attribute_value'),
            ];
        }

        return array_map(static fn (array $attribute): array => [
            'public_id'      => $attribute['public_id'],
            'attribute_name' => $attribute['attribute_name'],
            'translations'   => self::flatTranslations($names[(int) $attribute['id']] ?? [], 'attribute_name'),
            'values'         => $byAttribute[(int) $attribute['id']] ?? [],
        ], $attributes);
    }

    /**
     * Whether another attribute of the business has this name
     * @param int      $businessId
     * @param string   $name
     * @param int|null $exceptId The attribute being edited
     * @return bool
     */
    public function nameTaken(int $businessId, string $name, ?int $exceptId = null): bool
    {
        $builder = $this->db->table($this->table)->where('business_id', $businessId)->where('attribute_name', $name);
        if ($exceptId !== null) {
            $builder->where('id !=', $exceptId);
        }

        return $builder->countAllResults() > 0;
    }

    /**
     * The values of an attribute as stored, keyed by public_id
     * @param int $attributeId
     * @return array<string, array>
     */
    public function valuesOf(int $attributeId): array
    {
        $rows = $this->db->table(self::VALUES)->where('attribute_id', $attributeId)->get()->getResultArray();

        return array_column($rows, null, 'public_id');
    }

    /**
     * The values of a business's attributes with these public_ids, keyed by public_id, each with its attribute_id
     * @param int          $businessId
     * @param list<string> $publicIds
     * @return array<string, array{id: int, attribute_id: int, public_id: string, attribute_public_id: string}>
     */
    public function valuesOfBusiness(int $businessId, array $publicIds): array
    {
        if ($publicIds === []) {
            return [];
        }
        $rows = $this->db->table(self::VALUES . ' v')
            ->select('v.id, v.attribute_id, v.public_id, a.public_id AS attribute_public_id')
            ->join($this->table . ' a', 'a.id = v.attribute_id')
            ->where('a.business_id', $businessId)
            ->whereIn('v.public_id', $publicIds)
            ->get()
            ->getResultArray();
        $out = [];
        foreach ($rows as $row) {
            $out[$row['public_id']] = ['id' => (int) $row['id'], 'attribute_id' => (int) $row['attribute_id']] + $row;
        }

        return $out;
    }

    /**
     * Create or update an attribute with its names and values in one transaction, logged to log_activity in it.
     * Only what changes is written; a value left out of $values stays as it is.
     * @param array|null $stored    The artifact_attribute row; null to create one
     * @param array      $attribute Checked columns: business_id and attribute_name
     * @param array|null $names     language_code => [attribute_name => text]; null leaves them as they are
     * @param list<array{stored: array|null, row: array{attribute_value: string, sort_order: int}, names: array|null}> $values
     *                              Checked values: a stored one to update, or a new one
     * @param int        $actorId
     * @return string The attribute's public_id
     * @throws Throwable When a write fails; nothing is written then
     */
    public function saveWithValues(?array $stored, array $attribute, ?array $names, array $values, int $actorId): string
    {
        $log  = new ActivityLog();
        $work = function (string $publicId) use ($stored, $attribute, $names, $values, $actorId, $log): string {
            $what = 'artifact attribute ' . $publicId;
            if ($stored === null) {
                $row = ['public_id' => $publicId] + $attribute;
                $this->db->table($this->table)->insert($row);
                $id = (int) $this->db->insertID();
                $log->inserted($actorId, 'Created ' . $what . ' of business ' . $row['business_id'], $this->table, $id, $row);
                $storedNames = [];
            } else {
                $id      = (int) $stored['id'];
                $changes = ActivityLog::changes($stored, ['attribute_name' => $attribute['attribute_name']]);
                if ($changes !== []) {
                    $this->db->table($this->table)->where('id', $id)->update($changes);
                    $log->updated($actorId, 'Updated ' . $what, $this->table, $id, $stored, $changes);
                }
                $storedNames = $this->translationsOf(self::TRANSLATIONS, 'attribute_id', [$id], ['attribute_name'])[$id] ?? [];
            }
            if ($names !== null) {
                $this->writeTranslations(self::TRANSLATIONS, 'attribute_id', $id, $what, $storedNames, $names, $actorId, $log, $stored !== null);
            }
            foreach ($values as $value) {
                $this->writeValue($id, $publicId, $value, $actorId, $log);
            }

            return $publicId;
        };

        return $stored === null
            ? $this->withPublicId(fn (string $publicId) => $this->inTransaction(static fn () => $work($publicId)))
            : $this->inTransaction(static fn () => $work($stored['public_id']));
    }

    /**
     * Insert or update one value of an attribute, with its names
     * @param int         $attributeId
     * @param string      $attributePublicId
     * @param array       $value     As saveWithValues() takes it
     * @param int         $actorId
     * @param ActivityLog $log
     * @return void
     */
    private function writeValue(int $attributeId, string $attributePublicId, array $value, int $actorId, ActivityLog $log): void
    {
        $stored = $value['stored'];
        if ($stored === null) {
            $row = ['public_id' => $this->newPublicIdIn(self::VALUES), 'attribute_id' => $attributeId] + $value['row'];
            $this->db->table(self::VALUES)->insert($row);
            $id = (int) $this->db->insertID();
            $log->inserted($actorId, 'Added value ' . $row['public_id'] . ' to artifact attribute ' . $attributePublicId, self::VALUES, $id, $row);
            $storedNames = [];
            $publicId    = $row['public_id'];
        } else {
            $id       = (int) $stored['id'];
            $publicId = $stored['public_id'];
            $changes  = ActivityLog::changes($stored, $value['row']);
            if ($changes !== []) {
                $this->db->table(self::VALUES)->where('id', $id)->update($changes);
                $log->updated($actorId, 'Updated artifact attribute value ' . $publicId, self::VALUES, $id, $stored, $changes);
            }
            $storedNames = $this->translationsOf(self::VALUE_TRANSLATIONS, 'attribute_value_id', [$id], ['attribute_value'])[$id] ?? [];
        }
        if ($value['names'] !== null) {
            $this->writeTranslations(self::VALUE_TRANSLATIONS, 'attribute_value_id', $id, 'artifact attribute value ' . $publicId, $storedNames, $value['names'], $actorId, $log, $stored !== null);
        }
    }
}
