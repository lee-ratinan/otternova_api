<?php

namespace App\Models;

use CodeIgniter\Model;
use DateTimeImmutable;
use DateTimeZone;

/**
 * What a business's people earn in a pay period, for its owners: read only, worked out from what is stored each time.
 *
 * A pay period is of one pay cycle (business_client_salary.salary_period): weekly (Monday to Sunday), semi-monthly
 * (1st to 15th, 16th to the month's end) or monthly. A person is in the cycle of their salary rows that overlap the
 * period; one with none (commission only) is in the cycle of the business's commission_period.
 *
 * Salary: each salary row of the cycle that overlaps the period pays its amount for the days of the period it covers,
 * and that the person worked (from start_date to termination_date), out of the period's days: so someone who joins
 * or leaves inside the period, or gets a raise, is paid pro rata. A person with no salary row gets no salary.
 *
 * Commission (only while the business's commission_status is A): the service lines (appointments and group
 * sessions) of orders not cancelled earn it when they are performed. A line is shared out evenly over the scheduled
 * slots of its session, and each slot's share evenly over the people who work that slot; a slot counts in the period
 * it starts in, on the business's clock. On each share the person earns their current rate for the service
 * (business_service_client_link): a percentage of the line after its discount and without tax, or a fixed amount
 * times the line's quantity. Someone whose share of sales in the period is below their threshold
 * (business_client_link.commission_threshold, else the business's) earns no commission for it.
 * Everything is counted in cents.
 */
class PayrollModel extends Model
{
    protected $table      = 'business_client_link';
    protected $primaryKey = 'id';
    protected $returnType = 'array';

    public const CYCLES = [BusinessClientSalaryModel::PERIOD_WEEKLY, BusinessClientSalaryModel::PERIOD_SEMI_MONTHLY, BusinessClientSalaryModel::PERIOD_MONTHLY];

    private const DB_FORMAT    = 'Y-m-d H:i:s';
    private const LOCAL_FORMAT = 'Y-m-d\TH:i';

    /**
     * The pay period of a cycle that holds a day
     * @param string            $cycle W, S or M
     * @param DateTimeImmutable $day
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable} its first and last days
     */
    public static function periodOf(string $cycle, DateTimeImmutable $day): array
    {
        return match ($cycle) {
            'W'     => [$day->modify('-' . ((int) $day->format('N') - 1) . ' days'), $day->modify('+' . (7 - (int) $day->format('N')) . ' days')],
            'S'     => (int) $day->format('j') <= 15
                ? [$day->modify('first day of this month'), $day->setDate((int) $day->format('Y'), (int) $day->format('n'), 15)]
                : [$day->setDate((int) $day->format('Y'), (int) $day->format('n'), 16), $day->modify('last day of this month')],
            default => [$day->modify('first day of this month'), $day->modify('last day of this month')],
        };
    }

    /**
     * Everyone of the cycle who earns anything in the period, by name
     * @param int          $businessId
     * @param string       $cycle    W, S or M
     * @param string       $from     'Y-m-d', the period's first day
     * @param string       $to       'Y-m-d', its last day
     * @param DateTimeZone $timezone the business's
     * @param string|null  $uid      only this person (for their breakdown)
     * @return array{currency_code: string, commission: array, people: list<array>}
     *   commission: {status, period, threshold};
     *   people: [{ uid, name, full_name, avatar_path, client_role, client_status, start_date, termination_date,
     *   salary, prorated, commission, sales, threshold, below_threshold, total, salary_lines, commission_lines }]
     *   salary_lines: [{salary_amount, from, to, days, period_days, amount}];
     *   commission_lines: [{order_public_id, order_number, kind, service_name, variant_label, customer_name,
     *   local_starts_at, slot_number, slot_count, staff_count, line_amount, quantity, share, commission_unit,
     *   commission_value, commission}], each in cents as a string "299.00"
     */
    public function report(int $businessId, string $cycle, string $from, string $to, DateTimeZone $timezone, ?string $uid = null): array
    {
        $business = $this->db->table('business_master')->select('currency_code, commission_status, commission_period, commission_threshold')
            ->where('id', $businessId)->get()->getRowArray();
        $first      = new DateTimeImmutable($from);
        $last       = new DateTimeImmutable($to);
        $periodDays = (int) $first->diff($last)->days + 1;

        // everyone who worked some of the period
        $builder = $this->db->table('business_client_link l')
            ->select('l.client_id, l.client_role, l.client_status, l.start_date, l.termination_date, l.commission_threshold, a.uid, a.full_name, a.avatar_path, c.display_name')
            ->join('client_master c', 'c.id = l.client_id')->join('auth_master a', 'a.id = c.auth_id')
            ->where('l.business_id', $businessId)->where('l.start_date <=', $to)
            ->groupStart()->where('l.termination_date', null)->orWhere('l.termination_date >=', $from)->groupEnd();
        if ($uid !== null) {
            $builder->where('a.uid', $uid);
        }
        $links = [];
        foreach ($builder->get()->getResultArray() as $row) {
            $links[(int) $row['client_id']] = $row;
        }

        $salaries = [];
        if ($links !== []) {
            foreach ($this->db->table('business_client_salary')->where('business_id', $businessId)->whereIn('client_id', array_keys($links))
                ->where('effective_date <=', $to)->groupStart()->where('end_date', null)->orWhere('end_date >=', $from)->groupEnd()
                ->orderBy('effective_date')->get()->getResultArray() as $row) {
                $salaries[(int) $row['client_id']][] = $row;
            }
        }
        $earned = $business['commission_status'] === 'A' && $links !== []
            ? $this->commissionLines($businessId, array_keys($links), $from, $to, $timezone)
            : [];

        $people = [];
        foreach ($links as $clientId => $link) {
            $rows = $salaries[$clientId] ?? [];
            // the person's cycle: their salary's, else the business's commission period
            $own = $rows === [] ? (string) $business['commission_period'] : (string) end($rows)['salary_period'];
            if ($own !== $cycle) {
                continue;
            }
            // the days of the period they worked
            $worked = [max($from, (string) $link['start_date']), min($to, (string) ($link['termination_date'] ?? $to))];

            $salary   = 0;
            $salaryLines = [];
            foreach ($rows as $row) {
                if ($row['salary_period'] !== $cycle) {
                    continue;
                }
                $start = max($worked[0], (string) $row['effective_date']);
                $end   = min($worked[1], (string) ($row['end_date'] ?? $worked[1]));
                if ($end < $start) {
                    continue;
                }
                $days   = (int) (new DateTimeImmutable($start))->diff(new DateTimeImmutable($end))->days + 1;
                $amount = self::cents($row['salary_amount']);
                $paid   = (int) round($amount * $days / $periodDays);
                $salary += $paid;
                $salaryLines[] = ['salary_amount' => self::money($amount), 'from' => $start, 'to' => $end, 'days' => $days, 'period_days' => $periodDays,
                    'amount' => self::money($paid)];
            }

            $lines     = $earned[$clientId] ?? [];
            $sales     = array_sum(array_column($lines, 'share'));
            $threshold = self::cents($link['commission_threshold'] ?? $business['commission_threshold']);
            $below     = $threshold > 0 && $sales < $threshold;
            $commission = $below ? 0 : array_sum(array_column($lines, 'commission'));
            if ($rows === [] && $lines === []) {
                // no salary and nothing performed: nothing to pay
                continue;
            }
            $people[] = [
                'uid'              => $link['uid'],
                'name'             => trim((string) $link['display_name']) !== '' ? trim((string) $link['display_name']) : $link['full_name'],
                'full_name'        => $link['full_name'],
                'avatar_path'      => $link['avatar_path'],
                'client_role'      => $link['client_role'],
                'client_status'    => $link['client_status'],
                'start_date'       => $link['start_date'],
                'termination_date' => $link['termination_date'],
                'salary'           => self::money($salary),
                'prorated'         => array_filter($salaryLines, static fn (array $s): bool => $s['days'] < $periodDays) !== [],
                'commission'       => self::money($commission),
                'sales'            => self::money($sales),
                'threshold'        => self::money($threshold),
                'below_threshold'  => $below,
                'total'            => self::money($salary + $commission),
                'salary_lines'     => $salaryLines,
                'commission_lines' => array_map(static fn (array $l): array => ['share' => self::money($l['share']), 'commission' => self::money($l['commission']),
                    'line_amount' => self::money($l['line_amount']), 'commission_value' => $l['commission_value'] === null ? null : self::money($l['commission_value'])] + $l, $lines),
            ];
        }
        usort($people, static fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));

        return [
            'currency_code' => $business['currency_code'],
            'commission'    => ['status' => $business['commission_status'], 'period' => $business['commission_period'],
                'threshold' => self::money(self::cents($business['commission_threshold']))],
            'people'        => $people,
        ];
    }

    /**
     * The shares of the service lines the people performed in the period, and what each earns them
     * @param list<int> $clientIds
     * @return array<int, list<array>> client_id => lines, by the time they start; share, commission and line_amount
     *         in cents, commission_value in cents (F) or hundredths of a percent (P), null when they have no rate
     */
    private function commissionLines(int $businessId, array $clientIds, string $from, string $to, DateTimeZone $timezone): array
    {
        $utc     = new DateTimeZone('UTC');
        $fromUtc = (new DateTimeImmutable($from, $timezone))->setTimezone($utc)->format(self::DB_FORMAT);
        $toUtc   = (new DateTimeImmutable($to, $timezone))->modify('+1 day')->setTimezone($utc)->format(self::DB_FORMAT);

        // the slots of the period with someone of ours working them, and the lines booked on their sessions
        $rows = $this->db->table('order_line ol')
            ->select('ol.id AS line_id, ol.session_id, ol.line_kind, ol.artifact_id, ol.artifact_name, ol.variant_label, ol.quantity, ol.unit_price, '
                . 'ol.discount_amount, ol.tax_calculation, ol.tax_amount, o.public_id AS order_public_id, o.order_number, o.first_name, o.last_name, '
                . 'sl.id AS slot_id, sl.slot_number, sl.starts_at')
            ->join('order_master o', 'o.id = ol.order_id')
            ->join('service_session s', 's.id = ol.session_id')
            ->join(ServiceSessionModel::SLOTS . ' sl', 'sl.session_id = s.id')
            ->where('o.business_id', $businessId)->where('o.cancelled_at', null)
            ->whereIn('ol.line_kind', ['A', 'G'])
            ->where('s.session_status !=', ServiceSessionModel::STATUS_CANCELLED)
            ->where('sl.slot_status', ServiceSessionModel::SLOT_SCHEDULED)
            ->where('sl.starts_at >=', $fromUtc)->where('sl.starts_at <', $toUtc)
            ->orderBy('sl.starts_at')->orderBy('o.id')->orderBy('ol.id')
            ->get()->getResultArray();
        if ($rows === []) {
            return [];
        }
        $sessionIds = array_values(array_unique(array_map('intval', array_column($rows, 'session_id'))));
        $slotCount  = [];
        foreach ($this->db->table(ServiceSessionModel::SLOTS)->select('session_id, COUNT(*) AS n')->whereIn('session_id', $sessionIds)
            ->where('slot_status', ServiceSessionModel::SLOT_SCHEDULED)->groupBy('session_id')->get()->getResultArray() as $row) {
            $slotCount[(int) $row['session_id']] = (int) $row['n'];
        }
        $slotIds = array_values(array_unique(array_map('intval', array_column($rows, 'slot_id'))));
        $staff   = [];
        foreach ($this->db->table(ServiceSessionModel::STAFF)->select('slot_id, client_id')->whereIn('slot_id', $slotIds)->get()->getResultArray() as $row) {
            $staff[(int) $row['slot_id']][] = (int) $row['client_id'];
        }
        $rates = [];
        foreach ($this->db->table('business_service_client_link')->select('artifact_id, client_id, commission_value, commission_unit')
            ->where('business_id', $businessId)->whereIn('client_id', $clientIds)->get()->getResultArray() as $row) {
            $rates[(int) $row['client_id']][(int) $row['artifact_id']] = $row;
        }

        $out = [];
        foreach ($rows as $row) {
            $people = $staff[(int) $row['slot_id']] ?? [];
            if ($people === []) {
                continue;
            }
            $quantity = (int) $row['quantity'];
            $amount   = self::cents($row['unit_price']) * $quantity - self::cents($row['discount_amount'])
                - ($row['tax_calculation'] === 'I' ? self::cents($row['tax_amount']) : 0);
            $amount   = max(0, $amount);
            $slots    = max(1, $slotCount[(int) $row['session_id']] ?? 1);
            $parts    = $slots * count($people);
            $share    = (int) round($amount / $parts);
            foreach ($people as $clientId) {
                if (! in_array($clientId, $clientIds, true)) {
                    continue;
                }
                $rate  = $rates[$clientId][(int) $row['artifact_id']] ?? null;
                $value = $rate === null ? 0 : self::cents($rate['commission_value']);
                $unit  = $rate['commission_unit'] ?? null;
                $earns = match (true) {
                    $rate === null || $value === 0 => 0,
                    $unit === 'F'                  => (int) round($value * $quantity / $parts),
                    default                        => (int) round($share * $value / 10000),
                };
                $out[$clientId][] = [
                    'order_public_id'  => $row['order_public_id'],
                    'order_number'     => $row['order_number'],
                    'kind'             => $row['line_kind'],
                    'service_name'     => $row['artifact_name'],
                    'variant_label'    => $row['variant_label'],
                    'customer_name'    => trim($row['first_name'] . ' ' . $row['last_name']),
                    'local_starts_at'  => (new DateTimeImmutable($row['starts_at'], $utc))->setTimezone($timezone)->format(self::LOCAL_FORMAT),
                    'slot_number'      => (int) $row['slot_number'],
                    'slot_count'       => $slots,
                    'staff_count'      => count($people),
                    'line_amount'      => $amount,
                    'quantity'         => $quantity,
                    'share'            => $share,
                    'commission_unit'  => $unit,
                    'commission_value' => $rate === null ? null : $value,
                    'commission'       => $earns,
                ];
            }
        }

        return $out;
    }

    private static function cents(mixed $amount): int
    {
        return \App\Libraries\Money::toCents((string) \App\Libraries\Money::format($amount ?? 0));
    }

    private static function money(int $cents): string
    {
        return \App\Libraries\Money::fromCents($cents);
    }
}
