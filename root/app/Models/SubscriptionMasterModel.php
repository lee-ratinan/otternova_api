<?php

namespace App\Models;

use App\Libraries\Money;
use CodeIgniter\Model;

/**
 * Model for the subscription_master table: a business on a paid plan. Its writes go through
 * App\Libraries\SubscriptionBilling, which keeps the status, the periods and the invoices in step.
 */
class SubscriptionMasterModel extends Model
{
    use PublicIdTrait;

    /** subscription_status */
    public const STATUS_PENDING   = 'P';
    public const STATUS_ACTIVE    = 'A';
    public const STATUS_SUSPENDED = 'S';
    public const STATUS_CANCELLED = 'C';
    public const STATUS_EXPIRED   = 'X';

    /** The statuses of a live subscription, at most one per business */
    public const LIVE_STATUSES = [self::STATUS_PENDING, self::STATUS_ACTIVE, self::STATUS_SUSPENDED];

    /** billing_mode */
    public const BILLING_AUTOMATIC = 'A';
    public const BILLING_MANUAL    = 'M';

    protected $table            = 'subscription_master';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [];

    // created_at and updated_at are maintained by the database
    protected $useTimestamps = false;

    /** public_id: CHAR(12), see PublicIdTrait */
    protected int $publicIdLength = 12;
    protected $beforeInsert       = ['fillPublicId'];

    /**
     * The business's live subscription (pending, active or suspended), as stored
     * @param int $businessId
     * @return array|null
     */
    public function liveOfBusiness(int $businessId): ?array
    {
        return $this->db->table($this->table)
            ->where('business_id', $businessId)
            ->whereIn('subscription_status', self::LIVE_STATUSES)
            ->get()
            ->getRowArray();
    }

    /**
     * One subscription as the API sends it
     * @param int $id
     * @return array|null
     */
    public function findPayload(int $id): ?array
    {
        $row = $this->joinedBuilder()->where('s.id', $id)->get()->getRowArray();

        return $row === null ? null : self::toPayload($row);
    }

    /**
     * One page of subscriptions for the staff, newest first
     * @param int                   $start
     * @param int                   $length
     * @param string                $search  Matched in the business's name and slug
     * @param array<string, string> $filters subscription_status, plan_code
     * @return array{records_total: int, records_filtered: int, rows: list<array>}
     */
    public function listSubscriptions(int $start, int $length, string $search, array $filters): array
    {
        $total   = $this->db->table($this->table)->countAllResults();
        $builder = $this->joinedBuilder();
        if (isset($filters['subscription_status'])) {
            $builder->where('s.subscription_status', $filters['subscription_status']);
        }
        if (isset($filters['plan_code'])) {
            $builder->where('p.plan_code', $filters['plan_code']);
        }
        if ($search !== '') {
            $char = $this->db->likeEscapeChar;
            $term = str_replace([$char, '%', '_'], [$char . $char, $char . '%', $char . '_'], $search);
            $builder->groupStart()
                ->like('b.business_name', $term, 'both', null, true)
                ->orLike('b.business_slug', $term, 'both', null, true)
                ->groupEnd();
        }
        $filtered = $builder->countAllResults(false);
        $rows     = $builder->orderBy('s.id', 'DESC')->limit($length, $start)->get()->getResultArray();

        return ['records_total' => $total, 'records_filtered' => $filtered, 'rows' => array_map(self::toPayload(...), $rows)];
    }

    /**
     * @param array $row
     * @return array
     */
    private static function toPayload(array $row): array
    {
        $row['price']              = Money::format($row['price']);
        $row['billing_anchor_day'] = $row['billing_anchor_day'] === null ? null : (int) $row['billing_anchor_day'];

        return $row;
    }

    /**
     * @return \CodeIgniter\Database\BaseBuilder
     */
    private function joinedBuilder()
    {
        return $this->db->table($this->table . ' s')
            ->select('s.public_id, b.business_slug, b.business_name, b.country_code AS business_country_code')
            ->select('p.plan_code, v.billing_cycle, v.currency_code, v.price')
            ->select('np.plan_code AS next_plan_code, nv.billing_cycle AS next_billing_cycle')
            ->select('s.subscription_status, s.billing_mode, s.billing_anchor_day, s.activated_at, s.current_period_start, s.current_period_end')
            ->select('s.next_billing_at, s.cancel_at_period_end, s.cancel_requested_at, s.suspended_at, s.cancelled_at, s.ended_at')
            ->select('s.created_at, s.updated_at')
            ->join('business_master b', 'b.id = s.business_id', 'inner')
            ->join('plan_version v', 'v.id = s.plan_version_id', 'inner')
            ->join('plan_master p', 'p.id = v.plan_id', 'inner')
            ->join('plan_version nv', 'nv.id = s.next_plan_version_id', 'left')
            ->join('plan_master np', 'np.id = nv.plan_id', 'left');
    }
}
