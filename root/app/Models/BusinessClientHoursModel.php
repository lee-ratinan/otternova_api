<?php

namespace App\Models;

use App\Libraries\ActivityLog;
use CodeIgniter\Model;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use InvalidArgumentException;
use Throwable;

/**
 * Model for the business_client_hours table, the regular weekly shifts of the people of a business, and the lookups of
 * when and where someone works, which also read business_client_hours_exceptions: the dates whose shifts replace the
 * regular ones. Only active rows (active_status A) count; nothing is ever deleted, a removed shift becomes I.
 * Each shift is in its own store's store_timezone. One whose end_time is not after its start_time ends on the next
 * day, but still belongs to the day it starts on: 22:00-06:00 on Monday ends at 6am on Tuesday.
 * The writes here check what the database cannot: a person's shifts never overlap, at any store and in any timezone,
 * a late one running into the next day's first (Sunday's into Monday's) included; a day off is alone on its date.
 * That a store is one the person works at is the database's foreign key on business_store_client_link; that it is
 * active, the caller's check.
 */
class BusinessClientHoursModel extends Model
{
    use TransactionTrait;

    public const EXCEPTIONS_TABLE = 'business_client_hours_exceptions';

    // active_status
    public const STATUS_ACTIVE   = 'A';
    public const STATUS_INACTIVE = 'I';

    // exception_type
    public const TYPE_VACATION = 'V';
    public const TYPE_SICK     = 'S';
    public const TYPE_OTHER    = 'O';
    public const TYPES         = [self::TYPE_VACATION, self::TYPE_SICK, self::TYPE_OTHER];

    /** The most shifts one day may have */
    public const MAX_SHIFTS_PER_DAY = 6;

    /** A Monday to lay a regular week on, to compare its shifts as moments in time across timezones */
    private const REFERENCE_MONDAY = '2026-01-05';

    protected $table            = 'business_client_hours';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $protectFields    = true;
    protected $allowedFields    = ['business_id', 'client_id', 'store_id', 'day_of_week', 'start_time', 'end_time', 'active_status'];

    // created_at and updated_at are maintained by the database
    protected $useTimestamps = false;

    // ---- lookups ----

    /**
     * When and where a person works on a day: the exceptions of that date when it has any, their regular shifts of
     * its weekday otherwise; none before they started or after they were terminated. The last shift may end on the
     * next calendar day.
     * @param int    $businessId
     * @param int    $clientId
     * @param string $date     Y-m-d
     * @param string $timezone 'local' for each shift in its own store's timezone, or any IANA timezone ('UTC', the
     *                         viewer's) for all of them in one
     * @return array{date: string, exception: array{type: string, reason: string|null}|null, shifts: list<array{store_id: int, start: string, end: string}>}
     *         start and end in ISO 8601 with their offset (in UTC, ending in Z), by start; shifts is empty on a day off
     * @throws InvalidArgumentException When $date or $timezone is not valid
     */
    public function getWorkingHoursOf(int $businessId, int $clientId, string $date, string $timezone = 'local'): array
    {
        $day  = self::date($date);
        $zone = $timezone === 'local' ? null : self::timezone($timezone);
        [$shifts, $exception] = $this->employed($businessId, $clientId, $date)
            ? $this->shiftsOn($businessId, $clientId, $day, $this->zonesOf($businessId))
            : [[], null];

        return [
            'date'      => $date,
            'exception' => $exception,
            'shifts'    => array_map(static function (array $shift) use ($zone): array {
                $at     = $zone ?? $shift[0]->getTimezone();
                $format = $at->getName() === 'UTC' ? 'Y-m-d\TH:i:s\Z' : 'Y-m-d\TH:i:sP';

                return ['store_id' => $shift[2], 'start' => $shift[0]->setTimezone($at)->format($format), 'end' => $shift[1]->setTimezone($at)->format($format)];
            }, $shifts),
        ];
    }

    /**
     * Whether a person works at a moment (at a store, when given): a shift counts from its start, up to but not
     * including its end. The day before is looked at too, as its last shift may run past midnight.
     * @param int      $businessId
     * @param int      $clientId
     * @param string   $datetime ISO 8601 with an offset (2026-11-08T01:30:00+07:00, ...Z); with none, UTC
     * @param int|null $storeId  Only shifts at this store
     * @return bool
     * @throws InvalidArgumentException When $datetime is not valid
     */
    public function isWorkingAt(int $businessId, int $clientId, string $datetime, ?int $storeId = null): bool
    {
        try {
            $moment = new DateTimeImmutable($datetime, new DateTimeZone('UTC'));
        } catch (Exception) {
            throw new InvalidArgumentException('Not a date and time: ' . $datetime);
        }
        $zones = $this->zonesOf($businessId);
        // the calendar date differs from one timezone to the next: the days around it in UTC cover them all
        $utc = new DateTimeImmutable($moment->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d'));
        foreach ([$utc->modify('-2 days'), $utc->modify('-1 day'), $utc, $utc->modify('+1 day')] as $day) {
            if (! $this->employed($businessId, $clientId, $day->format('Y-m-d'))) {
                continue;
            }
            foreach ($this->shiftsOn($businessId, $clientId, $day, $zones)[0] as [$start, $end, $store]) {
                if (($storeId === null || $storeId === $store) && $start <= $moment && $moment < $end) {
                    return true;
                }
            }
        }

        return false;
    }

    // ---- what the Client app's Staff controller reads and writes ----

    /**
     * A person's active regular shifts, by day and start
     * @param int $businessId
     * @param int $clientId
     * @return list<array{store_id: int, day_of_week: int, start_time: string, end_time: string}> Times as HH:MM
     */
    public function weeklyOf(int $businessId, int $clientId): array
    {
        $rows = $this->db->table($this->table)->select('store_id, day_of_week, start_time, end_time')
            ->where('business_id', $businessId)->where('client_id', $clientId)->where('active_status', self::STATUS_ACTIVE)
            ->orderBy('day_of_week')->orderBy('start_time')
            ->get()->getResultArray();

        return array_map(static fn (array $row): array => [
            'store_id'    => (int) $row['store_id'],
            'day_of_week' => (int) $row['day_of_week'],
            'start_time'  => self::hhmm($row['start_time']),
            'end_time'    => self::hhmm($row['end_time']),
        ], $rows);
    }

    /**
     * A person's active exceptions from a date on, by date
     * @param int    $businessId
     * @param int    $clientId
     * @param string $fromDate Y-m-d
     * @return list<array{exception_date: string, exception_type: string, exception_reason: string|null, off: bool, shifts: list<array{store_id: int, start_time: string, end_time: string}>}>
     */
    public function exceptionsFrom(int $businessId, int $clientId, string $fromDate): array
    {
        $rows = $this->db->table(self::EXCEPTIONS_TABLE)
            ->select('exception_date, exception_type, exception_reason, store_id, start_time, end_time')
            ->where('business_id', $businessId)->where('client_id', $clientId)->where('active_status', self::STATUS_ACTIVE)
            ->where('exception_date >=', $fromDate)
            ->orderBy('exception_date')->orderBy('start_time')
            ->get()->getResultArray();
        $dates = [];
        foreach ($rows as $row) {
            $date           = $row['exception_date'];
            $dates[$date] ??= ['exception_date' => $date, 'exception_type' => $row['exception_type'], 'exception_reason' => $row['exception_reason'],
                'off' => true, 'shifts' => []];
            if ($row['store_id'] !== null) {
                $dates[$date]['off']      = false;
                $dates[$date]['shifts'][] = ['store_id' => (int) $row['store_id'], 'start_time' => self::hhmm($row['start_time']), 'end_time' => self::hhmm($row['end_time'])];
            }
        }

        return array_values($dates);
    }

    /**
     * Why these regular shifts cannot be saved, or null when they can: too many in a day, or two that overlap, at any
     * store, a late one running into the next day's first (Sunday's into Monday's) included
     * @param int   $businessId
     * @param list<array{store_id: int, day_of_week: int, start_time: string, end_time: string}> $rows Normalized times
     * @return string|null Language key of Staff
     */
    public function weeklyError(int $businessId, array $rows): ?string
    {
        $perDay = array_count_values(array_map(static fn (array $row): int => $row['day_of_week'], $rows));
        if ($perDay !== [] && max($perDay) > self::MAX_SHIFTS_PER_DAY) {
            return 'Staff.tooManyShifts';
        }
        $zones  = $this->zonesOf($businessId);
        $monday = new DateTimeImmutable(self::REFERENCE_MONDAY);
        $shifts = [];
        foreach ([0, 7] as $week) { // and the week after, for Sunday's late shift
            foreach ($rows as $row) {
                $day      = $monday->modify('+' . ($week + $row['day_of_week'] - 1) . ' days')->format('Y-m-d');
                $shifts[] = self::shift($day, $row, $zones[$row['store_id']]);
            }
        }

        return self::overlap($shifts) ? 'Staff.shiftsOverlap' : null;
    }

    /**
     * Why these shifts cannot be a person's on a date, or null when they can: too many, two that overlap, or one that
     * overlaps a late shift of the day before or runs into the first of the day after
     * @param int    $businessId
     * @param int    $clientId
     * @param string $date Y-m-d
     * @param list<array{store_id: int, start_time: string, end_time: string}> $rows Normalized times; none for a day off
     * @return string|null Language key of Staff
     */
    public function exceptionError(int $businessId, int $clientId, string $date, array $rows): ?string
    {
        if (count($rows) > self::MAX_SHIFTS_PER_DAY) {
            return 'Staff.tooManyShifts';
        }
        $zones = $this->zonesOf($businessId);
        $own   = array_map(static fn (array $row): array => self::shift($date, $row, $zones[$row['store_id']]), $rows);
        if (self::overlap($own)) {
            return 'Staff.shiftsOverlap';
        }
        $day = self::date($date);

        return self::overlap([
            ...$this->shiftsOn($businessId, $clientId, $day->modify('-1 day'), $zones)[0],
            ...$own,
            ...$this->shiftsOn($businessId, $clientId, $day->modify('+1 day'), $zones)[0],
        ]) ? 'Staff.shiftsOverlapNeighbour' : null;
    }

    /**
     * Make $rows the person's regular shifts, in one transaction, logged to log_activity in it. weeklyError() must have
     * passed. A shift is known by its store, day and start: one whose end changes is updated, an inactive one wanted
     * again is made active, and an active one left out becomes inactive.
     * @param int    $businessId
     * @param int    $clientId
     * @param list<array{store_id: int, day_of_week: int, start_time: string, end_time: string}> $rows Normalized times
     * @param int    $actorId auth_master.id of whoever makes the change
     * @param string $uid     auth_master.uid of the person, for the log
     * @return void
     * @throws Throwable When a write fails; nothing is written then
     */
    public function replaceWeekly(int $businessId, int $clientId, array $rows, int $actorId, string $uid): void
    {
        $key    = static fn (array $row): string => $row['store_id'] . ' ' . $row['day_of_week'] . ' ' . self::clock($row['start_time']);
        $wanted = [];
        foreach ($rows as $row) {
            $wanted[$key($row)] = $row;
        }
        $log = new ActivityLog();
        $this->inTransaction(function () use ($businessId, $clientId, $wanted, $key, $actorId, $uid, $log) {
            $stored = $this->db->table($this->table)->where('business_id', $businessId)->where('client_id', $clientId)
                ->orderBy('active_status')->orderBy('id', 'DESC') // the active one first, then the latest inactive
                ->get()->getResultArray();
            $done   = [];
            foreach ($stored as $row) {
                $k      = $key($row);
                $active = $row['active_status'] === self::STATUS_ACTIVE;
                if (! isset($wanted[$k]) || isset($done[$k])) {
                    if ($active) {
                        $this->write($this->table, $row, ['active_status' => self::STATUS_INACTIVE], 'Ended a shift of ' . $uid, $actorId, $log);
                    }
                    continue;
                }
                $done[$k] = true;
                $changes  = ActivityLog::changes(['end_time' => self::clock($row['end_time']), 'active_status' => $row['active_status']],
                    ['end_time' => $wanted[$k]['end_time'], 'active_status' => self::STATUS_ACTIVE]);
                if ($changes !== []) {
                    $this->write($this->table, $row, $changes, 'Changed a shift of ' . $uid, $actorId, $log);
                }
            }
            foreach ($wanted as $k => $row) {
                if (! isset($done[$k])) {
                    $row = ['business_id' => $businessId, 'client_id' => $clientId] + $row;
                    $this->db->table($this->table)->insert($row);
                    $log->inserted($actorId, 'Added a shift of ' . $uid, $this->table, (int) $this->db->insertID(), $row);
                }
            }
        });
    }

    /**
     * Make $rows the person's shifts on a date in place of their regular ones, in one transaction, logged to
     * log_activity in it: the active rows the date had become inactive. exceptionError() must have passed.
     * @param int         $businessId
     * @param int         $clientId
     * @param string      $date   Y-m-d
     * @param string      $type   One of TYPES
     * @param string|null $reason
     * @param list<array{store_id: int, start_time: string, end_time: string}> $rows Normalized times; none: a day off
     * @param int         $actorId
     * @param string      $uid    auth_master.uid of the person, for the log
     * @return void
     * @throws Throwable When a write fails; nothing is written then
     */
    public function saveException(int $businessId, int $clientId, string $date, string $type, ?string $reason, array $rows, int $actorId, string $uid): void
    {
        $log = new ActivityLog();
        $this->inTransaction(function () use ($businessId, $clientId, $date, $type, $reason, $rows, $actorId, $uid, $log) {
            $this->endException($businessId, $clientId, $date, $actorId, $uid, $log);
            foreach ($rows === [] ? [['store_id' => null, 'start_time' => null, 'end_time' => null]] : $rows as $row) {
                $row = ['business_id' => $businessId, 'client_id' => $clientId, 'exception_date' => $date, 'exception_type' => $type]
                    + $row + ['exception_reason' => $reason];
                $this->db->table(self::EXCEPTIONS_TABLE)->insert($row);
                $log->inserted($actorId, 'Set the shifts of ' . $uid . ' on ' . $date, self::EXCEPTIONS_TABLE, (int) $this->db->insertID(), $row);
            }
        });
    }

    /**
     * Give a date back the person's regular shifts: its active exception rows become inactive
     * @param int    $businessId
     * @param int    $clientId
     * @param string $date
     * @param int    $actorId
     * @param string $uid
     * @return bool Whether the date had an exception
     * @throws Throwable When a write fails; nothing is written then
     */
    public function deleteException(int $businessId, int $clientId, string $date, int $actorId, string $uid): bool
    {
        $log = new ActivityLog();

        return $this->inTransaction(fn () => $this->endException($businessId, $clientId, $date, $actorId, $uid, $log) > 0);
    }

    // ---- inside ----

    /**
     * @return int How many rows were made inactive
     */
    private function endException(int $businessId, int $clientId, string $date, int $actorId, string $uid, ActivityLog $log): int
    {
        $rows = $this->db->table(self::EXCEPTIONS_TABLE)->where('business_id', $businessId)->where('client_id', $clientId)
            ->where('exception_date', $date)->where('active_status', self::STATUS_ACTIVE)->get()->getResultArray();
        foreach ($rows as $row) {
            $this->write(self::EXCEPTIONS_TABLE, $row, ['active_status' => self::STATUS_INACTIVE], 'Removed the shifts of ' . $uid . ' on ' . $date, $actorId, $log);
        }

        return count($rows);
    }

    private function write(string $table, array $row, array $changes, string $summary, int $actorId, ActivityLog $log): void
    {
        $this->db->table($table)->where('id', $row['id'])->update($changes);
        $log->updated($actorId, $summary, $table, (int) $row['id'], $row, $changes);
    }

    /**
     * The shifts of a day, from its active exceptions when it has any, from its weekday's regular ones otherwise
     * @param int                         $businessId
     * @param int                         $clientId
     * @param DateTimeImmutable           $day   Any time of the day; only its date counts
     * @param array<int, DateTimeZone>    $zones store_id => its timezone
     * @return array{0: list<array{0: DateTimeImmutable, 1: DateTimeImmutable, 2: int}>, 1: array{type: string, reason: string|null}|null}
     *         [start, end, store_id] by start, and the exception
     */
    private function shiftsOn(int $businessId, int $clientId, DateTimeImmutable $day, array $zones): array
    {
        $date = $day->format('Y-m-d');
        $rows = $this->db->table(self::EXCEPTIONS_TABLE)->select('exception_type, exception_reason, store_id, start_time, end_time')
            ->where('business_id', $businessId)->where('client_id', $clientId)->where('exception_date', $date)->where('active_status', self::STATUS_ACTIVE)
            ->get()->getResultArray();
        $exception = null;
        if ($rows !== []) {
            $exception = ['type' => $rows[0]['exception_type'], 'reason' => $rows[0]['exception_reason']];
            $rows      = array_filter($rows, static fn (array $row): bool => $row['store_id'] !== null);
        } else {
            $rows = $this->db->table($this->table)->select('store_id, start_time, end_time')
                ->where('business_id', $businessId)->where('client_id', $clientId)
                ->where('day_of_week', (int) $day->format('N'))->where('active_status', self::STATUS_ACTIVE)
                ->get()->getResultArray();
        }
        $shifts = array_map(static fn (array $row): array => self::shift($date, $row, $zones[(int) $row['store_id']]), $rows);
        usort($shifts, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return [$shifts, $exception];
    }

    /**
     * Whether the person works in the business on a date: on or after their start_date, and not after their
     * termination_date
     */
    private function employed(int $businessId, int $clientId, string $date): bool
    {
        $link = $this->db->table('business_client_link')->select('start_date, termination_date')
            ->where('business_id', $businessId)->where('client_id', $clientId)->get()->getRowArray();

        return $link !== null && $date >= $link['start_date'] && ($link['termination_date'] === null || $date <= $link['termination_date']);
    }

    /**
     * @param int $businessId
     * @return array<int, DateTimeZone> store_id => its store_timezone, for every store of the business
     */
    private function zonesOf(int $businessId): array
    {
        $zones = [];
        foreach ($this->db->table('business_store')->select('id, store_timezone')->where('business_id', $businessId)->get()->getResultArray() as $row) {
            $zones[(int) $row['id']] = self::timezone($row['store_timezone']);
        }

        return $zones;
    }

    /**
     * A shift on $date, in its store's timezone
     * @param string       $date Y-m-d
     * @param array        $row  store_id, start_time, end_time
     * @param DateTimeZone $zone
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable, 2: int} [start, end, store_id]
     */
    private static function shift(string $date, array $row, DateTimeZone $zone): array
    {
        $start = self::clock($row['start_time']);
        $end   = self::clock($row['end_time']);
        $next  = (new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d');

        return [new DateTimeImmutable($date . ' ' . $start, $zone), new DateTimeImmutable(($end > $start ? $date : $next) . ' ' . $end, $zone), (int) $row['store_id']];
    }

    /**
     * Whether any two of the shifts overlap; one ending as another starts does not
     * @param list<array{0: DateTimeImmutable, 1: DateTimeImmutable}> $shifts
     */
    private static function overlap(array $shifts): bool
    {
        usort($shifts, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        for ($i = 1, $n = count($shifts); $i < $n; $i++) {
            if ($shifts[$i][0] < $shifts[$i - 1][1]) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $date Y-m-d
     * @return DateTimeImmutable Its midnight, in UTC
     * @throws InvalidArgumentException When it is not a real date
     */
    private static function date(string $date): DateTimeImmutable
    {
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));
        if ($day === false || $day->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException('Not a date: ' . $date);
        }

        return $day;
    }

    private static function timezone(string $name): DateTimeZone
    {
        try {
            return new DateTimeZone($name);
        } catch (Exception) {
            throw new InvalidArgumentException('Not a timezone: ' . $name);
        }
    }

    /** HH:MM or HH:MM:SS as HH:MM:SS */
    private static function clock(string $time): string
    {
        return strlen($time) === 5 ? $time . ':00' : substr($time, 0, 8);
    }

    /** A TIME as HH:MM */
    private static function hhmm(string $time): string
    {
        return substr(self::clock($time), 0, 5);
    }
}
