<?php

namespace App\Models;

use App\Libraries\ActivityLog;
use CodeIgniter\Model;
use Throwable;

/**
 * Model for the business_client_attribute table, what a business records about its people ("Certification",
 * "Specialized in", "Height"), together with business_client_attribute_value, each person's values of them in free
 * text ("JLPT N1", "Sport massage", "168 cm"), and the translation tables of both. The business decides what it
 * records; an attribute is deleted with all its values.
 */
class BusinessClientAttributeModel extends Model
{
    use PublicIdTrait;
    use TransactionTrait;
    use TranslationWriteTrait;

    /** The table of the values */
    public const VALUES = 'business_client_attribute_value';

    private const TRANSLATIONS       = 'business_client_attribute_translation';
    private const VALUE_TRANSLATIONS = 'business_client_attribute_value_translation';

    protected $table            = 'business_client_attribute';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = ['public_id', 'business_id', 'attribute_name', 'is_public', 'sort_order'];

    /** public_id: CHAR(12), see PublicIdTrait; the values' are as long */
    protected int $publicIdLength = 12;
    protected $beforeInsert       = ['fillPublicId'];

    // created_at and updated_at are maintained by the database
    protected $useTimestamps = false;

    /**
     * The attributes of a business in their order, with their names in other languages and how many values people
     * have of each. The ids stay inside.
     * @param int $businessId
     * @return list<array{public_id: string, attribute_name: string, is_public: bool, sort_order: int, translations: array<string, string>, value_count: int}>
     */
    public function listOfBusiness(int $businessId): array
    {
        $rows   = $this->rowsOfBusiness($businessId);
        $ids    = array_map('intval', array_column($rows, 'id'));
        $names  = $this->translationsOf(self::TRANSLATIONS, 'attribute_id', $ids, ['attribute_name']);
        $counts = [];
        if ($ids !== []) {
            $countRows = $this->db->table(self::VALUES)->select('attribute_id, COUNT(*) AS value_count')
                ->where('business_id', $businessId)->whereIn('attribute_id', $ids)->groupBy('attribute_id')
                ->get()->getResultArray();
            $counts = array_column($countRows, 'value_count', 'attribute_id');
        }

        return array_map(fn (array $row): array => $this->out($row, $names) + ['value_count' => (int) ($counts[$row['id']] ?? 0)], $rows);
    }

    /**
     * The attributes of a business in their order, as stored
     * @param int $businessId
     * @return list<array>
     */
    public function rowsOfBusiness(int $businessId): array
    {
        return $this->db->table($this->table)->where('business_id', $businessId)
            ->orderBy('sort_order')->orderBy('attribute_name')
            ->get()->getResultArray();
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
     * Create or update an attribute with its names in one transaction, logged to log_activity in it
     * @param array|null $stored The business_client_attribute row; null to create one
     * @param array      $row    Checked columns: business_id, attribute_name, is_public, sort_order
     * @param array|null $names  language_code => [attribute_name => text], replacing the stored ones; null leaves them
     * @param int        $actorId
     * @return string The attribute's public_id
     * @throws Throwable When a write fails; nothing is written then
     */
    public function saveAttribute(?array $stored, array $row, ?array $names, int $actorId): string
    {
        $log  = new ActivityLog();
        $work = function (string $publicId) use ($stored, $row, $names, $actorId, $log): string {
            $what = 'staff attribute ' . $publicId;
            if ($stored === null) {
                $row = ['public_id' => $publicId] + $row;
                $this->db->table($this->table)->insert($row);
                $id = (int) $this->db->insertID();
                $log->inserted($actorId, 'Created ' . $what . ' of business ' . $row['business_id'], $this->table, $id, $row);
                $storedNames = [];
            } else {
                $id = (int) $stored['id'];
                unset($row['business_id']);
                $changes = ActivityLog::changes($stored, $row);
                if ($changes !== []) {
                    $this->db->table($this->table)->where('id', $id)->update($changes);
                    $log->updated($actorId, 'Updated ' . $what, $this->table, $id, $stored, $changes);
                }
                $storedNames = $this->translationsOf(self::TRANSLATIONS, 'attribute_id', [$id], ['attribute_name'])[$id] ?? [];
            }
            if ($names !== null) {
                $this->writeTranslations(self::TRANSLATIONS, 'attribute_id', $id, $what, $storedNames, $names, $actorId, $log, $stored !== null);
            }

            return $publicId;
        };

        return $stored === null
            ? $this->withPublicId(fn (string $publicId) => $this->inTransaction(static fn () => $work($publicId)))
            : $this->inTransaction(static fn () => $work($stored['public_id']));
    }

    /**
     * Delete an attribute with everyone's values of it and the names of both, in one transaction, each logged to
     * log_activity in it
     * @param array $stored The business_client_attribute row
     * @param int   $actorId
     * @return int How many values were deleted with it
     * @throws Throwable When a write fails; nothing is deleted then
     */
    public function deleteAttribute(array $stored, int $actorId): int
    {
        $log = new ActivityLog();

        return $this->inTransaction(function () use ($stored, $actorId, $log): int {
            $id     = (int) $stored['id'];
            $values = $this->db->table(self::VALUES)->where('business_id', $stored['business_id'])->where('attribute_id', $id)->get()->getResultArray();
            foreach ($values as $value) {
                $this->deleteValue($value, $actorId, $log);
            }
            foreach ($this->db->table(self::TRANSLATIONS)->where('attribute_id', $id)->get()->getResultArray() as $row) {
                $this->db->table(self::TRANSLATIONS)->where('id', $row['id'])->delete();
                $log->deleted($actorId, 'Removed the ' . $row['language_code'] . ' translation of staff attribute ' . $stored['public_id'], self::TRANSLATIONS, (int) $row['id'], $row);
            }
            $this->db->table($this->table)->where('id', $id)->delete();
            $log->deleted($actorId, 'Deleted staff attribute ' . $stored['public_id'], $this->table, $id, $stored);

            return count($values);
        });
    }

    /**
     * A person's values as stored, keyed by public_id
     * @param int $businessId
     * @param int $clientId
     * @return array<string, array>
     */
    public function valuesOf(int $businessId, int $clientId): array
    {
        $rows = $this->db->table(self::VALUES)->where('business_id', $businessId)->where('client_id', $clientId)->get()->getResultArray();

        return array_column($rows, null, 'public_id');
    }

    /**
     * Every attribute of the business in its order, each with the person's values of it in their order (none for an
     * attribute they have no value of), with the names of both in other languages. The ids stay inside.
     * @param int $businessId
     * @param int $clientId
     * @return list<array{public_id: string, attribute_name: string, is_public: bool, sort_order: int, translations: array<string, string>, values: list<array>}>
     */
    public function profileOf(int $businessId, int $clientId): array
    {
        $rows  = $this->rowsOfBusiness($businessId);
        $names = $this->translationsOf(self::TRANSLATIONS, 'attribute_id', array_map('intval', array_column($rows, 'id')), ['attribute_name']);
        $values = $this->db->table(self::VALUES)
            ->select('id, attribute_id, public_id, attribute_value, sort_order')
            ->where('business_id', $businessId)->where('client_id', $clientId)
            ->orderBy('sort_order')->orderBy('attribute_value')
            ->get()->getResultArray();
        $valueNames  = $this->translationsOf(self::VALUE_TRANSLATIONS, 'value_id', array_map('intval', array_column($values, 'id')), ['attribute_value']);
        $byAttribute = [];
        foreach ($values as $value) {
            $byAttribute[(int) $value['attribute_id']][] = [
                'public_id'       => $value['public_id'],
                'attribute_value' => $value['attribute_value'],
                'sort_order'      => (int) $value['sort_order'],
                'translations'    => self::flatTranslations($valueNames[(int) $value['id']] ?? [], 'attribute_value'),
            ];
        }

        return array_map(fn (array $row): array => $this->out($row, $names) + ['values' => $byAttribute[(int) $row['id']] ?? []], $rows);
    }

    /**
     * Make $values the person's values, in one transaction, logged to log_activity in it: a stored value left out is
     * deleted, a stored one given is updated where it differs, a new one is added
     * @param int    $businessId
     * @param int    $clientId
     * @param list<array{stored: array|null, row: array{attribute_id: int, attribute_value: string, sort_order: int}, names: array|null}> $values
     *               Checked values; names (language_code => [attribute_value => text]) replace the stored ones, null leaves them
     * @param int    $actorId
     * @param string $uid auth_master.uid of the person, for the log
     * @return void
     * @throws Throwable When a write fails; nothing is written then
     */
    public function replaceValues(int $businessId, int $clientId, array $values, int $actorId, string $uid): void
    {
        $log = new ActivityLog();
        $this->inTransaction(function () use ($businessId, $clientId, $values, $actorId, $uid, $log) {
            $kept = [];
            foreach ($values as $value) {
                if ($value['stored'] !== null) {
                    $kept[$value['stored']['public_id']] = true;
                }
            }
            // the ones left out go first, so a value given again as a new one does not clash with them
            foreach ($this->valuesOf($businessId, $clientId) as $publicId => $row) {
                if (! isset($kept[$publicId])) {
                    $this->deleteValue($row, $actorId, $log);
                }
            }
            foreach ($values as $value) {
                $stored = $value['stored'];
                if ($stored === null) {
                    $row = ['public_id' => $this->newPublicIdIn(self::VALUES), 'business_id' => $businessId, 'client_id' => $clientId] + $value['row'];
                    $this->db->table(self::VALUES)->insert($row);
                    $id = (int) $this->db->insertID();
                    $log->inserted($actorId, 'Added staff attribute value ' . $row['public_id'] . ' to ' . $uid, self::VALUES, $id, $row);
                    $storedNames = [];
                    $publicId    = $row['public_id'];
                } else {
                    $id       = (int) $stored['id'];
                    $publicId = $stored['public_id'];
                    $changes  = ActivityLog::changes($stored, $value['row']);
                    if ($changes !== []) {
                        $this->db->table(self::VALUES)->where('id', $id)->update($changes);
                        $log->updated($actorId, 'Updated staff attribute value ' . $publicId . ' of ' . $uid, self::VALUES, $id, $stored, $changes);
                    }
                    $storedNames = $this->translationsOf(self::VALUE_TRANSLATIONS, 'value_id', [$id], ['attribute_value'])[$id] ?? [];
                }
                if ($value['names'] !== null) {
                    $this->writeTranslations(self::VALUE_TRANSLATIONS, 'value_id', $id, 'staff attribute value ' . $publicId, $storedNames, $value['names'], $actorId, $log, $stored !== null);
                }
            }
        });
    }

    /**
     * Delete one value with its names
     * @param array       $row The business_client_attribute_value row
     * @param int         $actorId
     * @param ActivityLog $log
     * @return void
     */
    private function deleteValue(array $row, int $actorId, ActivityLog $log): void
    {
        foreach ($this->db->table(self::VALUE_TRANSLATIONS)->where('value_id', $row['id'])->get()->getResultArray() as $name) {
            $this->db->table(self::VALUE_TRANSLATIONS)->where('id', $name['id'])->delete();
            $log->deleted($actorId, 'Removed the ' . $name['language_code'] . ' translation of staff attribute value ' . $row['public_id'], self::VALUE_TRANSLATIONS, (int) $name['id'], $name);
        }
        $this->db->table(self::VALUES)->where('id', $row['id'])->delete();
        $log->deleted($actorId, 'Deleted staff attribute value ' . $row['public_id'], self::VALUES, (int) $row['id'], $row);
    }

    /**
     * What goes out of an attribute
     * @param array $row   As stored
     * @param array $names translationsOf() of the attributes
     * @return array
     */
    private function out(array $row, array $names): array
    {
        return [
            'public_id'      => $row['public_id'],
            'attribute_name' => $row['attribute_name'],
            'is_public'      => (int) $row['is_public'] === 1,
            'sort_order'     => (int) $row['sort_order'],
            'translations'   => self::flatTranslations($names[(int) $row['id']] ?? [], 'attribute_name'),
        ];
    }
}
