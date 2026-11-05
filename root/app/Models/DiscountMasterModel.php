<?php

namespace App\Models;

use App\Libraries\Money;
use CodeIgniter\Model;

/**
 * Model for the discount_master table: discount codes a business applies to one invoice at checkout.
 * See the CreateDiscountMasterTable migration for who may use one and how the caps count.
 */
class DiscountMasterModel extends Model
{
    use LedgerWriteTrait;

    /** Columns /staff/plans/discount/update writes */
    public const FORM_FIELDS = [
        'discount_code', 'discount_description', 'discount_type', 'discount_value', 'currency_code', 'country_code',
        'business_id', 'plan_id', 'billing_cycle', 'is_listed', 'max_redemptions', 'max_per_business', 'valid_from', 'expires_at',
    ];
    public const FORM_NULLABLE = ['discount_description', 'currency_code', 'country_code', 'business_id', 'plan_id', 'billing_cycle', 'max_redemptions', 'max_per_business'];

    /** discount_type */
    public const TYPE_PERCENTAGE = 'P';
    public const TYPE_FIXED      = 'F';

    /** A discount_value as the body may give it: up to 2 decimal places */
    public const VALUE_PATTERN = '/^\d{1,17}(\.\d{1,2})?$/';

    protected $table            = 'discount_master';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = self::FORM_FIELDS;

    // created_at and updated_at are maintained by the database
    protected $useTimestamps = false;

    protected $validationRules = [
        'id'                   => 'permit_empty|is_natural_no_zero',
        'discount_code'        => 'required|regex_match[/^[A-Z0-9_-]{3,30}$/]|is_unique[discount_master.discount_code,id,{id}]',
        'discount_description' => 'permit_empty|max_length[255]',
        'discount_type'        => 'required|in_list[P,F]',
        'discount_value'       => 'required|regex_match[/^\d{1,17}\.\d{2}$/]',
        'currency_code'        => 'permit_empty|regex_match[/^[A-Z]{3}$/]',
        'country_code'         => 'permit_empty|regex_match[/^[A-Z]{2}$/]',
        'business_id'          => 'permit_empty|is_natural_no_zero',
        'plan_id'              => 'permit_empty|is_natural_no_zero',
        'billing_cycle'        => 'permit_empty|in_list[M,Y]',
        'is_listed'            => 'required|in_list[Y,N]',
        'max_redemptions'      => 'permit_empty|is_natural_no_zero|less_than_equal_to[4294967295]',
        'max_per_business'     => 'permit_empty|is_natural_no_zero|less_than_equal_to[255]',
        'valid_from'           => 'required|valid_date[Y-m-d H:i:s]',
        'expires_at'           => 'required|valid_date[Y-m-d H:i:s]',
    ];
    protected $skipValidation       = false;
    protected $cleanValidationRules = true;

    /** public_id: CHAR(8), see PublicIdTrait */
    protected int $publicIdLength = 8;
    protected $beforeInsert       = ['fillPublicId'];

    /**
     * @param mixed $code From the request; matched without case
     * @return array|null
     */
    public function findByCode(mixed $code): ?array
    {
        if (! is_string($code) || trim($code) === '') {
            return null;
        }

        return $this->db->table($this->table)->where('discount_code', strtoupper(trim($code)))->get()->getRowArray();
    }

    /**
     * One page of discounts, newest first
     * @param int    $start
     * @param int    $length
     * @param string $search Matched in discount_code and discount_description
     * @return array{records_total: int, records_filtered: int, rows: list<array>}
     */
    public function listDiscounts(int $start, int $length, string $search): array
    {
        $total   = $this->db->table($this->table)->countAllResults();
        $builder = $this->joinedBuilder();
        if ($search !== '') {
            $char = $this->db->likeEscapeChar;
            $term = str_replace([$char, '%', '_'], [$char . $char, $char . '%', $char . '_'], $search);
            $builder->groupStart()
                ->like('d.discount_code', $term, 'both', null, true)
                ->orLike('d.discount_description', $term, 'both', null, true)
                ->groupEnd();
        }
        $filtered = $builder->countAllResults(false);
        $rows     = $builder->orderBy('d.id', 'DESC')->limit($length, $start)->get()->getResultArray();

        return ['records_total' => $total, 'records_filtered' => $filtered, 'rows' => array_map(self::toPayload(...), $rows)];
    }

    /**
     * One discount as the API sends it
     * @param int $id
     * @return array|null
     */
    public function findPayload(int $id): ?array
    {
        $row = $this->joinedBuilder()->where('d.id', $id)->get()->getRowArray();

        return $row === null ? null : self::toPayload($row);
    }

    /**
     * The listed discounts that can be applied at $now, before the checks that need the business and the price
     * @param string $now UTC
     * @return list<array> Stored rows
     */
    public function listedAt(string $now): array
    {
        return $this->db->table($this->table)
            ->where('is_listed', 'Y')
            ->where('valid_from <=', $now)
            ->where('expires_at >', $now)
            ->orderBy('expires_at', 'ASC')
            ->orderBy('id', 'ASC')
            ->get()
            ->getResultArray();
    }

    /**
     * Why the business cannot apply the discount to an invoice for $version, as a Subscriptions language key;
     * null when it can. The overall cap is checked when it is reserved.
     * @param array    $discount        Stored row
     * @param array    $business        With id and country_code
     * @param array    $version         The invoice's plan_version row
     * @param string   $now             UTC
     * @param int|null $exceptInvoiceId The invoice it is being applied to, left out of the per-business count
     * @return string|null
     */
    public function eligibilityError(array $discount, array $business, array $version, string $now, ?int $exceptInvoiceId = null): ?string
    {
        if ($discount['valid_from'] > $now || $discount['expires_at'] <= $now) {
            return 'Subscriptions.discountExpired';
        }
        if ($discount['business_id'] !== null) {
            if ((int) $discount['business_id'] !== (int) $business['id']) {
                return 'Subscriptions.discountNotFound'; // another business's code is not shown to exist
            }
        } elseif ($discount['country_code'] !== null && $discount['country_code'] !== $version['country_code']) {
            return 'Subscriptions.discountNotForCountry';
        }
        if ($discount['plan_id'] !== null && (int) $discount['plan_id'] !== (int) $version['plan_id']) {
            return 'Subscriptions.discountNotForPlan';
        }
        if ($discount['billing_cycle'] !== null && $discount['billing_cycle'] !== $version['billing_cycle']) {
            return 'Subscriptions.discountNotForCycle';
        }
        if ($discount['discount_type'] === self::TYPE_FIXED && $discount['currency_code'] !== $version['currency_code']) {
            return 'Subscriptions.discountNotForCountry';
        }
        if ($discount['max_redemptions'] !== null && (int) $discount['redemption_count'] >= (int) $discount['max_redemptions']) {
            return 'Subscriptions.discountUsedUp';
        }
        if ($discount['max_per_business'] !== null) {
            $builder = $this->db->table('subscription_invoice i')
                ->join('subscription_master s', 's.id = i.subscription_id', 'inner')
                ->where('s.business_id', (int) $business['id'])
                ->where('i.discount_id', (int) $discount['id'])
                ->whereIn('i.invoice_status', ['P', 'D']);
            if ($exceptInvoiceId !== null) {
                $builder->where('i.id !=', $exceptInvoiceId);
            }
            if ($builder->countAllResults() >= (int) $discount['max_per_business']) {
                return 'Subscriptions.discountAlreadyUsed';
            }
        }

        return null;
    }

    /**
     * The discount on a price, in cents: a percentage rounded half up to the cent, a fixed amount at most the price
     * @param array $discount
     * @param int   $priceCents
     * @return int
     */
    public static function discountCents(array $discount, int $priceCents): int
    {
        $value = Money::toCents(Money::format($discount['discount_value']));
        if ($discount['discount_type'] === self::TYPE_PERCENTAGE) {
            // $value is the percentage times 100, so the price times it is 10 000 times the discount
            return min($priceCents, intdiv($priceCents * $value + 5000, 10000));
        }

        return min($priceCents, $value);
    }

    /**
     * Count one more use, unless the cap is reached: one UPDATE, so two requests can never both take the last one
     * @param int $id
     * @return bool False when the cap is reached
     */
    public function reserve(int $id): bool
    {
        $this->db->table($this->table)
            ->set('redemption_count', 'redemption_count + 1', false)
            ->where('id', $id)
            ->groupStart()->where('max_redemptions', null)->orWhere('redemption_count < max_redemptions', null, false)->groupEnd()
            ->update();

        return $this->db->affectedRows() > 0;
    }

    /**
     * Give back a use counted by reserve()
     * @param int $id
     * @return void
     */
    public function release(int $id): void
    {
        $this->db->table($this->table)
            ->set('redemption_count', 'redemption_count - 1', false)
            ->where('id', $id)
            ->where('redemption_count >', 0)
            ->update();
    }

    /**
     * A discount as the checkout lists it
     * @param array $row Stored row
     * @return array
     */
    public static function toCheckout(array $row): array
    {
        return [
            'discount_code'        => $row['discount_code'],
            'discount_description' => $row['discount_description'],
            'discount_type'        => $row['discount_type'],
            'discount_value'       => Money::format($row['discount_value']),
            'currency_code'        => $row['currency_code'],
            'expires_at'           => $row['expires_at'],
        ];
    }

    /**
     * @return \CodeIgniter\Database\BaseBuilder
     */
    private function joinedBuilder()
    {
        return $this->db->table($this->table . ' d')
            ->select('d.public_id, d.discount_code, d.discount_description, d.discount_type, d.discount_value, d.currency_code, d.country_code')
            ->select('b.business_slug, b.business_name, p.plan_code, d.billing_cycle, d.is_listed, d.max_redemptions, d.redemption_count')
            ->select('d.max_per_business, d.valid_from, d.expires_at, a.full_name AS created_by_name, d.created_at, d.updated_at')
            ->join('business_master b', 'b.id = d.business_id', 'left')
            ->join('plan_master p', 'p.id = d.plan_id', 'left')
            ->join('auth_master a', 'a.id = d.created_by', 'left');
    }

    /**
     * @param array $row
     * @return array
     */
    private static function toPayload(array $row): array
    {
        $row['discount_value'] = Money::format($row['discount_value']);
        foreach (['max_redemptions', 'redemption_count', 'max_per_business'] as $field) {
            $row[$field] = $row[$field] === null ? null : (int) $row[$field];
        }

        return $row;
    }
}
