<?php

namespace App\Libraries;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\I18n\Time;

/**
 * The figures of the Executive Office's dashboard in the Admin app, read in one go: businesses and paid businesses
 * with their growth over the last 30 days, monthly recurring revenue by currency, the plan mix and the subscriptions'
 * movements, the newest businesses, the inbox, what waits for Finance and the ledger's accounts (from
 * FinanceDashboard), and the latest activity. Every time is UTC. Amounts go out as strings with 2 decimals.
 * The website's and the marketplace's visits are not here: the dashboard loads them from /staff/traffic/overview.
 */
class ExecutiveDashboard
{
    private const DATETIME = 'Y-m-d H:i:s';

    /** The period "new" and "cancelled" are counted over */
    private const PERIOD_DAYS = 30;

    private const NEW_BUSINESSES = 5;
    private const ACTIVITIES     = 8;

    /** The language the business types are named in */
    private const LANGUAGE = 'en-US';

    private string $now;
    private string $periodStart;

    public function __construct(private readonly BaseConnection $db)
    {
        $now               = Time::now('UTC');
        $this->now         = $now->format(self::DATETIME);
        $this->periodStart = $now->subDays(self::PERIOD_DAYS)->format(self::DATETIME);
    }

    /**
     * Every figure of the dashboard
     * @return array
     */
    public function summary(): array
    {
        $finance = new FinanceDashboard($this->db);

        return [
            'as_of'          => $this->now,
            'period_start'   => $this->periodStart,
            'period_days'    => self::PERIOD_DAYS,
            'businesses'     => $this->businesses(),
            'mrr'            => $this->mrr(),
            'plans'          => $this->plans(),
            'movements'      => $this->movements(),
            'new_businesses' => $this->newBusinesses(),
            'inbox'          => $this->inbox(),
            'finance_queue'  => $finance->queue(),
            'ledger'         => $finance->ledger(),
            'activities'     => $this->activities(),
        ];
    }

    /**
     * Every business and the paid ones (an active subscription), each with how many joined in the period
     * @return array{total: int, new: int, paid: int, new_paid: int}
     */
    private function businesses(): array
    {
        return [
            'total'    => $this->db->table('business_master')->countAllResults(),
            'new'      => $this->db->table('business_master')->where('created_at >=', $this->periodStart)->countAllResults(),
            'paid'     => $this->db->table('subscription_master')->where('subscription_status', 'A')->countAllResults(),
            'new_paid' => $this->db->table('subscription_master')->where('subscription_status', 'A')
                ->where('activated_at >=', $this->periodStart)->countAllResults(),
        ];
    }

    /**
     * Monthly recurring revenue by currency: the list price of every active subscription's plan, a yearly one
     * counted at a twelfth. Discounts and withholding tax are not taken off. The currency with the most subscriptions comes first.
     * @return list<array{currency_code: string, mrr: string, subscriptions: int}>
     */
    private function mrr(): array
    {
        $rows = $this->db->table('subscription_master s')
            ->select('v.currency_code')
            ->select("SUM(CASE WHEN v.billing_cycle = 'Y' THEN v.price / 12 ELSE v.price END) AS mrr", false)
            ->select('COUNT(*) AS subscriptions', false)
            ->join('plan_version v', 'v.id = s.plan_version_id', 'inner')
            ->where('s.subscription_status', 'A')
            ->groupBy('v.currency_code')->orderBy('subscriptions', 'DESC')->orderBy('v.currency_code', 'ASC')
            ->get()->getResultArray();

        return array_map(static fn (array $row): array => [
            'currency_code' => $row['currency_code'],
            'mrr'           => number_format(round((float) $row['mrr'], 2), 2, '.', ''),
            'subscriptions' => (int) $row['subscriptions'],
        ], $rows);
    }

    /**
     * How many businesses are on each plan, lowest tier first: an active subscription's plan, and every other
     * business on the free plan (tier 0)
     * @return list<array{plan_code: string, businesses: int}>
     */
    private function plans(): array
    {
        $paid = $this->db->table('subscription_master s')
            ->select('p.id, COUNT(*) AS businesses')
            ->join('plan_version v', 'v.id = s.plan_version_id', 'inner')
            ->join('plan_master p', 'p.id = v.plan_id', 'inner')
            ->where('s.subscription_status', 'A')
            ->groupBy('p.id')->get()->getResultArray();
        $counts = array_column($paid, 'businesses', 'id');
        $free   = $this->db->table('business_master')->countAllResults() - array_sum($counts);

        $plans = [];
        foreach ($this->db->table('plan_master')->select('id, plan_code, plan_tier')->orderBy('plan_tier', 'ASC')->get()->getResultArray() as $plan) {
            $count   = (int) ($counts[$plan['id']] ?? 0) + ((int) $plan['plan_tier'] === 0 ? $free : 0);
            $plans[] = ['plan_code' => $plan['plan_code'], 'businesses' => $count];
        }

        return $plans;
    }

    /**
     * The subscriptions' movements: cancelled in the period, suspended now, and active ones with a plan change set
     * for their next period
     * @return array{cancelled: int, suspended: int, plan_changes: int}
     */
    private function movements(): array
    {
        return [
            'cancelled'    => $this->db->table('subscription_master')->where('subscription_status', 'C')
                ->where('cancelled_at >=', $this->periodStart)->countAllResults(),
            'suspended'    => $this->db->table('subscription_master')->where('subscription_status', 'S')->countAllResults(),
            'plan_changes' => $this->db->table('subscription_master')->where('subscription_status', 'A')
                ->where('next_plan_version_id IS NOT NULL', null, false)->countAllResults(),
        ];
    }

    /**
     * The businesses that joined last, with their type and plan
     * @return list<array{business_slug: string, business_name: string, country_code: string, type_name: string, plan_code: string, created_at: string}>
     */
    private function newBusinesses(): array
    {
        $rows = $this->db->table('business_master b')
            ->select('b.business_slug, b.business_name, b.country_code, b.created_at, bt.type_code, tt.type_name, p.plan_code')
            ->join('business_type bt', 'bt.id = b.business_type_id', 'inner')
            ->join('business_type_translation tt', 'tt.business_type_id = bt.id AND tt.language_code = ' . $this->db->escape(self::LANGUAGE), 'left')
            ->join('subscription_master s', "s.business_id = b.id AND s.subscription_status = 'A'", 'left')
            ->join('plan_version v', 'v.id = s.plan_version_id', 'left')
            ->join('plan_master p', 'p.id = v.plan_id', 'left')
            ->orderBy('b.created_at', 'DESC')->orderBy('b.id', 'DESC')
            ->limit(self::NEW_BUSINESSES)->get()->getResultArray();
        $free = $this->db->table('plan_master')->select('plan_code')->where('plan_tier', 0)->get()->getRowArray()['plan_code'] ?? 'free';

        return array_map(static fn (array $row): array => [
            'business_slug' => $row['business_slug'],
            'business_name' => $row['business_name'],
            'country_code'  => $row['country_code'],
            'type_name'     => $row['type_name'] ?? $row['type_code'],
            'plan_code'     => $row['plan_code'] ?? $free,
            'created_at'    => $row['created_at'],
        ], $rows);
    }

    /**
     * The contact form's messages not handled yet: new (unread) and read
     * @return array{new: int, read: int}
     */
    private function inbox(): array
    {
        return [
            'new'  => $this->db->table('contact_message')->where('status', 'N')->countAllResults(),
            'read' => $this->db->table('contact_message')->where('status', 'R')->countAllResults(),
        ];
    }

    /**
     * The latest entries of the activity log, with who made them (null: the system or a customer)
     * @return list<array{id: int, activity_summary: string, table_involved: string|null, table_id_updated: int|null, actor_name: string|null, created_at: string}>
     */
    private function activities(): array
    {
        $rows = $this->db->table('log_activity l')
            ->select('l.id, l.activity_summary, l.table_involved, l.table_id_updated, l.created_at, a.full_name AS actor_name')
            ->join('auth_master a', 'a.id = l.changed_by_acc', 'left')
            ->orderBy('l.id', 'DESC')
            ->limit(self::ACTIVITIES)->get()->getResultArray();

        return array_map(static fn (array $row): array => [
            'id'               => (int) $row['id'],
            'activity_summary' => $row['activity_summary'],
            'table_involved'   => $row['table_involved'],
            'table_id_updated' => $row['table_id_updated'] === null ? null : (int) $row['table_id_updated'],
            'actor_name'       => $row['actor_name'],
            'created_at'       => $row['created_at'],
        ], $rows);
    }
}
