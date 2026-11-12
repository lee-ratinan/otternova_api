<?php

namespace App\Libraries;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * When a customer may book an appointment on the mart (see the AddBookingWindow migration): how far ahead
 * (booking_horizon_days) and how much notice the business needs (booking_notice_unit, _value and _time).
 * A business has its own rule (business_master), and a service variant may have its own instead (artifact_variant,
 * all four columns NULL for the business's). Everything is counted in the store's timezone.
 *
 * The notice is either
 * - M: booking_notice_value minutes before the start (M, 15: up to 15 minutes before), or
 * - D: by booking_notice_time on the day booking_notice_value days before the start's date (D, 1, 23:59: no same-day
 *   booking; D, 1, 18:00: by 6pm the day before), and never after the start itself.
 * The horizon: the start's date is at most booking_horizon_days days after today.
 */
final class BookingWindow
{
    public const NOTICE_MINUTES = 'M';
    public const NOTICE_DAYS    = 'D';

    public const FIELDS = ['booking_horizon_days', 'booking_notice_unit', 'booking_notice_value', 'booking_notice_time'];

    /** What a business left without a value gets: 3 days ahead, an hour's notice */
    public const DEFAULTS = [
        'booking_horizon_days' => 3,
        'booking_notice_unit'  => self::NOTICE_MINUTES,
        'booking_notice_value' => 60,
        'booking_notice_time'  => null,
    ];

    public const MAX_HORIZON_DAYS   = 365;
    public const MAX_NOTICE_MINUTES = 65535; // SMALLINT UNSIGNED, about 45 days
    public const MAX_NOTICE_DAYS    = self::MAX_HORIZON_DAYS;

    /** booking_notice_time as a client sends it, 18:00 or 18:00:00; stored as 18:00:00 */
    public const TIME_PATTERN = '/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/';

    private function __construct()
    {
    }

    /**
     * booking_notice_time as stored (HH:MM:00), or the value as it came when it is not a time, for the checks to refuse
     * @param mixed $value
     * @return string|null
     */
    public static function normalizeTime(mixed $value): ?string
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }
        $value = is_scalar($value) ? trim((string) $value) : '';

        return preg_match(self::TIME_PATTERN, $value) ? substr($value, 0, 5) . ':00' : $value;
    }

    /**
     * Errors for a rule whose columns, each of the right kind already, do not fit together:
     * - the horizon outside 1 to MAX_HORIZON_DAYS
     * - the notice wrong for its unit (see noticeErrors())
     * - a notice longer than the horizon, when nothing could ever be booked
     * @param array $rule The four FIELDS
     * @return array field => error
     */
    public static function errors(array $rule): array
    {
        $errors  = [];
        $horizon = self::int($rule['booking_horizon_days'] ?? null);
        if ($horizon === null || $horizon < 1 || $horizon > self::MAX_HORIZON_DAYS) {
            $errors['booking_horizon_days'] = lang('BusinessMaster.invalidHorizon', [self::MAX_HORIZON_DAYS]);
        }
        $errors += self::noticeErrors($rule, 'booking');
        $unit   = $rule['booking_notice_unit'] ?? null;
        $notice = self::int($rule['booking_notice_value'] ?? null);
        if (! isset($errors['booking_horizon_days']) && ! isset($errors['booking_notice_unit']) && ! isset($errors['booking_notice_value'])
            && ($unit === self::NOTICE_MINUTES ? $notice > $horizon * 1440 : $notice > $horizon)) {
            $errors['booking_notice_value'] = lang('BusinessMaster.noticeBeyondHorizon');
        }

        return $errors;
    }

    /**
     * Errors for a notice of the minutes-or-days shape, {prefix}_notice_unit, _value and _time (the booking notice,
     * the free-cancellation deadline): the unit M or D, the value from 0 to MAX_NOTICE_MINUTES (M) or MAX_NOTICE_DAYS
     * (D), and the time an HH:MM:SS for D and null for M
     * @param array  $row
     * @param string $prefix booking, cancel
     * @return array field => error
     */
    public static function noticeErrors(array $row, string $prefix): array
    {
        $errors = [];
        $unit   = $row[$prefix . '_notice_unit'] ?? null;
        if (! in_array($unit, [self::NOTICE_MINUTES, self::NOTICE_DAYS], true)) {
            return [$prefix . '_notice_unit' => lang('BusinessMaster.invalidNoticeUnit')];
        }
        $max    = $unit === self::NOTICE_MINUTES ? self::MAX_NOTICE_MINUTES : self::MAX_NOTICE_DAYS;
        $notice = self::int($row[$prefix . '_notice_value'] ?? null);
        if ($notice === null || $notice < 0 || $notice > $max) {
            $errors[$prefix . '_notice_value'] = lang('BusinessMaster.invalidNotice', [$max]);
        }
        $time = $row[$prefix . '_notice_time'] ?? null;
        if ($unit === self::NOTICE_DAYS && ($time === null || ! preg_match(self::TIME_PATTERN, (string) $time))) {
            $errors[$prefix . '_notice_time'] = lang('BusinessMaster.noticeTimeRequired');
        } elseif ($unit === self::NOTICE_MINUTES && $time !== null) {
            $errors[$prefix . '_notice_time'] = lang('BusinessMaster.noticeTimeDaysOnly');
        }

        return $errors;
    }

    /**
     * The rule a variant is booked by: its own when it has one, else its business's, typed
     * @param array      $business business_master, with the FIELDS
     * @param array|null $variant  artifact_variant, with the FIELDS; null for none
     * @return array{booking_horizon_days: int, booking_notice_unit: string, booking_notice_value: int, booking_notice_time: string|null}
     */
    public static function effective(array $business, ?array $variant = null): array
    {
        $source = $variant !== null && ($variant['booking_horizon_days'] ?? null) !== null ? $variant : $business;

        return [
            'booking_horizon_days' => (int) ($source['booking_horizon_days'] ?? self::DEFAULTS['booking_horizon_days']),
            'booking_notice_unit'  => (string) ($source['booking_notice_unit'] ?? self::DEFAULTS['booking_notice_unit']),
            'booking_notice_value' => (int) ($source['booking_notice_value'] ?? self::DEFAULTS['booking_notice_value']),
            'booking_notice_time'  => self::normalizeTime($source['booking_notice_time'] ?? null),
        ];
    }

    /**
     * The last moment to book a start under $rule: its notice before the start, and never after the start
     * @param array             $rule  As effective() gives it
     * @param DateTimeInterface $start
     * @param DateTimeZone      $zone  The store's
     * @return DateTimeImmutable In UTC
     */
    public static function closesAt(array $rule, DateTimeInterface $start, DateTimeZone $zone): DateTimeImmutable
    {
        $start = DateTimeImmutable::createFromInterface($start)->setTimezone($zone);
        if ($rule['booking_notice_unit'] === self::NOTICE_DAYS) {
            [$hour, $minute] = array_map('intval', explode(':', (string) $rule['booking_notice_time']));
            $closes          = $start->setTime(0, 0)->modify('-' . $rule['booking_notice_value'] . ' days')->setTime($hour, $minute);
            $closes          = min($closes, $start);
        } else {
            $closes = $start->modify('-' . $rule['booking_notice_value'] . ' minutes');
        }

        return $closes->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * The starts a customer may book now under $rule: from earliest, and before latestBefore (the start of the day
     * after the horizon's last day)
     * @param array             $rule As effective() gives it
     * @param DateTimeZone      $zone The store's
     * @param DateTimeInterface $now
     * @return array{earliest: DateTimeImmutable, latestBefore: DateTimeImmutable} In UTC
     */
    public static function window(array $rule, DateTimeZone $zone, DateTimeInterface $now): array
    {
        $utc   = new DateTimeZone('UTC');
        $now   = DateTimeImmutable::createFromInterface($now)->setTimezone($zone);
        $today = $now->setTime(0, 0);
        if ($rule['booking_notice_unit'] === self::NOTICE_DAYS) {
            // today's cutoff is for the starts notice days ahead; once it has passed, a day later
            [$hour, $minute] = array_map('intval', explode(':', (string) $rule['booking_notice_time']));
            $days            = $rule['booking_notice_value'] + ($now > $today->setTime($hour, $minute) ? 1 : 0);
            $earliest        = max($today->modify('+' . $days . ' days'), $now);
        } else {
            $earliest = $now->modify('+' . $rule['booking_notice_value'] . ' minutes');
        }

        return [
            'earliest'     => $earliest->setTimezone($utc),
            'latestBefore' => $today->modify('+' . ($rule['booking_horizon_days'] + 1) . ' days')->setTimezone($utc),
        ];
    }

    /**
     * Whether a customer may book $start now under $rule
     * @param array             $rule As effective() gives it
     * @param DateTimeInterface $start
     * @param DateTimeZone      $zone The store's
     * @param DateTimeInterface $now
     * @return bool
     */
    public static function allows(array $rule, DateTimeInterface $start, DateTimeZone $zone, DateTimeInterface $now): bool
    {
        $window = self::window($rule, $zone, $now);

        return $now <= self::closesAt($rule, $start, $zone) && $start < $window['latestBefore'];
    }

    private static function int(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && preg_match('/^\d+$/', $value) ? (int) $value : null;
    }
}
