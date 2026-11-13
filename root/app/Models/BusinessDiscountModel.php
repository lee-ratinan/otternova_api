<?php

namespace App\Models;

use App\Libraries\ActivityLog;
use App\Libraries\Money;
use CodeIgniter\Model;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;

/**
 * Model for business_discount: the discount codes a business gives its own customers on the mart (see the migration
 * CreateBusinessDiscountTable for what each column means). Not to be confused with DiscountMasterModel, Otternova's
 * discounts on its plans. MartOrderModel reads these rows and counts redemption_count; this model writes the rest.
 *
 * Codes: upper case, letters, digits, dashes and underscores; unique within the business (codeTaken()).
 * Nothing is deleted: a code is made inactive instead, as the orders placed with it point to it.
 */
class BusinessDiscountModel extends Model
{
    use PublicIdTrait;
    use TransactionTrait;

    /** Columns fixed once the discount is created; redemption_count is the mart's */
    public const FIXED_FIELDS = ['business_id', 'redemption_count'];

    public const TYPE_PERCENT = 'P';
    public const TYPE_FIXED   = 'F';

    /** What a code may be made of, once upper-cased */
    public const CODE_PATTERN = '/^[A-Z0-9][A-Z0-9_-]{2,29}$/';

    /** A datetime as the API takes and gives it, in UTC */
    public const DATETIME_FORMAT = 'Y-m-d H:i:s';

    /** The most redemptions max_redemptions may allow (INT UNSIGNED) */
    public const REDEMPTIONS_MAX = 4294967295;

    /** An amount as DECIMAL(19,2) takes it */
    private const AMOUNT_PATTERN = '/^\d{1,15}(\.\d{1,2})?$/';

    protected $table            = 'business_discount';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'public_id',
        'business_id',
        'discount_code',
        'description',
        'discount_type',
        'discount_value',
        'applies_to',
        'min_subtotal',
        'max_redemptions',
        'redemption_count',
        'valid_from',
        'expires_at',
        'is_active',
    ];

    /** public_id: CHAR(12), see PublicIdTrait */
    protected int $publicIdLength = 12;
    protected $beforeInsert       = ['fillPublicId'];

    // created_at and updated_at are maintained by the database
    protected $useTimestamps = false;

    // the code's form, the amounts, the dates and how they go together are checked by ruleErrors()
    protected $validationRules = [
        'business_id'     => 'required|is_natural_no_zero',
        'discount_code'   => 'required|max_length[30]',
        'description'     => 'permit_empty|max_length[255]',
        'discount_type'   => 'required|in_list[P,F]',
        'applies_to'      => 'required|in_list[A,P,S]',
        'max_redemptions' => 'permit_empty|is_natural_no_zero|less_than_equal_to[4294967295]',
        'is_active'       => 'required|in_list[Y,N]',
    ];
    protected $skipValidation       = false;
    protected $cleanValidationRules = true;

    /**
     * An amount from the request (or the database) as DECIMAL(19,2) keeps it, "50" and 50.5 as "50.00" and "50.50"
     * @param mixed $value
     * @return mixed Null when it is empty; the value untouched (to be refused) when it is not an amount
     */
    public static function decimal(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_float($value)) {
            $value = sprintf('%.2F', $value);
        } elseif (is_int($value)) {
            $value = (string) $value;
        }
        if (! is_string($value) || ! preg_match(self::AMOUNT_PATTERN, $value = trim($value))) {
            return $value;
        }

        return Money::normalize($value);
    }

    /**
     * A UTC datetime from the request (or the database) as the table keeps it, "2026-12-01T09:00" as
     * "2026-12-01 09:00:00"
     * @param mixed $value
     * @return mixed Null when it is empty; the value untouched (to be refused) when it is not a datetime
     */
    public static function datetime(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value) || ! preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2})(:\d{2})?$/', $value = trim($value), $m)) {
            return $value;
        }
        $text = $m[1] . ' ' . $m[2] . ($m[3] ?? ':00');
        $date = DateTimeImmutable::createFromFormat('!' . self::DATETIME_FORMAT, $text, new DateTimeZone('UTC'));

        return $date !== false && $date->format(self::DATETIME_FORMAT) === $text ? $text : $value;
    }

    /**
     * Errors of the discount's columns that the validation rules cannot say: the code's form, discount_value above
     * zero (and at most 100 percent), min_subtotal an amount, the dates datetimes with expires_at after valid_from,
     * max_redemptions not below the redemptions already made
     * @param array $discount business_discount columns, through decimal() and datetime(); redemption_count when stored
     * @return array field => error
     */
    public static function ruleErrors(array $discount): array
    {
        $errors = [];
        if (! is_string($discount['discount_code'] ?? null) || ! preg_match(self::CODE_PATTERN, $discount['discount_code'])) {
            $errors['discount_code'] = lang('BusinessDiscount.invalidCode');
        }
        $value = $discount['discount_value'] ?? null;
        if (! is_string($value) || ! preg_match(self::AMOUNT_PATTERN, $value) || Money::toCents($value) === 0) {
            $errors['discount_value'] = lang('BusinessDiscount.invalidValue');
        } elseif (($discount['discount_type'] ?? null) === self::TYPE_PERCENT && Money::toCents($value) > 10000) {
            $errors['discount_value'] = lang('BusinessDiscount.percentTooHigh');
        }
        $min = $discount['min_subtotal'] ?? null;
        if ($min !== null && (! is_string($min) || ! preg_match(self::AMOUNT_PATTERN, $min))) {
            $errors['min_subtotal'] = lang('BusinessDiscount.invalidAmount');
        }
        foreach (['valid_from', 'expires_at'] as $field) {
            $date = $discount[$field] ?? null;
            if ($date !== null && (! is_string($date) || ! preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $date) || self::datetime($date) !== $date)) {
                $errors[$field] = lang('BusinessDiscount.invalidDatetime');
            }
        }
        if (! isset($errors['valid_from']) && ! isset($errors['expires_at']) && ($discount['valid_from'] ?? null) !== null
            && ($discount['expires_at'] ?? null) !== null && $discount['expires_at'] <= $discount['valid_from']) {
            $errors['expires_at'] = lang('BusinessDiscount.expiresBeforeValid');
        }
        $max = $discount['max_redemptions'] ?? null;
        if (is_numeric($max) && (int) $max < (int) ($discount['redemption_count'] ?? 0)) {
            $errors['max_redemptions'] = lang('BusinessDiscount.belowRedeemed', [(int) $discount['redemption_count']]);
        }

        return $errors;
    }

    /**
     * The discounts of a business, by code. The ids stay inside; a discount goes out by its public_id. Amounts are
     * strings with 2 decimals, datetimes UTC as DATETIME_FORMAT.
     * @param int $businessId
     * @return list<array>
     */
    public function listOfBusiness(int $businessId): array
    {
        $rows = $this->db->table($this->table)
            ->select('public_id, discount_code, description, discount_type, discount_value, applies_to, min_subtotal, max_redemptions, redemption_count, valid_from, expires_at, is_active, created_at, updated_at')
            ->where('business_id', $businessId)
            ->orderBy('discount_code', 'ASC')->orderBy('id', 'ASC')
            ->get()->getResultArray();

        return array_map(static fn (array $row): array => [
            'public_id'        => $row['public_id'],
            'discount_code'    => $row['discount_code'],
            'description'      => $row['description'],
            'discount_type'    => $row['discount_type'],
            'discount_value'   => Money::format($row['discount_value']),
            'applies_to'       => $row['applies_to'],
            'min_subtotal'     => Money::format($row['min_subtotal']),
            'max_redemptions'  => $row['max_redemptions'] === null ? null : (int) $row['max_redemptions'],
            'redemption_count' => (int) $row['redemption_count'],
            'valid_from'       => $row['valid_from'],
            'expires_at'       => $row['expires_at'],
            'is_active'        => $row['is_active'],
            'created_at'       => $row['created_at'],
            'updated_at'       => $row['updated_at'],
        ], $rows);
    }

    /**
     * Whether another discount of the business has this code
     * @param int      $businessId
     * @param string   $code     Upper case, as the codes are kept
     * @param int|null $exceptId The discount being edited
     * @return bool
     */
    public function codeTaken(int $businessId, string $code, ?int $exceptId = null): bool
    {
        $builder = $this->db->table($this->table)->where('business_id', $businessId)->where('discount_code', $code);
        if ($exceptId !== null) {
            $builder->where('id !=', $exceptId);
        }

        return $builder->countAllResults() > 0;
    }

    /**
     * Create a discount in one transaction, logged to log_activity in it, unless its code is taken. The business's
     * row is locked first (on MySQL), so two writes at once cannot both take a code.
     * @param array $discount Checked columns (ruleErrors() included), business_id included
     * @param int   $actorId  auth_master.id of whoever creates it
     * @return string|null The new discount's public_id, or null when the code is taken; nothing is written then
     * @throws Throwable When a write fails; nothing is written then
     */
    public function createDiscount(array $discount, int $actorId): ?string
    {
        $log      = new ActivityLog();
        $publicId = $this->withPublicId(fn (string $publicId) => $this->inTransaction(function () use ($discount, $actorId, $log, $publicId) {
            $this->lockBusiness((int) $discount['business_id']);
            if ($this->codeTaken((int) $discount['business_id'], $discount['discount_code'])) {
                return false;
            }
            $discount = ['public_id' => $publicId] + $discount;
            $this->db->table($this->table)->insert($discount);
            $id = (int) $this->db->insertID();
            $log->inserted($actorId, 'Created discount ' . $publicId . ' of business ' . $discount['business_id'], $this->table, $id, $discount);

            return $publicId;
        }));

        return $publicId === false ? null : $publicId;
    }

    /**
     * Update a discount in one transaction, logged to log_activity in it, unless its code is taken. Only what changes
     * is written and logged.
     * @param array $stored   The business_discount row
     * @param array $discount Checked columns
     * @param int   $actorId
     * @return bool False when the code is taken; nothing is written then
     * @throws InvalidArgumentException When $discount changes a column of FIXED_FIELDS
     * @throws Throwable When a write fails; nothing is written then
     */
    public function updateDiscount(array $stored, array $discount, int $actorId): bool
    {
        $changes = ActivityLog::changes($stored, $discount);
        $fixed   = array_intersect(array_keys($changes), self::FIXED_FIELDS);
        if ($fixed !== []) {
            throw new InvalidArgumentException('Fixed once the discount is created: ' . implode(', ', $fixed));
        }
        $id  = (int) $stored['id'];
        $log = new ActivityLog();

        return $this->inTransaction(function () use ($stored, $discount, $id, $changes, $actorId, $log) {
            $this->lockBusiness((int) $stored['business_id']);
            if ($this->codeTaken((int) $stored['business_id'], $discount['discount_code'] ?? $stored['discount_code'], $id)) {
                return false;
            }
            if ($changes !== []) {
                $this->db->table($this->table)->where('id', $id)->update($changes);
                $log->updated($actorId, 'Updated discount ' . $stored['public_id'], $this->table, $id, $stored, $changes);
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
