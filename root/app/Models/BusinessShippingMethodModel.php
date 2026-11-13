<?php

namespace App\Models;

use App\Libraries\ActivityLog;
use CodeIgniter\Model;
use InvalidArgumentException;
use Throwable;

/**
 * Model for business_shipping_method: the ways a customer of a business can get a physical product, at every store
 * (store_id NULL) or at one, with their names and descriptions in other languages in
 * business_shipping_method_translation, and their fee brackets in business_shipping_rate (see
 * CreateBusinessShippingMethodTables for how a fee is worked out). rateErrors() checks the brackets, as the database
 * cannot; they are always written as a whole, replacing the stored ones.
 *
 * Names: as with BusinessPaymentMethodModel, a store's customers see the business-wide methods and the store's own
 * together, so a business-wide method's name is unique within the business, a store's within its store and the
 * business-wide ones.
 */
class BusinessShippingMethodModel extends Model
{
    use PublicIdTrait;
    use TransactionTrait;
    use TranslationWriteTrait;

    public const TRANSLATION_TABLE = 'business_shipping_method_translation';
    public const RATE_TABLE        = 'business_shipping_rate';

    /** Columns of TRANSLATION_TABLE, the name (which a language cannot do without) first */
    public const TRANSLATED_FIELDS = ['method_name', 'description'];

    /** Columns of RATE_TABLE that come from the request, all DECIMAL(19,2) */
    public const RATE_FIELDS = ['range_from', 'range_to', 'fee_amount', 'step_size', 'step_fee'];

    /** Columns fixed once the method is created */
    public const FIXED_FIELDS = ['business_id'];

    /** method_type: home delivery, locker or pickup point, store pickup */
    public const METHOD_TYPES = ['H', 'L', 'S'];

    /** fee_basis: the order's subtotal, its weight in grams */
    public const FEE_BASES = ['P', 'W'];

    /** The most brackets a method may have */
    public const RATES_MAX = 50;

    /** The longest description taken, in characters; the translations' too */
    public const DESCRIPTION_MAX = 2000;

    /** An amount as DECIMAL(19,2) takes it, at most 2 decimals; a weight in grams is a whole number */
    private const AMOUNT_PATTERN = '/^\d{1,15}(\.\d{1,2})?$/';
    private const GRAMS_PATTERN  = '/^\d{1,15}(\.0{1,2})?$/';

    protected $table            = 'business_shipping_method';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'public_id',
        'business_id',
        'store_id',
        'method_type',
        'method_name',
        'description',
        'fee_basis',
        'free_over_amount',
        'eta_days_min',
        'eta_days_max',
        'is_active',
        'sort_order',
    ];

    /** public_id: CHAR(12), see PublicIdTrait */
    protected int $publicIdLength = 12;
    protected $beforeInsert       = ['fillPublicId'];

    // created_at and updated_at are maintained by the database
    protected $useTimestamps = false;

    // free_over_amount is checked by methodErrors(), as an amount; the brackets by rateErrors()
    protected $validationRules = [
        'business_id'  => 'required|is_natural_no_zero',
        'store_id'     => 'permit_empty|is_natural_no_zero',
        'method_type'  => 'required|in_list[H,L,S]',
        'method_name'  => 'required|max_length[100]',
        'description'  => 'permit_empty|max_length[2000]',
        'fee_basis'    => 'required|in_list[P,W]',
        'eta_days_min' => 'permit_empty|is_natural|less_than_equal_to[255]',
        'eta_days_max' => 'permit_empty|is_natural|less_than_equal_to[255]',
        'is_active'    => 'required|in_list[Y,N]',
        'sort_order'   => 'required|is_natural|less_than_equal_to[255]',
    ];
    protected $skipValidation       = false;
    protected $cleanValidationRules = true;

    /**
     * An amount from the request (or the database) as DECIMAL(19,2) keeps it, "50" and 50.5 as "50.00" and "50.50"
     * @param mixed $value
     * @return string|null Null when it is empty; the value untouched (to be refused) when it is not an amount
     */
    public static function decimal(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }
        if (! is_string($value) || ! preg_match(self::AMOUNT_PATTERN, $value = trim($value))) {
            return $value;
        }
        [$whole, $fraction] = explode('.', $value . '.');

        return ltrim($whole, '0') === '' ? '0.' . str_pad($fraction, 2, '0') : ltrim($whole, '0') . '.' . str_pad($fraction, 2, '0');
    }

    /**
     * Errors of the method's columns that the validation rules cannot say: free_over_amount an amount, eta_days_min
     * no more than eta_days_max
     * @param array $method business_shipping_method columns, free_over_amount through decimal()
     * @return array field => error
     */
    public static function methodErrors(array $method): array
    {
        $errors = [];
        $free   = $method['free_over_amount'] ?? null;
        if ($free !== null && (! is_string($free) || ! preg_match(self::AMOUNT_PATTERN, $free))) {
            $errors['free_over_amount'] = lang('BusinessShippingMethod.invalidAmount');
        }
        $min = $method['eta_days_min'] ?? null;
        $max = $method['eta_days_max'] ?? null;
        if (is_numeric($min) && is_numeric($max) && (int) $min > (int) $max) {
            $errors['eta_days_max'] = lang('BusinessShippingMethod.etaOrder');
        }

        return $errors;
    }

    /**
     * The brackets of the request, checked: each an object of RATE_FIELDS; amounts (grams, whole, when $feeBasis is W);
     * range_to above range_from, or null for no upper bound; step_size (above 0) and step_fee both or neither; and,
     * sorted by range_from, none overlapping the next, so only the last may have no upper bound. Gaps are allowed: an
     * order that falls in one is not offered the method.
     * @param mixed  $in       The value of "rates" in the body
     * @param string $feeBasis P or W
     * @return array{0: list<array<string, string|null>>|null, 1: array<string, string>}
     *               [the brackets sorted by range_from, field => error]; the brackets are null when $in is not a list
     */
    public static function ratesIn(mixed $in, string $feeBasis): array
    {
        if (! is_array($in) || ! array_is_list($in)) {
            return [null, ['rates' => lang('BusinessShippingMethod.invalidRates')]];
        }
        if (count($in) > self::RATES_MAX) {
            return [[], ['rates' => lang('BusinessShippingMethod.tooManyRates', [self::RATES_MAX])]];
        }
        $rangePattern = $feeBasis === 'W' ? self::GRAMS_PATTERN : self::AMOUNT_PATTERN;
        $rates        = [];
        $errors       = [];
        foreach ($in as $i => $rateIn) {
            $key = 'rates.' . $i;
            if (! is_array($rateIn) || ($rateIn !== [] && array_is_list($rateIn))) {
                $errors[$key] = lang('BusinessShippingMethod.invalidRate');

                continue;
            }
            $rate = [];
            foreach (self::RATE_FIELDS as $field) {
                $rate[$field] = self::decimal($rateIn[$field] ?? null);
            }
            $rate['range_from'] ??= '0.00';
            $rate['fee_amount'] ??= '0.00';
            foreach (self::RATE_FIELDS as $field) {
                $pattern = in_array($field, ['range_from', 'range_to', 'step_size'], true) ? $rangePattern : self::AMOUNT_PATTERN;
                if ($rate[$field] !== null && (! is_string($rate[$field]) || ! preg_match($pattern, $rate[$field]))) {
                    $errors[$key . '.' . $field] = lang($feeBasis === 'W' && $pattern === $rangePattern ? 'BusinessShippingMethod.invalidGrams' : 'BusinessShippingMethod.invalidAmount');
                }
            }
            if (array_intersect_key($errors, array_flip(array_map(static fn ($field) => $key . '.' . $field, self::RATE_FIELDS))) !== []) {
                continue;
            }
            if ($rate['range_to'] !== null && (float) $rate['range_to'] <= (float) $rate['range_from']) {
                $errors[$key . '.range_to'] = lang('BusinessShippingMethod.rangeOrder');
            }
            if (($rate['step_size'] === null) !== ($rate['step_fee'] === null)) {
                $errors[$key . '.' . ($rate['step_size'] === null ? 'step_size' : 'step_fee')] = lang('BusinessShippingMethod.stepPair');
            } elseif ($rate['step_size'] !== null && (float) $rate['step_size'] <= 0) {
                $errors[$key . '.step_size'] = lang('BusinessShippingMethod.stepSize');
            }
            $rates[$i] = $rate;
        }
        if ($errors !== []) {
            return [array_values($rates), $errors];
        }

        uasort($rates, static fn (array $a, array $b) => (float) $a['range_from'] <=> (float) $b['range_from']);
        $previous = null;
        foreach ($rates as $i => $rate) {
            if ($previous !== null && ($previous['range_to'] === null || (float) $previous['range_to'] > (float) $rate['range_from'])) {
                $errors['rates.' . $i . '.range_from'] = lang('BusinessShippingMethod.rangeOverlap');
            }
            $previous = $rate;
        }

        return [array_values($rates), $errors];
    }

    /**
     * The shipping methods of a business, by sort_order and name, with their brackets (by range_from) and their names
     * and descriptions in other languages. The ids stay inside; a method goes out by its public_id, its store by
     * store_public_id (null for every store). Amounts and grams are strings with 2 decimals, as DECIMAL(19,2) has them.
     * @param int      $businessId
     * @param int|null $storeId    Only those offered at this store: the business-wide ones and its own
     * @return list<array>
     */
    public function listOfBusiness(int $businessId, ?int $storeId = null): array
    {
        $builder = $this->db->table($this->table . ' m')
            ->select('m.id, m.public_id, s.public_id AS store_public_id, m.method_type, m.method_name, m.description, m.fee_basis, m.free_over_amount, m.eta_days_min, m.eta_days_max, m.is_active, m.sort_order')
            ->join('business_store s', 's.id = m.store_id', 'left')
            ->where('m.business_id', $businessId);
        if ($storeId !== null) {
            $builder->groupStart()->where('m.store_id', null)->orWhere('m.store_id', $storeId)->groupEnd();
        }
        $rows = $builder->orderBy('m.sort_order', 'ASC')->orderBy('m.method_name', 'ASC')->orderBy('m.id', 'ASC')->get()->getResultArray();
        if ($rows === []) {
            return [];
        }
        $ids          = array_map('intval', array_column($rows, 'id'));
        $translations = $this->translationsOf(self::TRANSLATION_TABLE, 'shipping_method_id', $ids, self::TRANSLATED_FIELDS);
        $rates        = $this->ratesOf($ids);

        return array_map(static fn (array $row) => [
            'public_id'        => $row['public_id'],
            'store_public_id'  => $row['store_public_id'],
            'method_type'      => $row['method_type'],
            'method_name'      => $row['method_name'],
            'description'      => $row['description'],
            'fee_basis'        => $row['fee_basis'],
            'free_over_amount' => self::decimal($row['free_over_amount']),
            'eta_days_min'     => $row['eta_days_min'] === null ? null : (int) $row['eta_days_min'],
            'eta_days_max'     => $row['eta_days_max'] === null ? null : (int) $row['eta_days_max'],
            'is_active'        => $row['is_active'],
            'sort_order'       => (int) $row['sort_order'],
            'rates'            => $rates[(int) $row['id']] ?? [],
            'translations'     => $translations[(int) $row['id']] ?? [],
        ], $rows);
    }

    /**
     * The brackets of methods, by range_from, as RATE_FIELDS through decimal()
     * @param list<int> $methodIds
     * @return array<int, list<array<string, string|null>>> shipping_method_id => brackets
     */
    public function ratesOf(array $methodIds): array
    {
        if ($methodIds === []) {
            return [];
        }
        $rows = $this->db->table(self::RATE_TABLE)
            ->select('shipping_method_id, ' . implode(', ', self::RATE_FIELDS))
            ->whereIn('shipping_method_id', $methodIds)
            ->orderBy('shipping_method_id', 'ASC')->orderBy('range_from', 'ASC')
            ->get()->getResultArray();
        $out = [];
        foreach ($rows as $row) {
            $rate = [];
            foreach (self::RATE_FIELDS as $field) {
                $rate[$field] = self::decimal($row[$field]);
            }
            $out[(int) $row['shipping_method_id']][] = $rate;
        }

        return $out;
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
        return $this->translationsOf(self::TRANSLATION_TABLE, 'shipping_method_id', [$id], self::TRANSLATED_FIELDS)[$id] ?? [];
    }

    /**
     * Create a method with its brackets and translations in one transaction, logged to log_activity in it, unless its
     * name is taken (nameTaken()). The business's row is locked first (on MySQL), so two writes at once cannot both
     * take a name.
     * @param array $method  Checked columns, business_id included
     * @param int   $actorId auth_master.id of whoever creates it
     * @param array $rates   From ratesIn(), checked
     * @param array $names   language_code => [column of TRANSLATED_FIELDS => text], from TranslationInputTrait
     * @return string|null The new method's public_id, or null when the name is taken; nothing is written then
     * @throws Throwable When a write fails; nothing is written then
     */
    public function createWithRates(array $method, int $actorId, array $rates = [], array $names = []): ?string
    {
        $log      = new ActivityLog();
        $publicId = $this->withPublicId(fn (string $publicId) => $this->inTransaction(function () use ($method, $actorId, $rates, $names, $log, $publicId) {
            $this->lockBusiness((int) $method['business_id']);
            if ($this->nameTaken((int) $method['business_id'], $method['store_id'], $method['method_name'])) {
                return false;
            }
            $method = ['public_id' => $publicId] + $method;
            $this->db->table($this->table)->insert($method);
            $id = (int) $this->db->insertID();
            $log->inserted($actorId, 'Created shipping method ' . $publicId . ' of business ' . $method['business_id'], $this->table, $id, $method);
            $this->writeRates($id, $publicId, [], $rates, $actorId, $log);
            $this->writeTranslations(self::TRANSLATION_TABLE, 'shipping_method_id', $id, 'shipping method ' . $publicId, [], $names, $actorId, $log);

            return $publicId;
        }));

        return $publicId === false ? null : $publicId;
    }

    /**
     * Update a method, its brackets and its translations in one transaction, logged to log_activity in it, unless its
     * name is taken. Only what changes is written and logged.
     * @param array      $stored  The business_shipping_method row
     * @param array      $method  Checked columns
     * @param int        $actorId
     * @param array|null $rates   From ratesIn(), replacing the stored brackets; null leaves them as they are
     * @param array|null $names   language_code => [column => text]; a language left out loses its translation.
     *                            Null leaves them as they are
     * @return bool False when the name is taken; nothing is written then
     * @throws InvalidArgumentException When $method changes a column of FIXED_FIELDS
     * @throws Throwable When a write fails; nothing is written then
     */
    public function updateWithRates(array $stored, array $method, int $actorId, ?array $rates = null, ?array $names = null): bool
    {
        $changes = ActivityLog::changes($stored, $method);
        $fixed   = array_intersect(array_keys($changes), self::FIXED_FIELDS);
        if ($fixed !== []) {
            throw new InvalidArgumentException('Fixed once the shipping method is created: ' . implode(', ', $fixed));
        }
        $id  = (int) $stored['id'];
        $log = new ActivityLog();

        return $this->inTransaction(function () use ($stored, $method, $id, $changes, $actorId, $rates, $names, $log) {
            $this->lockBusiness((int) $stored['business_id']);
            $storeId = array_key_exists('store_id', $method) ? $method['store_id'] : $stored['store_id'];
            $storeId = $storeId === null ? null : (int) $storeId;
            if ($this->nameTaken((int) $stored['business_id'], $storeId, $method['method_name'] ?? $stored['method_name'], $id)) {
                return false;
            }
            if ($changes !== []) {
                $this->db->table($this->table)->where('id', $id)->update($changes);
                $log->updated($actorId, 'Updated shipping method ' . $stored['public_id'], $this->table, $id, $stored, $changes);
            }
            if ($rates !== null) {
                $this->writeRates($id, $stored['public_id'], $this->ratesOf([$id])[$id] ?? [], $rates, $actorId, $log);
            }
            if ($names !== null) {
                $this->writeTranslations(self::TRANSLATION_TABLE, 'shipping_method_id', $id, 'shipping method ' . $stored['public_id'], $this->translationsOfMethod($id), $names, $actorId, $log, true);
            }

            return true;
        });
    }

    /**
     * Replace a method's brackets with $new, unless they are the same as $stored; each bracket deleted and inserted is
     * logged
     * @param int    $methodId
     * @param string $publicId
     * @param list<array> $stored From ratesOf(), by range_from
     * @param list<array> $new    From ratesIn(), by range_from
     * @param int         $actorId
     * @param ActivityLog $log
     * @return void
     */
    private function writeRates(int $methodId, string $publicId, array $stored, array $new, int $actorId, ActivityLog $log): void
    {
        if ($stored === $new) {
            return;
        }
        $rows = $this->db->table(self::RATE_TABLE)->where('shipping_method_id', $methodId)->get()->getResultArray();
        foreach ($rows as $row) {
            $log->deleted($actorId, 'Deleted a fee bracket of shipping method ' . $publicId, self::RATE_TABLE, (int) $row['id'], $row);
        }
        $this->db->table(self::RATE_TABLE)->where('shipping_method_id', $methodId)->delete();
        foreach ($new as $rate) {
            $row = ['shipping_method_id' => $methodId] + $rate;
            $this->db->table(self::RATE_TABLE)->insert($row);
            $log->inserted($actorId, 'Added a fee bracket to shipping method ' . $publicId, self::RATE_TABLE, (int) $this->db->insertID(), $row);
        }
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
