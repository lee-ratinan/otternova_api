<?php

namespace App\Libraries;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;

/**
 * The traffic of the website, the marketplace or one business on it, over a period of local days, for the traffic
 * endpoints of the Admin and Client apps (App\Controllers\Traffic).
 * Day-by-day numbers come from tracker_daily, so they reach back as far as the tracker does. Distinct visitors over
 * the whole period, the referring sites and the list of visits come from tracker_visit, kept for
 * Config\Tracker::$visitRetentionDays.
 * A scope is ['site' => www|mart, 'business_id' => int|null, 'timezone' => string]: business_id 0 is the website or
 * the marketplace's own pages, a business's id its pages, null the whole marketplace.
 */
class TrafficReport
{
    public const BREAKDOWNS = ['path', 'country', 'source', 'device', 'referrer'];

    /** The tracker_daily counts summed over days */
    private const COUNTS = ['pageviews', 'visits', 'new_visitors', 'bounces', 'visible_s', 'funnel_view_item',
        'funnel_add_to_cart', 'funnel_checkout_start', 'funnel_checkout_confirm', 'orders'];

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? db_connect();
    }

    /**
     * The totals of a period and of the period of the same length before it, day by day, and the shopping funnel
     * @param array  $scope
     * @param string $from YYYY-MM-DD
     * @param string $to   YYYY-MM-DD
     * @return array
     */
    public function overview(array $scope, string $from, string $to): array
    {
        $days      = $this->dayCount($from, $to);
        $prevTo    = date('Y-m-d', strtotime($from . ' -1 day'));
        $prevFrom  = date('Y-m-d', strtotime($from . ' -' . $days . ' days'));
        $series    = $this->series($scope, $from, $to);
        $previous  = $this->series($scope, $prevFrom, $prevTo);
        $totals    = $this->sum($series);
        $prevTotal = $this->sum($previous);

        $report = [
            'from'     => $from,
            'to'       => $to,
            'timezone' => $scope['timezone'],
            'totals'   => $this->rates($totals) + ['visitors' => $this->distinctVisitors($scope, $from, $to)],
            'previous' => ['from' => $prevFrom, 'to' => $prevTo] + $this->rates($prevTotal)
                + ['visitors' => $this->distinctVisitors($scope, $prevFrom, $prevTo)],
            'series'   => array_map(fn (array $day): array => [
                'day'          => $day['day'],
                'visits'       => $day['visits'],
                'pageviews'    => $day['pageviews'],
                'visitors'     => $day['visitors'],
                'new_visitors' => $day['new_visitors'],
                'orders'       => $day['orders'],
                'revenue'      => $day['revenue'],
            ], $series),
        ];
        if ($scope['site'] === 'mart') {
            $report['funnel'] = $this->funnel($totals);
            // what the shop made of the visits, in both periods, so the change can be shown
            $report['totals'] += $this->sales($totals);
            $report['previous'] += $this->sales($prevTotal);
        }

        return $report;
    }

    /**
     * The period's traffic by page, country, source, device or referring site, most visits first
     * @param array  $scope
     * @param string $from
     * @param string $to
     * @param string $dimension One of BREAKDOWNS
     * @param int    $limit
     * @return list<array>
     */
    public function breakdown(array $scope, string $from, string $to, string $dimension, int $limit): array
    {
        if ($dimension === 'referrer') {
            return $this->referrers($scope, $from, $to, $limit);
        }

        $builder = $this->scoped($this->db->table('tracker_daily'), $scope, '')
            ->select('dimension_value AS value')
            ->selectSum('visits')->selectSum('pageviews')->selectSum('bounces')->selectSum('visible_s')
            ->selectSum('funnel_checkout_confirm', 'orders')->selectSum('revenue')
            ->where('dimension', $dimension)
            ->where('day >=', $from)->where('day <=', $to)
            ->groupBy('dimension_value')
            ->orderBy($dimension === 'path' ? 'pageviews' : 'visits', 'DESC')->orderBy('dimension_value')
            ->limit($limit);

        return array_map(function (array $row) use ($dimension): array {
            $visits    = (int) $row['visits'];
            $pageviews = (int) $row['pageviews'];
            $visible   = (int) $row['visible_s'];
            $out       = [
                'value'       => (string) $row['value'],
                'visits'      => $visits,
                'pageviews'   => $pageviews,
                'bounce_rate' => $this->ratio((int) $row['bounces'], $visits),
            ];
            if ($dimension === 'path') {
                // a page's visible time over its views, rather than over whole visits
                $out['avg_time_on_page_s'] = $pageviews === 0 ? 0 : (int) round($visible / $pageviews);
            } else {
                $out['avg_visit_s'] = $visits === 0 ? 0 : (int) round($visible / $visits);
                $out['orders']      = (int) $row['orders'];
                $out['revenue']     = round((float) $row['revenue'], 2);
            }

            return $out;
        }, $builder->get()->getResultArray());
    }

    /**
     * One page of the period's visits, newest first, each with its pages
     * @param array  $scope
     * @param string $from
     * @param string $to
     * @param int    $start
     * @param int    $length
     * @param bool   $forStaff Adds the IP address and, for the whole marketplace, the business's slug
     * @return array{records_total: int, rows: list<array>}
     */
    public function visits(array $scope, string $from, string $to, int $start, int $length, bool $forStaff): array
    {
        [$utcFrom, $utcUntil] = TrackerRollup::utcWindow($from, $to, $scope['timezone']);
        $builder = $this->scoped($this->db->table('tracker_visit v'), $scope, 'v.')
            ->where('v.started_at >=', $utcFrom)->where('v.started_at <', $utcUntil);
        $total = $builder->countAllResults(false);

        $columns = ['v.id', 'v.business_id', 'v.started_at', 'v.ended_at', 'v.duration_s', 'v.pageviews', 'v.events',
            'v.is_bounce', 'v.entry_path', 'v.exit_path', 'v.referrer_host', 'v.source_type', 'v.utm_source',
            'v.utm_medium', 'v.utm_campaign', 'v.country_code', 'v.subdivision_code', 'v.city', 'v.device_type',
            'v.browser', 'v.os', 'v.language', 'v.funnel_stage', 'v.order_value', 'v.order_currency',
            'tv.first_seen_at', 'tv.visits AS visitor_visits'];
        if ($forStaff) {
            $columns[] = 'v.ip_address';
            $columns[] = 'b.business_slug';
            $builder->join('business_master b', 'b.id = v.business_id', 'left');
        }
        $rows = $builder->select(implode(', ', $columns))
            ->join('tracker_visitor tv', 'tv.site = v.site AND tv.business_id = v.business_id AND tv.visitor_id = v.visitor_id', 'left')
            ->orderBy('v.started_at', 'DESC')->orderBy('v.id', 'DESC')
            ->limit($length, $start)->get()->getResultArray();

        $pages = [];
        if ($rows !== []) {
            foreach ($this->db->table('tracker_visit_page')->select('visit_id, path, views, visible_s')
                ->whereIn('visit_id', array_column($rows, 'id'))->orderBy('first_viewed_at')->orderBy('id')
                ->get()->getResultArray() as $page) {
                $pages[$page['visit_id']][] = ['path' => $page['path'], 'views' => (int) $page['views'], 'visible_s' => (int) $page['visible_s']];
            }
        }

        return ['records_total' => $total, 'rows' => array_map(static function (array $row) use ($pages, $forStaff): array {
            $visit = [
                'started_at'       => $row['started_at'],
                'ended_at'         => $row['ended_at'],
                'duration_s'       => (int) $row['duration_s'],
                'pageviews'        => (int) $row['pageviews'],
                'events'           => (int) $row['events'],
                'is_bounce'        => (bool) $row['is_bounce'],
                // the visitor had come before this visit
                'is_returning'     => $row['first_seen_at'] !== null && $row['first_seen_at'] < $row['started_at'],
                'entry_path'       => $row['entry_path'],
                'exit_path'        => $row['exit_path'],
                'referrer_host'    => $row['referrer_host'],
                'source_type'      => $row['source_type'],
                'utm_source'       => $row['utm_source'],
                'utm_medium'       => $row['utm_medium'],
                'utm_campaign'     => $row['utm_campaign'],
                'country_code'     => $row['country_code'],
                'subdivision_code' => $row['subdivision_code'],
                'city'             => $row['city'],
                'device_type'      => $row['device_type'],
                'browser'          => $row['browser'],
                'os'               => $row['os'],
                'language'         => $row['language'],
                'funnel_stage'     => (int) $row['funnel_stage'],
                'order_value'      => $row['order_value'] === null ? null : round((float) $row['order_value'], 2),
                'order_currency'   => $row['order_currency'],
                'pages'            => $pages[$row['id']] ?? [],
            ];
            if ($forStaff) {
                $visit['ip_address']    = $row['ip_address'];
                $visit['business_slug'] = $row['business_slug'];
            }

            return $visit;
        }, $rows)];
    }

    /**
     * The marketplace's businesses with the most visits in the period, for the Admin app
     * @param string $from
     * @param string $to
     * @param int    $limit
     * @return list<array>
     */
    public function topBusinesses(string $from, string $to, int $limit): array
    {
        $rows = $this->db->table('tracker_daily d')
            ->select('b.business_slug, b.business_name, b.currency_code')
            ->selectSum('d.visits', 'visits')->selectSum('d.pageviews', 'pageviews')
            ->selectSum('d.funnel_checkout_confirm', 'orders')->selectSum('d.revenue', 'revenue')
            ->join('business_master b', 'b.id = d.business_id')
            ->where('d.site', 'mart')->where('d.dimension', 'total')->where('d.business_id >', 0)
            ->where('d.day >=', $from)->where('d.day <=', $to)
            ->groupBy('b.id, b.business_slug, b.business_name, b.currency_code')
            ->orderBy('visits', 'DESC')->orderBy('b.business_slug')
            ->limit($limit)->get()->getResultArray();

        return array_map(fn (array $row): array => [
            'business_slug'   => $row['business_slug'],
            'business_name'   => $row['business_name'],
            'currency_code'   => $row['currency_code'],
            'visits'          => (int) $row['visits'],
            'pageviews'       => (int) $row['pageviews'],
            'orders'          => (int) $row['orders'],
            'revenue'         => round((float) $row['revenue'], 2),
            'conversion_rate' => $this->ratio((int) $row['orders'], (int) $row['visits']),
        ], $rows);
    }

    /**
     * Each day of the period, with zeros for a day without visits
     * @return list<array>
     */
    private function series(array $scope, string $from, string $to): array
    {
        $builder = $this->scoped($this->db->table('tracker_daily'), $scope, '')->select('day');
        foreach (self::COUNTS as $count) {
            $builder->selectSum($count);
        }
        // a sum of daily visitors counts a visitor once a day, and once per business for the whole marketplace
        $builder->selectSum('visitors')->selectSum('revenue');
        $found = [];
        foreach ($builder->where('dimension', 'total')->where('day >=', $from)->where('day <=', $to)
            ->groupBy('day')->get()->getResultArray() as $row) {
            $found[$row['day']] = $row;
        }

        $series = [];
        for ($day = $from; $day <= $to; $day = date('Y-m-d', strtotime($day . ' +1 day'))) {
            $row  = $found[$day] ?? [];
            $item = ['day' => $day];
            foreach ([...self::COUNTS, 'visitors'] as $count) {
                $item[$count] = (int) ($row[$count] ?? 0);
            }
            $item['revenue'] = round((float) ($row['revenue'] ?? 0), 2);
            $series[]        = $item;
        }

        return $series;
    }

    /**
     * @param list<array> $series
     * @return array The counts and revenue added up
     */
    private function sum(array $series): array
    {
        $total = array_fill_keys([...self::COUNTS, 'revenue'], 0);
        foreach ($series as $day) {
            foreach (array_keys($total) as $field) {
                $total[$field] += $day[$field];
            }
        }
        $total['revenue'] = round($total['revenue'], 2);

        return $total;
    }

    /**
     * @param array $total From sum()
     * @return array The headline numbers of a period
     */
    private function rates(array $total): array
    {
        return [
            'visits'          => $total['visits'],
            'pageviews'       => $total['pageviews'],
            'new_visitors'    => $total['new_visitors'],
            'bounce_rate'     => $this->ratio($total['bounces'], $total['visits']),
            'avg_visit_s'     => $total['visits'] === 0 ? 0 : (int) round($total['visible_s'] / $total['visits']),
            'pages_per_visit' => $total['visits'] === 0 ? 0.0 : round($total['pageviews'] / $total['visits'], 2),
        ];
    }

    /**
     * @param array $total From sum()
     * @return array orders, revenue and conversion_rate (the share of visits that ended in an order) of a period
     */
    private function sales(array $total): array
    {
        return [
            'orders'          => $total['orders'],
            'revenue'         => $total['revenue'],
            'conversion_rate' => $this->ratio($total['funnel_checkout_confirm'], $total['visits']),
        ];
    }

    /**
     * The shopping funnel of a period: how many visits reached each step, how many of the step before went on to
     * it, and how many visits ended in an order
     * @param array $total From sum()
     * @return array
     */
    private function funnel(array $total): array
    {
        $steps = [
            'visits'           => $total['visits'],
            'view_item'        => $total['funnel_view_item'],
            'add_to_cart'      => $total['funnel_add_to_cart'],
            'checkout_start'   => $total['funnel_checkout_start'],
            'checkout_confirm' => $total['funnel_checkout_confirm'],
        ];
        $out      = [];
        $previous = null;
        foreach ($steps as $step => $count) {
            $out['steps'][] = [
                'step'               => $step,
                'visits'             => $count,
                // of the visits that reached the step before, the share that reached this one; 1 minus it drops off
                'rate_from_previous' => $previous === null ? null : $this->ratio($count, $previous),
            ];
            $previous = $count;
        }
        $out['orders']          = $total['orders'];
        $out['revenue']         = $total['revenue'];
        $out['conversion_rate'] = $this->ratio($total['funnel_checkout_confirm'], $total['visits']);

        return $out;
    }

    /**
     * Distinct visitors of a period: a visitor of two businesses counts twice for the whole marketplace, as the
     * tracker keeps a visitor per business
     */
    private function distinctVisitors(array $scope, string $from, string $to): int
    {
        [$utcFrom, $utcUntil] = TrackerRollup::utcWindow($from, $to, $scope['timezone']);
        $inner = $this->scoped($this->db->table('tracker_visit'), $scope, '')
            ->select('business_id, visitor_id')->distinct()
            ->where('started_at >=', $utcFrom)->where('started_at <', $utcUntil)
            ->getCompiledSelect();

        return (int) ($this->db->query('SELECT COUNT(*) AS n FROM (' . $inner . ') t')->getRow()->n ?? 0);
    }

    /**
     * The sites visits of the period came from, by host
     * @return list<array>
     */
    private function referrers(array $scope, string $from, string $to, int $limit): array
    {
        [$utcFrom, $utcUntil] = TrackerRollup::utcWindow($from, $to, $scope['timezone']);
        $rows = $this->scoped($this->db->table('tracker_visit'), $scope, '')
            ->select('referrer_host AS value, COUNT(*) AS visits')
            ->selectSum('pageviews')->selectSum('is_bounce', 'bounces')->selectSum('duration_s', 'visible_s')
            ->selectSum('order_value', 'revenue')
            ->select('SUM(CASE WHEN funnel_stage >= 4 THEN 1 ELSE 0 END) AS orders', false)
            ->where('referrer_host IS NOT NULL')
            ->where('started_at >=', $utcFrom)->where('started_at <', $utcUntil)
            ->groupBy('referrer_host')
            ->orderBy('visits', 'DESC')->orderBy('referrer_host')
            ->limit($limit)->get()->getResultArray();

        return array_map(fn (array $row): array => [
            'value'       => $row['value'],
            'visits'      => (int) $row['visits'],
            'pageviews'   => (int) $row['pageviews'],
            'bounce_rate' => $this->ratio((int) $row['bounces'], (int) $row['visits']),
            'avg_visit_s' => (int) $row['visits'] === 0 ? 0 : (int) round((int) $row['visible_s'] / (int) $row['visits']),
            'orders'      => (int) $row['orders'],
            'revenue'     => round((float) $row['revenue'], 2),
        ], $rows);
    }

    /**
     * Limit a query to a scope's site and business
     * @param BaseBuilder $builder
     * @param array       $scope
     * @param string      $alias   The table's alias with its dot, or ''
     * @return BaseBuilder
     */
    private function scoped(BaseBuilder $builder, array $scope, string $alias): BaseBuilder
    {
        $builder->where($alias . 'site', $scope['site']);
        if ($scope['business_id'] !== null) {
            $builder->where($alias . 'business_id', $scope['business_id']);
        }

        return $builder;
    }

    private function ratio(int $part, int $whole): float
    {
        return $whole === 0 ? 0.0 : round($part / $whole, 4);
    }

    private function dayCount(string $from, string $to): int
    {
        return (int) round((strtotime($to . ' 12:00:00 UTC') - strtotime($from . ' 12:00:00 UTC')) / DAY) + 1;
    }
}
