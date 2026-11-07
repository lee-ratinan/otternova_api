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
 * Model for the business_store_hours table, the weekly opening hours of a store, and the lookups of when a store is
 * open, which also read business_store_hours_exceptions: the dates whose hours replace the weekly ones.
 * Times are stored in business_store.store_timezone. An opening whose close_time is not after its open_time closes on
 * the next day, but still belongs to the business day it opened on: 10:00-02:00 on 7 Nov closes at 2am on 8 Nov.
 * The writes here check what the database cannot: the openings of a day never overlap, nor run into the next day's.
 */
class BusinessStoreHoursModel extends Model
{
    use TransactionTrait;

    public const EXCEPTIONS_TABLE = 'business_store_hours_exceptions';

    /** The most openings one day may have */
    public const MAX_OPENINGS_PER_DAY = 6;

    protected $table            = 'business_store_hours';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = ['store_id', 'day_of_week', 'open_time', 'close_time'];

    // created_at and updated_at are maintained by the database
    protected $useTimestamps = false;

    protected $validationRules = [
        'store_id'    => 'required|is_natural_no_zero',
        'day_of_week' => 'required|in_list[1,2,3,4,5,6,7]',
        'open_time'   => 'required|regex_match[/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/]',
        'close_time'  => 'required|regex_match[/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/]',
    ];

    /**
     * The opening hours of a store on a business day: the exceptions of that date when it has any, the weekly hours
     * of its weekday otherwise. The last opening may close on the next calendar day.
     * @param int    $storeId  business_store.id
     * @param string $date     The business day, Y-m-d (2026-11-07)
     * @param string $timezone 'local' for the store's store_timezone, or any IANA timezone ('UTC', the viewer's)
     * @return array{date: string, timezone: string, exception: array{reason: string}|null, hours: list<array{open: string, close: string}>}
     *               open and close in ISO 8601 with their offset (2026-11-08T02:00:00+07:00; in UTC, 2026-11-07T19:00:00Z),
     *               by opening time; hours is empty when the store is closed that day
     * @throws InvalidArgumentException When the store does not exist, or $date or $timezone is not valid
     */
    public function getBusinessHoursOf(int $storeId, string $date, string $timezone = 'local'): array
    {
        $storeZone = $this->storeTimezone($storeId);
        $zone      = $timezone === 'local' ? $storeZone : self::timezone($timezone);
        $format    = $zone->getName() === 'UTC' ? 'Y-m-d\TH:i:s\Z' : 'Y-m-d\TH:i:sP';
        [$openings, $reason] = $this->openingsOf($storeId, self::date($date, $storeZone), $storeZone);

        return [
            'date'      => $date,
            'timezone'  => $zone->getName(),
            'exception' => $reason === null ? null : ['reason' => $reason],
            'hours'     => array_map(static fn (array $opening): array => [
                'open'  => $opening[0]->setTimezone($zone)->format($format),
                'close' => $opening[1]->setTimezone($zone)->format($format),
            ], $openings),
        ];
    }

    /**
     * Whether a store is open at a moment: an opening counts from its open time, up to but not including its close.
     * The business day before is looked at too, as its last opening may run past midnight.
     * @param int    $storeId  business_store.id
     * @param string $datetime ISO 8601 (2026-11-08T01:30:00+07:00, 2026-11-07T18:30:00Z); with no offset, it is the
     *                         store's local time
     * @return bool
     * @throws InvalidArgumentException When the store does not exist, or $datetime is not valid
     */
    public function isOpenAt(int $storeId, string $datetime): bool
    {
        $storeZone = $this->storeTimezone($storeId);
        try {
            $moment = new DateTimeImmutable($datetime, $storeZone); // the zone counts only when $datetime has none
        } catch (Exception) {
            throw new InvalidArgumentException('Not a date and time: ' . $datetime);
        }
        $today = new DateTimeImmutable($moment->setTimezone($storeZone)->format('Y-m-d'), $storeZone);
        foreach ([$today->modify('-1 day'), $today] as $day) {
            foreach ($this->openingsOf($storeId, $day, $storeZone)[0] as [$open, $close]) {
                if ($open <= $moment && $moment < $close) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The openings of a business day, from its exceptions when it has any, from its weekday's hours otherwise
     * @param int               $storeId
     * @param DateTimeImmutable $day       Midnight of the business day, in $storeZone
     * @param DateTimeZone      $storeZone
     * @return array{0: list<array{0: DateTimeImmutable, 1: DateTimeImmutable}>, 1: string|null} The openings by
     *                                                                                             opening time, and the exception's reason
     */
    private function openingsOf(int $storeId, DateTimeImmutable $day, DateTimeZone $storeZone): array
    {
        $date   = $day->format('Y-m-d');
        $reason = null;
        $rows   = $this->db->table(self::EXCEPTIONS_TABLE)
            ->select('open_time, close_time, exception_reason')
            ->where('store_id', $storeId)
            ->where('exception_date', $date)
            ->get()
            ->getResultArray();
        if ($rows !== []) {
            $reason = $rows[0]['exception_reason'];
            // a closed day's row has no times
            $rows = array_filter($rows, static fn (array $row): bool => $row['open_time'] !== null && $row['close_time'] !== null);
        } else {
            $rows = $this->db->table($this->table)
                ->select('open_time, close_time')
                ->where('store_id', $storeId)
                ->where('day_of_week', (int) $day->format('N'))
                ->get()
                ->getResultArray();
        }

        return [self::build($date, $rows, $storeZone), $reason];
    }

    /**
     * The openings of $rows on the business day $date, by opening time
     * @param string       $date      Y-m-d
     * @param array        $rows      open_time, close_time
     * @param DateTimeZone $storeZone
     * @return list<array{0: DateTimeImmutable, 1: DateTimeImmutable}>
     */
    private static function build(string $date, array $rows, DateTimeZone $storeZone): array
    {
        $next     = (new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d');
        $openings = [];
        foreach ($rows as $row) {
            $open  = self::seconds($row['open_time']);
            $close = self::seconds($row['close_time']);
            // built from the date and time rather than by adding seconds to midnight, so a DST change lands right
            $openings[] = [
                new DateTimeImmutable($date . ' ' . self::clock($open), $storeZone),
                new DateTimeImmutable(($close > $open ? $date : $next) . ' ' . self::clock($close), $storeZone),
            ];
        }
        usort($openings, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return $openings;
    }

    // ---- what the Client app's StoreHours controller reads and writes ----

    /**
     * The weekly hours of a store, by day and opening time
     * @param int $storeId
     * @return list<array{day_of_week: int, open_time: string, close_time: string}> Times as HH:MM
     */
    public function weeklyOf(int $storeId): array
    {
        $rows = $this->db->table($this->table)->select('day_of_week, open_time, close_time')
            ->where('store_id', $storeId)
            ->orderBy('day_of_week')->orderBy('open_time')
            ->get()->getResultArray();

        return array_map(static fn (array $row): array => [
            'day_of_week' => (int) $row['day_of_week'],
            'open_time'   => substr(self::clock(self::seconds($row['open_time'])), 0, 5),
            'close_time'  => substr(self::clock(self::seconds($row['close_time'])), 0, 5),
        ], $rows);
    }

    /**
     * The exceptions of a store from a date on, by date
     * @param int    $storeId
     * @param string $fromDate Y-m-d
     * @return list<array{exception_date: string, exception_reason: string, closed: bool, hours: list<array{open_time: string, close_time: string}>}>
     *         hours by opening time, as HH:MM; none when closed
     */
    public function exceptionsFrom(int $storeId, string $fromDate): array
    {
        $rows = $this->db->table(self::EXCEPTIONS_TABLE)->select('exception_date, open_time, close_time, exception_reason')
            ->where('store_id', $storeId)->where('exception_date >=', $fromDate)
            ->orderBy('exception_date')->orderBy('open_time')
            ->get()->getResultArray();
        $dates = [];
        foreach ($rows as $row) {
            $date           = $row['exception_date'];
            $dates[$date] ??= ['exception_date' => $date, 'exception_reason' => $row['exception_reason'], 'closed' => true, 'hours' => []];
            if ($row['open_time'] !== null && $row['close_time'] !== null) {
                $dates[$date]['closed']  = false;
                $dates[$date]['hours'][] = [
                    'open_time'  => substr(self::clock(self::seconds($row['open_time'])), 0, 5),
                    'close_time' => substr(self::clock(self::seconds($row['close_time'])), 0, 5),
                ];
            }
        }

        return array_values($dates);
    }

    /**
     * Today's date where the store is
     * @param int $storeId
     * @return string Y-m-d
     * @throws InvalidArgumentException When there is no such store
     */
    public function localToday(int $storeId): string
    {
        return (new DateTimeImmutable('now', $this->storeTimezone($storeId)))->format('Y-m-d');
    }

    /**
     * A time of day as the tables store it
     * @param mixed $value HH:MM or HH:MM:SS
     * @return string|null HH:MM:SS, or null when it is not a time of day
     */
    public static function normalizeTime(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^([01]\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?$/', trim($value), $m) !== 1) {
            return null;
        }

        return $m[1] . ':' . $m[2] . ':' . ($m[3] ?? '00');
    }

    /**
     * Why these weekly hours cannot be saved, or null when they can: too many openings in a day, or two that
     * overlap, a late one running into the next day's first (Sunday's into Monday's) included
     * @param list<array{day_of_week: int, open_time: string, close_time: string}> $rows Normalized times
     * @return string|null Language key of StoreHours
     */
    public static function weeklyError(array $rows): ?string
    {
        $perDay    = array_count_values(array_map(static fn (array $row): int => $row['day_of_week'], $rows));
        if ($perDay !== [] && max($perDay) > self::MAX_OPENINGS_PER_DAY) {
            return 'StoreHours.tooManyOpenings';
        }
        $week      = 7 * 86400;
        $intervals = [];
        foreach ($rows as $row) {
            $open  = self::seconds($row['open_time']);
            $close = self::seconds($row['close_time']);
            $start = ($row['day_of_week'] - 1) * 86400 + $open;
            $end   = $start + ($close > $open ? $close - $open : $close - $open + 86400);
            $intervals[] = [$start, $end];
            $intervals[] = [$start + $week, $end + $week]; // the week after, for Sunday's late opening
        }

        return self::overlap($intervals) ? 'StoreHours.overlap' : null;
    }

    /**
     * Why these hours cannot be a store's on a date, or null when they can: too many openings, two that overlap, or
     * one that overlaps the late opening of the day before or runs into the first of the day after
     * @param int    $storeId
     * @param string $date Y-m-d
     * @param list<array{open_time: string, close_time: string}> $rows Normalized times; none for closed
     * @return string|null Language key of StoreHours
     * @throws InvalidArgumentException When there is no such store, or $date is not a date
     */
    public function exceptionError(int $storeId, string $date, array $rows): ?string
    {
        if (count($rows) > self::MAX_OPENINGS_PER_DAY) {
            return 'StoreHours.tooManyOpenings';
        }
        $zone = $this->storeTimezone($storeId);
        $day  = self::date($date, $zone);
        $own  = self::build($date, $rows, $zone);
        if (self::overlap(array_map(static fn (array $o): array => [$o[0]->getTimestamp(), $o[1]->getTimestamp()], $own))) {
            return 'StoreHours.overlap';
        }
        $around = [...$this->openingsOf($storeId, $day->modify('-1 day'), $zone)[0], ...$own, ...$this->openingsOf($storeId, $day->modify('+1 day'), $zone)[0]];

        return self::overlap(array_map(static fn (array $o): array => [$o[0]->getTimestamp(), $o[1]->getTimestamp()], $around))
            ? 'StoreHours.overlapsNeighbour' : null;
    }

    /**
     * Make $rows the store's weekly hours, in one transaction, logged to log_activity in it. weeklyError() must have
     * passed. An opening is known by its day and opening time: one whose closing time changes is updated.
     * @param int    $storeId
     * @param list<array{day_of_week: int, open_time: string, close_time: string}> $rows Normalized times
     * @param int    $actorId  auth_master.id of whoever makes the change
     * @param string $publicId business_store.public_id, for the log
     * @return void
     * @throws Throwable When a write fails; nothing is written then
     */
    public function replaceWeekly(int $storeId, array $rows, int $actorId, string $publicId): void
    {
        $wanted = [];
        foreach ($rows as $row) {
            $wanted[$row['day_of_week'] . ' ' . $row['open_time']] = $row;
        }
        $log = new ActivityLog();
        $this->inTransaction(function () use ($storeId, $wanted, $actorId, $publicId, $log) {
            $stored = $this->db->table($this->table)->where('store_id', $storeId)->get()->getResultArray();
            $have   = [];
            foreach ($stored as $row) {
                $key        = $row['day_of_week'] . ' ' . self::clock(self::seconds($row['open_time']));
                $have[$key] = true;
                if (! isset($wanted[$key])) {
                    $this->db->table($this->table)->where('id', $row['id'])->delete();
                    $log->deleted($actorId, 'Removed an opening of store ' . $publicId, $this->table, (int) $row['id'], $row);
                } elseif (self::seconds($row['close_time']) !== self::seconds($wanted[$key]['close_time'])) {
                    $change = ['close_time' => $wanted[$key]['close_time']];
                    $this->db->table($this->table)->where('id', $row['id'])->update($change);
                    $log->updated($actorId, 'Changed an opening of store ' . $publicId, $this->table, (int) $row['id'], $row, $change);
                }
            }
            foreach ($wanted as $key => $row) {
                if (! isset($have[$key])) {
                    $row = ['store_id' => $storeId] + $row;
                    $this->db->table($this->table)->insert($row);
                    $log->inserted($actorId, 'Added an opening to store ' . $publicId, $this->table, (int) $this->db->insertID(), $row);
                }
            }
        });
    }

    /**
     * Make $rows the store's hours on a date in place of its weekly ones, in one transaction, logged to log_activity
     * in it: what the date had is replaced. exceptionError() must have passed.
     * @param int    $storeId
     * @param string $date     Y-m-d
     * @param string $reason
     * @param list<array{open_time: string, close_time: string}> $rows Normalized times; none: closed all day
     * @param int    $actorId
     * @param string $publicId business_store.public_id, for the log
     * @return void
     * @throws Throwable When a write fails; nothing is written then
     */
    public function saveException(int $storeId, string $date, string $reason, array $rows, int $actorId, string $publicId): void
    {
        $log = new ActivityLog();
        $this->inTransaction(function () use ($storeId, $date, $reason, $rows, $actorId, $publicId, $log) {
            $this->removeException($storeId, $date, $actorId, $publicId, $log);
            foreach ($rows === [] ? [['open_time' => null, 'close_time' => null]] : $rows as $row) {
                $row = ['store_id' => $storeId, 'exception_date' => $date] + $row + ['exception_reason' => $reason];
                $this->db->table(self::EXCEPTIONS_TABLE)->insert($row);
                $log->inserted($actorId, 'Set the hours of store ' . $publicId . ' on ' . $date, self::EXCEPTIONS_TABLE, (int) $this->db->insertID(), $row);
            }
        });
    }

    /**
     * Give a date back its weekly hours, in one transaction, logged to log_activity in it
     * @param int    $storeId
     * @param string $date
     * @param int    $actorId
     * @param string $publicId business_store.public_id, for the log
     * @return bool Whether the date had an exception
     * @throws Throwable When a write fails; nothing is written then
     */
    public function deleteException(int $storeId, string $date, int $actorId, string $publicId): bool
    {
        $log = new ActivityLog();

        return $this->inTransaction(fn () => $this->removeException($storeId, $date, $actorId, $publicId, $log) > 0 ? true : false);
    }

    /**
     * @return int How many rows were removed
     */
    private function removeException(int $storeId, string $date, int $actorId, string $publicId, ActivityLog $log): int
    {
        $rows = $this->db->table(self::EXCEPTIONS_TABLE)->where('store_id', $storeId)->where('exception_date', $date)->get()->getResultArray();
        foreach ($rows as $row) {
            $this->db->table(self::EXCEPTIONS_TABLE)->where('id', $row['id'])->delete();
            $log->deleted($actorId, 'Removed the hours of store ' . $publicId . ' on ' . $date, self::EXCEPTIONS_TABLE, (int) $row['id'], $row);
        }

        return count($rows);
    }

    /**
     * Whether any two of the intervals overlap; one ending as another starts does not
     * @param list<array{0: int, 1: int}> $intervals [start, end)
     * @return bool
     */
    private static function overlap(array $intervals): bool
    {
        usort($intervals, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        for ($i = 1, $n = count($intervals); $i < $n; $i++) {
            if ($intervals[$i][0] < $intervals[$i - 1][1]) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param int $storeId
     * @return DateTimeZone The store's store_timezone
     * @throws InvalidArgumentException When there is no such store
     */
    private function storeTimezone(int $storeId): DateTimeZone
    {
        $row = $this->db->table('business_store')->select('store_timezone')->where('id', $storeId)->get()->getRowArray();
        if ($row === null) {
            throw new InvalidArgumentException('No store ' . $storeId);
        }

        return self::timezone($row['store_timezone']);
    }

    /**
     * @param string $name
     * @return DateTimeZone
     * @throws InvalidArgumentException When it is not a timezone
     */
    private static function timezone(string $name): DateTimeZone
    {
        try {
            return new DateTimeZone($name);
        } catch (Exception) {
            throw new InvalidArgumentException('Not a timezone: ' . $name);
        }
    }

    /**
     * @param string       $date Y-m-d
     * @param DateTimeZone $zone
     * @return DateTimeImmutable Midnight of $date in $zone
     * @throws InvalidArgumentException When $date is not a real Y-m-d date
     */
    private static function date(string $date, DateTimeZone $zone): DateTimeImmutable
    {
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $zone);
        if ($day === false || $day->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException('Not a date: ' . $date);
        }

        return $day;
    }

    /**
     * @param string $time HH:MM or HH:MM:SS, as the database returns a TIME
     * @return int Seconds since midnight
     */
    private static function seconds(string $time): int
    {
        $parts = array_map('intval', explode(':', $time)) + [0, 0, 0];

        return $parts[0] * 3600 + $parts[1] * 60 + $parts[2];
    }

    /**
     * @param int $seconds Since midnight
     * @return string HH:MM:SS
     */
    private static function clock(int $seconds): string
    {
        return sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    }
}
