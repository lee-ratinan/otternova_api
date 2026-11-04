<?php

namespace App\Models;

use App\Libraries\ActivityLog;
use CodeIgniter\Model;
use InvalidArgumentException;
use Throwable;

/**
 * Model for the business_store table: a branch of a business, physical (P) or online (O), with its names in other
 * languages in business_store_translation. Only a physical store has an address: the writes here save the address
 * columns of an online store as NULL. Its subdivision must be one of listSupportedSubdivisions() of its business's
 * country, which localeErrors() checks.
 */
class BusinessStoreModel extends Model
{
    use FieldConfigurationTrait;
    use PublicIdTrait;
    use TransactionTrait;

    // store_type
    public const TYPE_PHYSICAL = 'P';
    public const TYPE_ONLINE   = 'O';

    /** Columns that only a physical store has */
    public const ADDRESS_FIELDS = [
        'store_address_line_1',
        'store_address_line_2',
        'store_city',
        'store_subdivision_code',
        'store_postal_code',
    ];

    /** Columns fixed once the store is created */
    public const FIXED_FIELDS = ['business_id'];

    protected $table            = 'business_store';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'public_id',
        'business_id',
        'store_name',
        'store_type',
        'store_phone_number',
        'store_address_line_1',
        'store_address_line_2',
        'store_city',
        'store_subdivision_code',
        'store_postal_code',
    ];

    /** public_id: CHAR(12), see PublicIdTrait */
    protected int $publicIdLength = 12;
    protected $beforeInsert       = ['fillPublicId'];

    // created_at and updated_at are maintained by the database
    protected $useTimestamps = false;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    // whether the subdivision belongs to the business's country is checked by localeErrors(), as a rule cannot see it
    protected $validationRules = [
        'id'                     => 'permit_empty|is_natural_no_zero',
        'business_id'            => 'required|is_natural_no_zero',
        'store_name'             => 'required|max_length[255]',
        'store_type'             => 'required|in_list[P,O]',
        'store_phone_number'     => 'permit_empty|max_length[32]',
        'store_address_line_1'   => 'permit_empty|max_length[255]',
        'store_address_line_2'   => 'permit_empty|max_length[255]',
        'store_city'             => 'permit_empty|max_length[128]',
        'store_subdivision_code' => 'permit_empty|max_length[8]',
        'store_postal_code'      => 'permit_empty|max_length[16]',
    ];
    protected $validationMessages   = [];
    protected $skipValidation       = false;
    protected $cleanValidationRules = true;

    /**
     * Field configuration shared by the Form Builder (as 'fields') and the Table Builder (via toColumns()),
     * keyed by field name so a controller can unset() what it does not need.
     * @return array
     */
    public function getConfiguration(): array
    {
        $fields = [
            'store_name'             => ['type' => 'text'],
            'store_type'             => ['type' => 'select'],
            'store_phone_number'     => ['type' => 'tel'],
            'store_address_line_1'   => ['type' => 'text'],
            'store_address_line_2'   => ['type' => 'text'],
            'store_city'             => ['type' => 'text'],
            'store_subdivision_code' => ['type' => 'text', 'searchable' => false], // depends on the business's country
            'store_postal_code'      => ['type' => 'text'],
        ];
        $enums  = [
            'store_type' => [self::TYPE_PHYSICAL => 'primary', self::TYPE_ONLINE => 'info'],
        ];

        return $this->buildConfiguration('BusinessStore', $fields, $enums);
    }

    /**
     * Errors for the columns of $store that do not fit its business's country: a subdivision not in
     * listSupportedSubdivisions($countryCode)
     * @param array  $store
     * @param string $countryCode business_master.country_code
     * @return array field => error
     */
    public static function localeErrors(array $store, string $countryCode): array
    {
        helper('list');
        $subdivision = $store['store_subdivision_code'] ?? null;
        if ($subdivision !== null && $subdivision !== '' && ! isset(listSupportedSubdivisions(strtoupper($countryCode))[strtoupper($subdivision)])) {
            return ['store_subdivision_code' => lang('BusinessStore.invalidSubdivision')];
        }

        return [];
    }

    /**
     * Whether another store of the business has this name (the database refuses it; this tells the form first)
     * @param int      $businessId
     * @param string   $name
     * @param int|null $exceptId The store being edited
     * @return bool
     */
    public function nameTaken(int $businessId, string $name, ?int $exceptId = null): bool
    {
        $builder = $this->db->table($this->table)->where('business_id', $businessId)->where('store_name', $name);
        if ($exceptId !== null) {
            $builder->where('id !=', $exceptId);
        }

        return $builder->countAllResults() > 0;
    }

    /**
     * The stores of a business with their names in other languages, by name. The ids stay inside; a store goes out
     * by its public_id.
     * @param int $businessId
     * @return list<array>
     */
    public function listOfBusiness(int $businessId): array
    {
        $stores = $this->db->table($this->table)
            ->select('id, public_id, store_name, store_type, store_phone_number, ' . implode(', ', self::ADDRESS_FIELDS))
            ->where('business_id', $businessId)
            ->orderBy('store_name', 'ASC')
            ->orderBy('id', 'ASC')
            ->get()
            ->getResultArray();
        if ($stores === []) {
            return [];
        }
        $names = [];
        $rows  = $this->db->table('business_store_translation')
            ->select('store_id, language_code, store_name')
            ->whereIn('store_id', array_column($stores, 'id'))
            ->get()
            ->getResultArray();
        foreach ($rows as $row) {
            $names[(int) $row['store_id']][$row['language_code']] = $row['store_name'];
        }

        return array_map(static function (array $store) use ($names) {
            $id = (int) $store['id'];
            unset($store['id']);

            return $store + ['translations' => $names[$id] ?? []];
        }, $stores);
    }

    /**
     * The names of a store in other languages
     * @param int $id
     * @return array language_code => store_name
     */
    public function namesOf(int $id): array
    {
        $rows = $this->db->table('business_store_translation')
            ->select('language_code, store_name')
            ->where('store_id', $id)
            ->get()
            ->getResultArray();

        return array_column($rows, 'store_name', 'language_code');
    }

    /**
     * $store with the address columns NULL when it is an online store
     * @param array $store
     * @return array
     */
    public static function withoutOnlineAddress(array $store): array
    {
        if (($store['store_type'] ?? null) === self::TYPE_ONLINE) {
            foreach (self::ADDRESS_FIELDS as $field) {
                $store[$field] = null;
            }
        }

        return $store;
    }

    /**
     * Create a store with its names in one transaction, logged to log_activity in it
     * @param array $store   Checked business_store columns (localeErrors() included), business_id included
     * @param int   $actorId auth_master.id of whoever creates it
     * @param array $names   language_code => store_name, for languages of listLanguages(); optional
     * @return string The new store's public_id
     * @throws Throwable When a write fails (such as the name being taken meanwhile); nothing is written then
     */
    public function createWithNames(array $store, int $actorId, array $names = []): string
    {
        $store = self::withoutOnlineAddress($store);
        $log   = new ActivityLog();

        return $this->withPublicId(fn (string $publicId) => $this->inTransaction(function () use ($store, $actorId, $names, $log, $publicId) {
            $store = ['public_id' => $publicId] + $store;
            $this->db->table($this->table)->insert($store);
            $id = (int) $this->db->insertID();
            $log->inserted($actorId, 'Created store ' . $publicId . ' of business ' . $store['business_id'], $this->table, $id, $store);
            $this->writeNames($id, $publicId, [], $names, $actorId, $log);

            return $publicId;
        }));
    }

    /**
     * Update a store and its names in one transaction, logged to log_activity in it.
     * Only the columns and names whose value changes are written and logged.
     * @param array      $stored  The business_store row
     * @param array      $store   Checked business_store columns
     * @param int        $actorId auth_master.id of whoever makes the change
     * @param array|null $names   language_code => store_name; a language left out or empty loses its name.
     *                            Null leaves the names as they are
     * @return void
     * @throws InvalidArgumentException When $store changes a column of FIXED_FIELDS
     * @throws Throwable When a write fails; nothing is written then
     */
    public function updateWithNames(array $stored, array $store, int $actorId, ?array $names = null): void
    {
        $changes = ActivityLog::changes($stored, self::withoutOnlineAddress($store));
        $fixed   = array_intersect(array_keys($changes), self::FIXED_FIELDS);
        if ($fixed !== []) {
            throw new InvalidArgumentException('Fixed once the store is created: ' . implode(', ', $fixed));
        }
        $id  = (int) $stored['id'];
        $log = new ActivityLog();
        $this->inTransaction(function () use ($stored, $id, $changes, $actorId, $names, $log) {
            if ($changes !== []) {
                $this->db->table($this->table)->where('id', $id)->update($changes);
                $log->updated($actorId, 'Updated store ' . $stored['public_id'], $this->table, $id, $stored, $changes);
            }
            if ($names !== null) {
                $this->writeNames($id, $stored['public_id'], $this->namesOf($id), $names, $actorId, $log, true);
            }
        });
    }

    /**
     * Insert, update or (with $replace) delete the names that differ from $stored
     * @param int         $id
     * @param string      $publicId
     * @param array       $stored  language_code => store_name
     * @param array       $names   language_code => store_name; languages not in listLanguages() are ignored
     * @param int         $actorId
     * @param ActivityLog $log
     * @param bool        $replace Delete the stored names that $names leaves out or empties
     * @return void
     */
    private function writeNames(int $id, string $publicId, array $stored, array $names, int $actorId, ActivityLog $log, bool $replace = false): void
    {
        helper('list');
        $table = 'business_store_translation';
        foreach (array_keys(listLanguages()) as $language) {
            $name = trim((string) ($names[$language] ?? ''));
            $key  = ['store_id' => $id, 'language_code' => $language];
            if ($name === '') {
                if ($replace && isset($stored[$language])) {
                    $old = $this->db->table($table)->where($key)->get()->getRowArray();
                    $this->db->table($table)->where('id', $old['id'])->delete();
                    $log->deleted($actorId, 'Removed ' . $language . ' name of store ' . $publicId, $table, (int) $old['id'], $old);
                }
            } elseif (! isset($stored[$language])) {
                $row = $key + ['store_name' => $name];
                $this->db->table($table)->insert($row);
                $log->inserted($actorId, 'Named store ' . $publicId . ' in ' . $language, $table, (int) $this->db->insertID(), $row);
            } elseif ($stored[$language] !== $name) {
                $old = $this->db->table($table)->select('id, store_name')->where($key)->get()->getRowArray();
                $this->db->table($table)->where('id', $old['id'])->update(['store_name' => $name]);
                $log->updated($actorId, 'Renamed store ' . $publicId . ' in ' . $language, $table, (int) $old['id'], $old, ['store_name' => $name]);
            }
        }
    }
}
