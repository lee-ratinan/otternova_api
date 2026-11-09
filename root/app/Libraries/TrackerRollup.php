<?php

namespace App\Libraries;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\I18n\Time;
use Config\Tracker;
use Throwable;

/**
 * The daily upkeep of the tracker, run by php spark tracker:rollup:
 * - rebuilds tracker_daily for a UTC day from tracker_visit, tracker_visit_page and tracker_visitor. A visit counts on
 *   the day it started, a page on the day it was first viewed in the visit. Rebuilding replaces the day's rows, so it
 *   is safe to run again, and recent days are rebuilt each run to count visits merged late;
 * - zeroes the last part of the IP addresses of visits older than Config\Tracker::$ipAnonymiseDays;
 * - deletes visits (and their pages) and idle visitors older than Config\Tracker::$visitRetentionDays.
 */
class TrackerRollup
{
    /** dimension => what tracker_visit is grouped by for it */
    private const VISIT_DIMENSIONS = [
        'total'   => "''",
        'country' => "COALESCE(v.country_code, '')",
        'source'  => 'v.source_type',
        'device'  => "COALESCE(v.device_type, '')",
    ];

    private BaseConnection $db;

    public function __construct(private readonly Tracker $config, ?BaseConnection $db = null)
    {
        $this->db = $db ?? db_connect();
    }

    /**
     * Rebuild the tracker_daily rows of one day
     * @param string $day YYYY-MM-DD, UTC
     * @return int The rows written
     * @throws Throwable When it failed; the day's rows are then as they were
     */
    public function rollupDay(string $day): int
    {
        $start = Time::createFromFormat('Y-m-d H:i:s', $day . ' 00:00:00', 'UTC');
        $from  = $start->toDateTimeString();
        $until = $start->addDays(1)->toDateTimeString();

        $rows = [];
        foreach (self::VISIT_DIMENSIONS as $dimension => $column) {
            foreach ($this->visitTotals($column, $from, $until) as $row) {
                $rows[] = ['day' => $day, 'dimension' => $dimension] + $row;
            }
        }
        // a page has no funnel of its own; insertBatch() needs the same fields in every row
        $noFunnel = ['funnel_view_item' => 0, 'funnel_add_to_cart' => 0, 'funnel_checkout_start' => 0,
            'funnel_checkout_confirm' => 0, 'orders' => 0, 'revenue' => 0];
        foreach ($this->pageTotals($from, $until) as $row) {
            $rows[] = ['day' => $day, 'dimension' => 'path'] + $row + $noFunnel;
        }

        $this->db->resetTransStatus()->transException(true)->transBegin();
        try {
            $this->db->table('tracker_daily')->where('day', $day)->delete();
            foreach (array_chunk($rows, 500) as $chunk) {
                $this->db->table('tracker_daily')->insertBatch($chunk);
            }
            $this->db->transCommit();
        } catch (Throwable $e) {
            $this->db->transRollback();
            $this->db->resetTransStatus();

            throw $e;
        } finally {
            $this->db->transException(false);
        }

        return count($rows);
    }

    /**
     * Zero the last part of the IP address of the visits older than Config\Tracker::$ipAnonymiseDays
     * @param Time|null $now For tests
     * @return int The visits changed
     */
    public function anonymiseIps(?Time $now = null): int
    {
        $before = ($now ?? Time::now('UTC'))->subDays(max(0, $this->config->ipAnonymiseDays))->toDateTimeString();
        $count  = 0;
        do {
            $visits = $this->db->table('tracker_visit')->select('id, ip_address')
                ->where('ip_anonymised', 0)->where('started_at <', $before)
                ->orderBy('id')->limit(1000)->get()->getResultArray();
            foreach ($visits as $visit) {
                $this->db->table('tracker_visit')->where('id', $visit['id'])
                    ->update(['ip_address' => self::anonymiseIp($visit['ip_address']), 'ip_anonymised' => 1]);
            }
            $count += count($visits);
        } while (count($visits) === 1000);

        return $count;
    }

    /**
     * Delete the visits, and visitors not seen since, older than Config\Tracker::$visitRetentionDays
     * @param Time|null $now For tests
     * @return array{visits: int, visitors: int}
     */
    public function purge(?Time $now = null): array
    {
        $before = ($now ?? Time::now('UTC'))->subDays(max(1, $this->config->visitRetentionDays))->toDateTimeString();

        // the pages first, as SQLite (the test database) may not cascade
        $old = $this->db->table('tracker_visit')->select('id')->where('started_at <', $before);
        $this->db->table('tracker_visit_page')->whereIn('visit_id', $old)->delete();
        $this->db->table('tracker_visit')->where('started_at <', $before)->delete();
        $visits = $this->db->affectedRows();
        $this->db->table('tracker_visitor')->where('last_seen_at <', $before)->delete();

        return ['visits' => $visits, 'visitors' => $this->db->affectedRows()];
    }

    /**
     * An IP address with its last part zeroed: an IPv4 address to its /24, an IPv6 one to its /48
     * @param string $ip
     * @return string '' when it is no IP address
     */
    public static function anonymiseIp(string $ip): string
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return '';
        }
        $keep = strlen($packed) === 4 ? 3 : 6;

        return (string) inet_ntop(substr($packed, 0, $keep) . str_repeat("\0", strlen($packed) - $keep));
    }

    /**
     * The visits of a day grouped by site, business and a column
     * @param string $column SQL
     * @param string $from
     * @param string $until
     * @return list<array>
     */
    private function visitTotals(string $column, string $from, string $until): array
    {
        $visit   = $this->db->prefixTable('tracker_visit');
        $visitor = $this->db->prefixTable('tracker_visitor');
        $funnel  = static fn (int $stage): string => 'SUM(CASE WHEN v.funnel_stage >= ' . $stage . ' THEN 1 ELSE 0 END)';
        $sql     = 'SELECT v.site, v.business_id, ' . $column . ' AS dimension_value,'
            . ' SUM(v.pageviews) AS pageviews, COUNT(*) AS visits, COUNT(DISTINCT v.visitor_id) AS visitors,'
            . ' COUNT(DISTINCT CASE WHEN tv.first_seen_at >= ? AND tv.first_seen_at < ? THEN v.visitor_id END) AS new_visitors,'
            . ' SUM(v.is_bounce) AS bounces, SUM(v.duration_s) AS visible_s,'
            . ' ' . $funnel(1) . ' AS funnel_view_item, ' . $funnel(2) . ' AS funnel_add_to_cart,'
            . ' ' . $funnel(3) . ' AS funnel_checkout_start, ' . $funnel(4) . ' AS funnel_checkout_confirm,'
            . ' ' . $funnel(4) . ' AS orders, COALESCE(SUM(v.order_value), 0) AS revenue'
            . ' FROM ' . $visit . ' v'
            . ' LEFT JOIN ' . $visitor . ' tv ON tv.site = v.site AND tv.business_id = v.business_id AND tv.visitor_id = v.visitor_id'
            . ' WHERE v.started_at >= ? AND v.started_at < ?'
            . ' GROUP BY v.site, v.business_id, ' . $column;

        return array_map([$this, 'numbers'], $this->db->query($sql, [$from, $until, $from, $until])->getResultArray());
    }

    /**
     * The pages first viewed in their visit on a day, grouped by site, business and path
     * @param string $from
     * @param string $until
     * @return list<array>
     */
    private function pageTotals(string $from, string $until): array
    {
        $page    = $this->db->prefixTable('tracker_visit_page');
        $visit   = $this->db->prefixTable('tracker_visit');
        $visitor = $this->db->prefixTable('tracker_visitor');
        $sql     = 'SELECT v.site, v.business_id, p.path AS dimension_value,'
            . ' SUM(p.views) AS pageviews, COUNT(DISTINCT v.id) AS visits, COUNT(DISTINCT v.visitor_id) AS visitors,'
            . ' COUNT(DISTINCT CASE WHEN tv.first_seen_at >= ? AND tv.first_seen_at < ? THEN v.visitor_id END) AS new_visitors,'
            // a bounce counts on the one page it was
            . ' SUM(CASE WHEN v.is_bounce = 1 AND v.entry_path = p.path THEN 1 ELSE 0 END) AS bounces,'
            . ' SUM(p.visible_s) AS visible_s'
            . ' FROM ' . $page . ' p'
            . ' JOIN ' . $visit . ' v ON v.id = p.visit_id'
            . ' LEFT JOIN ' . $visitor . ' tv ON tv.site = v.site AND tv.business_id = v.business_id AND tv.visitor_id = v.visitor_id'
            . ' WHERE p.first_viewed_at >= ? AND p.first_viewed_at < ?'
            . ' GROUP BY v.site, v.business_id, p.path';

        return array_map([$this, 'numbers'], $this->db->query($sql, [$from, $until, $from, $until])->getResultArray());
    }

    /**
     * A result row with its counts as numbers, as the drivers hand some back as text
     * @param array $row
     * @return array
     */
    private function numbers(array $row): array
    {
        foreach ($row as $field => $value) {
            if ($field === 'revenue') {
                $row[$field] = round((float) $value, 2);
            } elseif (! in_array($field, ['site', 'dimension_value'], true)) {
                $row[$field] = (int) $value;
            }
        }
        $row['dimension_value'] = (string) $row['dimension_value'];

        return $row;
    }
}
