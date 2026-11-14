<?php

namespace App\Models;

use App\Libraries\ActivityLog;
use CodeIgniter\Model;
use InvalidArgumentException;
use Throwable;

/**
 * Model for the business_store table: a branch of a business, physical (P) or online (O), with its names and addresses
 * in other languages in business_store_translation. Only a physical store has an address: the writes here save the address
 * columns of an online store as NULL. Its subdivision must be one of listSupportedSubdivisions() of its business's
 * country, and its store_timezone one of listSupportedTimezones() of it, which localeErrors() checks. Its opening
 * hours are in BusinessStoreHoursModel.
 */
class BusinessStoreModel extends Model
{
    use FieldConfigurationTrait;
    use PublicIdTrait;
    use TransactionTrait;
    use TranslationWriteTrait;

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

    /** The receipt_format of a store that prints receipts; NULL is a store with no printer (AddStoreReceipts) */
    public const RECEIPT_FORMATS = ['58mm', '80mm', 'A4'];

    /** Columns about the receipts, which only a physical store prints */
    public const RECEIPT_FIELDS = ['receipt_format', 'receipt_footer'];

    /** The table of the store's texts in other languages */
    public const TRANSLATION_TABLE = 'business_store_translation';

    /** Columns of TRANSLATION_TABLE, the name (which a language cannot do without) first */
    public const TRANSLATED_FIELDS = ['store_name', 'store_address_line_1', 'store_address_line_2', 'store_city', 'receipt_footer'];

    /** Columns of TRANSLATED_FIELDS that only a physical store has: its address and its receipt footer */
    public const TRANSLATED_ADDRESS_FIELDS = ['store_address_line_1', 'store_address_line_2', 'store_city', 'receipt_footer'];

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
        'store_timezone',
        'store_phone_number',
        'store_address_line_1',
        'store_address_line_2',
        'store_city',
        'store_subdivision_code',
        'store_postal_code',
        'receipt_format',
        'receipt_footer',
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
        'store_timezone'         => 'required|max_length[64]',
        'store_phone_number'     => 'permit_empty|max_length[32]',
        'store_address_line_1'   => 'permit_empty|max_length[255]',
        'store_address_line_2'   => 'permit_empty|max_length[255]',
        'store_city'             => 'permit_empty|max_length[128]',
        'store_subdivision_code' => 'permit_empty|max_length[8]',
        'store_postal_code'      => 'permit_empty|max_length[16]',
        'receipt_format'         => 'permit_empty|in_list[58mm,80mm,A4]',
        'receipt_footer'         => 'permit_empty|max_length[255]',
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
            'store_timezone'         => ['type' => 'select', 'searchable' => false], // depends on the business's country
            'store_phone_number'     => ['type' => 'tel'],
            'store_address_line_1'   => ['type' => 'text'],
            'store_address_line_2'   => ['type' => 'text'],
            'store_city'             => ['type' => 'text'],
            'store_subdivision_code' => ['type' => 'text', 'searchable' => false], // depends on the business's country
            'store_postal_code'      => ['type' => 'text'],
            'receipt_format'         => ['type' => 'select'],
            'receipt_footer'         => ['type' => 'textarea'],
        ];
        $enums  = [
            'store_type'     => [self::TYPE_PHYSICAL => 'primary', self::TYPE_ONLINE => 'info'],
            'receipt_format' => ['58mm' => 'secondary', '80mm' => 'secondary', 'A4' => 'secondary'],
        ];

        return $this->buildConfiguration('BusinessStore', $fields, $enums);
    }

    /**
     * Errors for the columns of $store that do not fit its business's country: a subdivision not in
     * listSupportedSubdivisions($countryCode), or a timezone not in listSupportedTimezones($countryCode)
     * @param array  $store
     * @param string $countryCode business_master.country_code
     * @return array field => error
     */
    public static function localeErrors(array $store, string $countryCode): array
    {
        helper('list');
        $errors      = [];
        $subdivision = $store['store_subdivision_code'] ?? null;
        if ($subdivision !== null && $subdivision !== '' && ! isset(listSupportedSubdivisions(strtoupper($countryCode))[strtoupper($subdivision)])) {
            $errors['store_subdivision_code'] = lang('BusinessStore.invalidSubdivision');
        }
        $timezone = $store['store_timezone'] ?? null;
        if ($timezone !== null && ! isset(listSupportedTimezones($countryCode)[$timezone])) {
            $errors['store_timezone'] = lang('BusinessStore.invalidTimezone');
        }

        return $errors;
    }

    /**
     * The timezone a store in $countryCode gets when none is given: the country's first of listSupportedTimezones()
     * @param string $countryCode business_master.country_code
     * @return string|null Null for a country with none
     */
    public static function defaultTimezone(string $countryCode): ?string
    {
        helper('list');

        return array_key_first(listSupportedTimezones($countryCode));
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
     * The stores of a business with their names and addresses in other languages, by name. The ids stay inside; a
     * store goes out by its public_id.
     * @param int  $businessId
     * @param bool $withIds Keep the internal id, for the caller's own use; strip it before anything goes out
     * @return list<array> each with translations: language_code => [column of TRANSLATED_FIELDS => text]
     */
    public function listOfBusiness(int $businessId, bool $withIds = false): array
    {
        $stores = $this->db->table($this->table)
            ->select('id, public_id, store_name, store_type, store_timezone, store_phone_number, ' . implode(', ', [...self::ADDRESS_FIELDS, ...self::RECEIPT_FIELDS]))
            ->where('business_id', $businessId)
            ->orderBy('store_name', 'ASC')
            ->orderBy('id', 'ASC')
            ->get()
            ->getResultArray();
        if ($stores === []) {
            return [];
        }
        $translations = $this->translationsOf(self::TRANSLATION_TABLE, 'store_id', array_map('intval', array_column($stores, 'id')), self::TRANSLATED_FIELDS);

        return array_map(static function (array $store) use ($translations, $withIds) {
            $id = (int) $store['id'];
            if ($withIds) {
                $store['id'] = $id;
            } else {
                unset($store['id']);
            }

            return $store + ['translations' => $translations[$id] ?? []];
        }, $stores);
    }

    /**
     * The names and addresses of a store in other languages
     * @param int $id
     * @return array language_code => [column of TRANSLATED_FIELDS => text]
     */
    public function translationsOfStore(int $id): array
    {
        return $this->translationsOf(self::TRANSLATION_TABLE, 'store_id', [$id], self::TRANSLATED_FIELDS)[$id] ?? [];
    }

    /**
     * $store with the address and receipt columns NULL when it is an online store, which has no counter to print at
     * @param array $store
     * @return array
     */
    public static function withoutOnlineAddress(array $store): array
    {
        if (($store['store_type'] ?? null) === self::TYPE_ONLINE) {
            foreach ([...self::ADDRESS_FIELDS, ...self::RECEIPT_FIELDS] as $field) {
                $store[$field] = null;
            }
        }

        return $store;
    }

    /**
     * $translations with the translated address columns and receipt footer NULL when $store is an online store
     * @param array $store
     * @param array $translations language_code => [column of TRANSLATED_FIELDS => text]
     * @return array
     */
    public static function translationsWithoutOnlineAddress(array $store, array $translations): array
    {
        if (($store['store_type'] ?? null) !== self::TYPE_ONLINE) {
            return $translations;
        }

        return array_map(static fn (array $texts): array => array_merge($texts, array_fill_keys(self::TRANSLATED_ADDRESS_FIELDS, null)), $translations);
    }

    /**
     * Create a store with its names and addresses in other languages in one transaction, logged to log_activity in it, unless the business has
     * $maxStores stores already. The business's row is locked first (on MySQL), so two creates at once cannot both
     * take the last place.
     * @param array    $store     Checked business_store columns (localeErrors() included), business_id included
     * @param int      $actorId   auth_master.id of whoever creates it
     * @param array    $names     language_code => [column of TRANSLATED_FIELDS => text], from TranslationInputTrait;
     *                            optional. The address is left out for an online store
     * @param int|null $maxStores The business's cap (Config\PlanLimits::STORES); null for none
     * @return string|null The new store's public_id, or null when the business is at its cap; nothing is written then
     * @throws Throwable When a write fails (such as the name being taken meanwhile); nothing is written then
     */
    public function createWithNames(array $store, int $actorId, array $names = [], ?int $maxStores = null): ?string
    {
        $store = self::withoutOnlineAddress($store);
        $names = self::translationsWithoutOnlineAddress($store, $names);
        $log   = new ActivityLog();

        $publicId = $this->withPublicId(fn (string $publicId) => $this->inTransaction(function () use ($store, $actorId, $names, $log, $publicId, $maxStores) {
            if ($maxStores !== null) {
                if ($this->db->DBDriver === 'MySQLi') {
                    $this->db->query('SELECT id FROM ' . $this->db->escapeIdentifiers($this->db->prefixTable('business_master')) . ' WHERE id = ? FOR UPDATE', [$store['business_id']]);
                }
                if ($this->db->table($this->table)->where('business_id', $store['business_id'])->countAllResults() >= $maxStores) {
                    return false;
                }
            }
            $store = ['public_id' => $publicId] + $store;
            $this->db->table($this->table)->insert($store);
            $id = (int) $this->db->insertID();
            $log->inserted($actorId, 'Created store ' . $publicId . ' of business ' . $store['business_id'], $this->table, $id, $store);
            $this->writeTranslations(self::TRANSLATION_TABLE, 'store_id', $id, 'store ' . $publicId, [], $names, $actorId, $log);

            return $publicId;
        }));

        return $publicId === false ? null : $publicId;
    }

    /**
     * Update a store and its names and addresses in other languages in one transaction, logged to log_activity in it.
     * Only the columns and names whose value changes are written and logged.
     * @param array      $stored  The business_store row
     * @param array      $store   Checked business_store columns
     * @param int        $actorId auth_master.id of whoever makes the change
     * @param array|null $names   language_code => [column of TRANSLATED_FIELDS => text], from TranslationInputTrait;
     *                            a language left out loses its translation. Null leaves them as they are, but for
     *                            the address of a store that becomes an online store
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
        $this->inTransaction(function () use ($stored, $store, $id, $changes, $actorId, $names, $log) {
            if ($changes !== []) {
                $this->db->table($this->table)->where('id', $id)->update($changes);
                $log->updated($actorId, 'Updated store ' . $stored['public_id'], $this->table, $id, $stored, $changes);
            }
            $storedNames = $this->translationsOfStore($id);
            $online      = ['store_type' => $store['store_type'] ?? $stored['store_type']];
            $names       = self::translationsWithoutOnlineAddress($online, $names ?? $storedNames);
            $this->writeTranslations(self::TRANSLATION_TABLE, 'store_id', $id, 'store ' . $stored['public_id'], $storedNames, $names, $actorId, $log, true);
        });
    }
}
