<?php

namespace App\Models;

use App\Libraries\ActivityLog;
use CodeIgniter\Model;
use Config\BusinessPayment;
use InvalidArgumentException;
use Throwable;

/**
 * Model for business_payment_method: the ways the customers of a business can pay it, at every store (store_id NULL)
 * or at one, with their names and instructions in other languages in business_payment_method_translation.
 * Which method_code and handler_code go together, and what settings a handler needs, is in Config\BusinessPayment
 * and checked by ruleErrors(). secret_json never leaves this model.
 *
 * Names: a store's customers see the business-wide methods and the store's own together, so no two of those may
 * share a name: a business-wide method's name is unique within the business, a store's within its store and the
 * business-wide ones. Two stores may each have a "Bank transfer".
 */
class BusinessPaymentMethodModel extends Model
{
    use PublicIdTrait;
    use TransactionTrait;
    use TranslationWriteTrait;

    public const TRANSLATION_TABLE = 'business_payment_method_translation';

    /** Columns of TRANSLATION_TABLE, the name (which a language cannot do without) first */
    public const TRANSLATED_FIELDS = ['method_name', 'instruction'];

    /** Columns fixed once the method is created */
    public const FIXED_FIELDS = ['business_id'];

    protected $table            = 'business_payment_method';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'public_id',
        'business_id',
        'store_id',
        'method_code',
        'handler_code',
        'method_name',
        'instruction',
        'settings_json',
        'secret_json',
        'is_active',
        'sort_order',
    ];

    /** public_id: CHAR(12), see PublicIdTrait */
    protected int $publicIdLength = 12;
    protected $beforeInsert       = ['fillPublicId'];

    // created_at and updated_at are maintained by the database
    protected $useTimestamps = false;

    // the codes and settings are checked by ruleErrors(), against Config\BusinessPayment
    protected $validationRules = [
        'business_id'  => 'required|is_natural_no_zero',
        'store_id'     => 'permit_empty|is_natural_no_zero',
        'method_code'  => 'required|max_length[30]',
        'handler_code' => 'required|max_length[30]',
        'method_name'  => 'required|max_length[100]',
        'instruction'  => 'permit_empty|max_length[2000]',
        'is_active'    => 'required|in_list[Y,N]',
        'sort_order'   => 'required|is_natural|less_than_equal_to[255]',
    ];
    protected $skipValidation       = false;
    protected $cleanValidationRules = true;

    /** The longest instruction taken, in characters; the translations' too */
    public const INSTRUCTION_MAX = 2000;

    /**
     * Errors of $method against Config\BusinessPayment: an unknown method or handler, a handler that does not take the
     * method, a missing instruction where the handler shows nothing else, a missing or malformed setting
     * @param array $method business_payment_method columns, with settings (key => value) in place of settings_json
     * @return array field => error
     */
    public static function ruleErrors(array $method): array
    {
        $config  = config(BusinessPayment::class);
        $errors  = [];
        $handler = $config->handlers[$method['handler_code'] ?? ''] ?? null;
        if (! in_array($method['method_code'] ?? null, $config->methods, true)) {
            $errors['method_code'] = lang('BusinessPaymentMethod.invalidMethod');
        }
        if ($handler === null) {
            $errors['handler_code'] = lang('BusinessPaymentMethod.invalidHandler');

            return $errors;
        }
        if (! isset($errors['method_code']) && ! in_array($method['method_code'], $handler['methods'], true)) {
            $errors['handler_code'] = lang('BusinessPaymentMethod.handlerMismatch');
        }
        if ($handler['instruction'] && ($method['instruction'] ?? null) === null) {
            $errors['instruction'] = lang('BusinessPaymentMethod.instructionRequired');
        }
        foreach ($handler['settings'] as $key => $pattern) {
            $value = $method['settings'][$key] ?? null;
            if ($value === null || ! preg_match($pattern, $value)) {
                $errors['settings.' . $key] = lang('BusinessPaymentMethod.invalidSetting.' . $key);
            }
        }

        return $errors;
    }

    /**
     * $settings as the handler takes them: only its keys, spaces and dashes taken out, empty ones left out
     * @param string $handlerCode
     * @param mixed  $settings From the request, or the stored ones
     * @return array<string, string>
     */
    public static function settingsFor(string $handlerCode, mixed $settings): array
    {
        $keys = array_keys(config(BusinessPayment::class)->handlers[$handlerCode]['settings'] ?? []);
        $out  = [];
        foreach ($keys as $key) {
            $value = $settings[$key] ?? null;
            if (is_int($value)) {
                $value = (string) $value;
            }
            if (is_string($value) && ($value = preg_replace('/[\s-]+/', '', $value)) !== '') {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * The settings of a stored row
     * @param array $row
     * @return array<string, string>
     */
    public static function settingsOf(array $row): array
    {
        $settings = json_decode((string) ($row['settings_json'] ?? ''), true);

        return is_array($settings) ? $settings : [];
    }

    /**
     * The payment methods of a business, by sort_order and name, with their names and instructions in other
     * languages. The ids and secret_json stay inside; a method goes out by its public_id, its store by
     * store_public_id (null for every store).
     * @param int      $businessId
     * @param int|null $storeId    Only those offered at this store: the business-wide ones and its own
     * @return list<array>
     */
    public function listOfBusiness(int $businessId, ?int $storeId = null): array
    {
        $builder = $this->db->table($this->table . ' m')
            ->select('m.id, m.public_id, s.public_id AS store_public_id, m.method_code, m.handler_code, m.method_name, m.instruction, m.settings_json, m.is_active, m.sort_order')
            ->join('business_store s', 's.id = m.store_id', 'left')
            ->where('m.business_id', $businessId);
        if ($storeId !== null) {
            $builder->groupStart()->where('m.store_id', null)->orWhere('m.store_id', $storeId)->groupEnd();
        }
        $rows = $builder->orderBy('m.sort_order', 'ASC')->orderBy('m.method_name', 'ASC')->orderBy('m.id', 'ASC')->get()->getResultArray();
        if ($rows === []) {
            return [];
        }
        $translations = $this->translationsOf(self::TRANSLATION_TABLE, 'payment_method_id', array_map('intval', array_column($rows, 'id')), self::TRANSLATED_FIELDS);
        $handlers     = config(BusinessPayment::class)->handlers;

        return array_map(static function (array $row) use ($translations, $handlers) {
            return [
                'public_id'       => $row['public_id'],
                'store_public_id' => $row['store_public_id'],
                'method_code'     => $row['method_code'],
                'handler_code'    => $row['handler_code'],
                'confirmation'    => $handlers[$row['handler_code']]['confirmation'] ?? null,
                'method_name'     => $row['method_name'],
                'instruction'     => $row['instruction'],
                'settings'        => (object) self::settingsOf($row), // {} rather than [] when there are none
                'is_active'       => $row['is_active'],
                'sort_order'      => (int) $row['sort_order'],
                'translations'    => $translations[(int) $row['id']] ?? [],
            ];
        }, $rows);
    }

    /**
     * Whether $name clashes with another method its customers would see beside it (see the class's comment)
     * @param int      $businessId
     * @param int|null $storeId  Null for a business-wide method
     * @param string   $name
     * @param int|null $exceptId The method being edited
     * @return bool
     */
    public function nameTaken(int $businessId, ?int $storeId, string $name, ?int $exceptId = null): bool
    {
        $builder = $this->db->table($this->table)->where('business_id', $businessId)->where('method_name', $name);
        if ($storeId !== null) {
            $builder->groupStart()->where('store_id', null)->orWhere('store_id', $storeId)->groupEnd();
        }
        if ($exceptId !== null) {
            $builder->where('id !=', $exceptId);
        }

        return $builder->countAllResults() > 0;
    }

    /**
     * The translations of a method
     * @param int $id
     * @return array language_code => [column of TRANSLATED_FIELDS => text]
     */
    public function translationsOfMethod(int $id): array
    {
        return $this->translationsOf(self::TRANSLATION_TABLE, 'payment_method_id', [$id], self::TRANSLATED_FIELDS)[$id] ?? [];
    }

    /**
     * Create a method with its translations in one transaction, logged to log_activity in it, unless its name is
     * taken (nameTaken()). The business's row is locked first (on MySQL), so two writes at once cannot both take a name.
     * @param array $method  Checked columns (ruleErrors() included), business_id included, settings_json encoded
     * @param int   $actorId auth_master.id of whoever creates it
     * @param array $names   language_code => [column of TRANSLATED_FIELDS => text], from TranslationInputTrait
     * @return string|null The new method's public_id, or null when the name is taken; nothing is written then
     * @throws Throwable When a write fails; nothing is written then
     */
    public function createWithNames(array $method, int $actorId, array $names = []): ?string
    {
        $log      = new ActivityLog();
        $publicId = $this->withPublicId(fn (string $publicId) => $this->inTransaction(function () use ($method, $actorId, $names, $log, $publicId) {
            $this->lockBusiness((int) $method['business_id']);
            if ($this->nameTaken((int) $method['business_id'], $method['store_id'], $method['method_name'])) {
                return false;
            }
            $method = ['public_id' => $publicId] + $method;
            $this->db->table($this->table)->insert($method);
            $id = (int) $this->db->insertID();
            $log->inserted($actorId, 'Created payment method ' . $publicId . ' of business ' . $method['business_id'], $this->table, $id, $method);
            $this->writeTranslations(self::TRANSLATION_TABLE, 'payment_method_id', $id, 'payment method ' . $publicId, [], $names, $actorId, $log);

            return $publicId;
        }));

        return $publicId === false ? null : $publicId;
    }

    /**
     * Update a method and its translations in one transaction, logged to log_activity in it, unless its name is
     * taken. Only what changes is written and logged.
     * @param array      $stored  The business_payment_method row
     * @param array      $method  Checked columns
     * @param int        $actorId
     * @param array|null $names   language_code => [column => text]; a language left out loses its translation.
     *                            Null leaves them as they are
     * @return bool False when the name is taken; nothing is written then
     * @throws InvalidArgumentException When $method changes a column of FIXED_FIELDS
     * @throws Throwable When a write fails; nothing is written then
     */
    public function updateWithNames(array $stored, array $method, int $actorId, ?array $names = null): bool
    {
        $changes = ActivityLog::changes($stored, $method);
        $fixed   = array_intersect(array_keys($changes), self::FIXED_FIELDS);
        if ($fixed !== []) {
            throw new InvalidArgumentException('Fixed once the payment method is created: ' . implode(', ', $fixed));
        }
        $id  = (int) $stored['id'];
        $log = new ActivityLog();

        return $this->inTransaction(function () use ($stored, $method, $id, $changes, $actorId, $names, $log) {
            $this->lockBusiness((int) $stored['business_id']);
            $storeId = array_key_exists('store_id', $method) ? $method['store_id'] : $stored['store_id'];
            $storeId = $storeId === null ? null : (int) $storeId;
            if ($this->nameTaken((int) $stored['business_id'], $storeId, $method['method_name'] ?? $stored['method_name'], $id)) {
                return false;
            }
            if ($changes !== []) {
                $this->db->table($this->table)->where('id', $id)->update($changes);
                $log->updated($actorId, 'Updated payment method ' . $stored['public_id'], $this->table, $id, $stored, $changes);
            }
            if ($names !== null) {
                $this->writeTranslations(self::TRANSLATION_TABLE, 'payment_method_id', $id, 'payment method ' . $stored['public_id'], $this->translationsOfMethod($id), $names, $actorId, $log, true);
            }

            return true;
        });
    }

    /**
     * Lock the business's row until the transaction ends (MySQL only; SQLite locks the whole database on a write)
     * @param int $businessId
     * @return void
     */
    private function lockBusiness(int $businessId): void
    {
        if ($this->db->DBDriver === 'MySQLi') {
            $this->db->query('SELECT id FROM ' . $this->db->escapeIdentifiers($this->db->prefixTable('business_master')) . ' WHERE id = ? FOR UPDATE', [$businessId]);
        }
    }
}
