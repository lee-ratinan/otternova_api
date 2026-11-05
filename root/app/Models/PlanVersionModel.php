<?php

namespace App\Models;

use App\Libraries\ActivityLog;
use App\Libraries\Money;
use CodeIgniter\Model;
use Throwable;

/**
 * Model for the plan_version table: the price of a plan in one market for one billing cycle over time.
 * A series is plan_id, country_code and billing_cycle; see the CreatePlanVersionTable migration for the ranges.
 */
class PlanVersionModel extends Model
{
    use PublicIdTrait;
    use TransactionTrait;

    /** Columns /staff/plans/version/create writes, besides plan_id */
    public const FORM_FIELDS = ['country_code', 'currency_code', 'billing_cycle', 'price', 'effective_from'];

    /** country_code of the rest of the world */
    public const REST_OF_WORLD = 'ZZ';

    /** billing_cycle */
    public const MONTHLY = 'M';
    public const YEARLY  = 'Y';

    /** A price as the body may give it: up to 2 decimal places */
    public const PRICE_PATTERN = '/^\d{1,17}(\.\d{1,2})?$/';

    /** State of a version at a moment, as the lists send it */
    public const STATE_SCHEDULED = 'S';
    public const STATE_CURRENT   = 'C';
    public const STATE_ENDED     = 'E';

    protected $table            = 'plan_version';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = ['plan_id', ...self::FORM_FIELDS, 'effective_to'];

    // created_at and updated_at are maintained by the database
    protected $useTimestamps = false;

    protected $validationRules = [
        'plan_id'        => 'required|is_natural_no_zero',
        'country_code'   => 'required|regex_match[/^[A-Z]{2}$/]',
        'currency_code'  => 'required|regex_match[/^[A-Z]{3}$/]',
        'billing_cycle'  => 'required|in_list[M,Y]',
        'price'          => 'required|regex_match[/^\d{1,17}\.\d{2}$/]',
        'effective_from' => 'required|valid_date[Y-m-d H:i:s]',
    ];
    protected $skipValidation       = false;
    protected $cleanValidationRules = true;

    /** public_id: CHAR(8), see PublicIdTrait */
    protected int $publicIdLength = 8;
    protected $beforeInsert       = ['fillPublicId'];

    /**
     * The version a business in $countryCode pays for the plan and cycle at $at: its country's own series when there
     * is one in effect, the rest of the world's otherwise
     * @param int    $planId
     * @param string $countryCode
     * @param string $billingCycle
     * @param string $at UTC, Y-m-d H:i:s
     * @return array|null
     */
    public function inEffect(int $planId, string $countryCode, string $billingCycle, string $at): ?array
    {
        return $this->db->table($this->table)
            ->where('plan_id', $planId)
            ->where('billing_cycle', $billingCycle)
            ->whereIn('country_code', [$countryCode, self::REST_OF_WORLD])
            ->where('effective_from <=', $at)
            ->groupStart()->where('effective_to', null)->orWhere('effective_to >', $at)->groupEnd()
            ->orderBy("CASE WHEN country_code = '" . self::REST_OF_WORLD . "' THEN 1 ELSE 0 END", 'ASC', false)
            ->limit(1)
            ->get()
            ->getRowArray();
    }

    /**
     * The row of the series with no end, if any: the one in effect, or the last one scheduled
     * @param int    $planId
     * @param string $countryCode
     * @param string $billingCycle
     * @return array|null
     */
    public function openOfSeries(int $planId, string $countryCode, string $billingCycle): ?array
    {
        return $this->db->table($this->table)
            ->where('plan_id', $planId)
            ->where('country_code', $countryCode)
            ->where('billing_cycle', $billingCycle)
            ->where('effective_to', null)
            ->get()
            ->getRowArray();
    }

    /**
     * The versions, by plan, country, cycle and then newest first, each with its state at $now
     * @param array<string, int|string> $filters plan_id, country_code, billing_cycle
     * @param string                    $now     UTC
     * @return list<array>
     */
    public function listVersions(array $filters, string $now): array
    {
        $builder = $this->db->table($this->table . ' v')
            ->select('v.public_id, p.plan_code, v.country_code, v.currency_code, v.billing_cycle, v.price, v.effective_from, v.effective_to, v.created_at')
            ->join('plan_master p', 'p.id = v.plan_id', 'inner');
        foreach ($filters as $column => $value) {
            $builder->where('v.' . $column, $value);
        }
        $rows = $builder->orderBy('p.plan_tier', 'ASC')
            ->orderBy('v.country_code', 'ASC')
            ->orderBy('v.billing_cycle', 'ASC')
            ->orderBy('v.effective_from', 'DESC')
            ->get()
            ->getResultArray();

        return array_map(static fn (array $row) => ['price' => Money::format($row['price'])] + $row + ['state' => self::stateAt($row, $now)], $rows);
    }

    /**
     * @param array  $row With effective_from and effective_to
     * @param string $at  UTC
     * @return string One of the STATE_ constants
     */
    public static function stateAt(array $row, string $at): string
    {
        if ($row['effective_from'] > $at) {
            return self::STATE_SCHEDULED;
        }

        return $row['effective_to'] === null || $row['effective_to'] > $at ? self::STATE_CURRENT : self::STATE_ENDED;
    }

    /**
     * Add a version to its series: the series' open row ends when the new one starts, in one transaction
     * @param array  $row     Checked columns, with plan_id
     * @param int    $actorId
     * @param string $summary
     * @return int The new id
     * @throws Throwable
     */
    public function schedule(array $row, int $actorId, string $summary): int
    {
        $log = new ActivityLog();

        return $this->withPublicId(fn (string $publicId) => $this->inTransaction(function () use ($row, $actorId, $summary, $log, $publicId) {
            $open = $this->openOfSeries((int) $row['plan_id'], $row['country_code'], $row['billing_cycle']);
            if ($open !== null) {
                $end = ['effective_to' => $row['effective_from']];
                $this->db->table($this->table)->where('id', $open['id'])->update($end);
                $log->updated($actorId, $summary, $this->table, (int) $open['id'], $open, $end);
            }
            $row = ['public_id' => $publicId] + $row;
            $this->db->table($this->table)->insert($row);
            $id = (int) $this->db->insertID();
            $log->inserted($actorId, $summary, $this->table, $id, $row);

            return $id;
        }));
    }

    /**
     * Whether a subscription or an invoice points at the version
     * @param int $id
     * @return bool
     */
    public function isInUse(int $id): bool
    {
        $subscriptions = $this->db->table('subscription_master')
            ->groupStart()->where('plan_version_id', $id)->orWhere('next_plan_version_id', $id)->groupEnd()
            ->countAllResults();

        return $subscriptions > 0 || $this->db->table('subscription_invoice')->where('plan_version_id', $id)->countAllResults() > 0;
    }

    /**
     * Remove a version scheduled for the future, and give the series' previous row its open end back
     * @param array  $stored  The version; the caller has checked that it is open, not started and not in use
     * @param int    $actorId
     * @param string $summary
     * @return void
     * @throws Throwable
     */
    public function cancelScheduled(array $stored, int $actorId, string $summary): void
    {
        $log = new ActivityLog();
        $this->inTransaction(function () use ($stored, $actorId, $summary, $log) {
            $this->db->table($this->table)->where('id', $stored['id'])->delete();
            $log->deleted($actorId, $summary, $this->table, (int) $stored['id'], $stored);

            $previous = $this->db->table($this->table)
                ->where('plan_id', $stored['plan_id'])
                ->where('country_code', $stored['country_code'])
                ->where('billing_cycle', $stored['billing_cycle'])
                ->where('effective_to', $stored['effective_from'])
                ->get()
                ->getRowArray();
            if ($previous !== null) {
                $reopen = ['effective_to' => null];
                $this->db->table($this->table)->where('id', $previous['id'])->update($reopen);
                $log->updated($actorId, $summary, $this->table, (int) $previous['id'], $previous, $reopen);
            }
        });
    }
}
