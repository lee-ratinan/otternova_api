<?php

namespace App\Libraries;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * A business's cancellation policy (see the AddCancellationPolicy migration): until when a customer may cancel a
 * booking for free (cancel_notice_unit, _value and _time, shaped like the booking notice, see BookingWindow), whether
 * they may still cancel online after that (late_cancel_allowed), and what cancelling late or not coming costs
 * (late_cancel_fee_*, no_show_fee_*): a percentage of the booked service line, or a fixed amount, never more than the
 * line.
 */
final class CancelPolicy
{
    public const UNIT_PERCENTAGE = 'P';
    public const UNIT_FIXED      = 'F';

    public const LATE_CANCEL = 'late_cancel';
    public const NO_SHOW     = 'no_show';

    public const FIELDS = ['cancel_notice_unit', 'cancel_notice_value', 'cancel_notice_time', 'late_cancel_allowed',
        'late_cancel_fee_value', 'late_cancel_fee_unit', 'no_show_fee_value', 'no_show_fee_unit'];

    /** DECIMAL(19,2) columns, which the drivers give back differently ("10.00", 10) */
    public const DECIMAL_FIELDS = ['late_cancel_fee_value', 'no_show_fee_value'];

    /** What a business left without a value gets: free until 24 hours before, no fee after */
    public const DEFAULTS = [
        'cancel_notice_unit'    => BookingWindow::NOTICE_MINUTES,
        'cancel_notice_value'   => 1440,
        'cancel_notice_time'    => null,
        'late_cancel_allowed'   => 'Y',
        'late_cancel_fee_value' => '0.00',
        'late_cancel_fee_unit'  => self::UNIT_PERCENTAGE,
        'no_show_fee_value'     => '0.00',
        'no_show_fee_unit'      => self::UNIT_PERCENTAGE,
    ];

    /** The highest percentage a fee may be */
    public const MAX_PERCENTAGE = '100.00';

    private function __construct()
    {
    }

    /**
     * Errors for a policy whose columns, each of the right kind already (fees as "10.00"), do not fit together: the
     * deadline wrong for its unit (see BookingWindow::noticeErrors()), a percentage fee over MAX_PERCENTAGE
     * @param array $policy The FIELDS
     * @return array field => error
     */
    public static function errors(array $policy): array
    {
        $errors = BookingWindow::noticeErrors($policy, 'cancel');
        foreach ([self::LATE_CANCEL, self::NO_SHOW] as $kind) {
            if (($policy[$kind . '_fee_unit'] ?? null) === self::UNIT_PERCENTAGE
                && Money::toCents((string) ($policy[$kind . '_fee_value'] ?? '0.00')) > Money::toCents(self::MAX_PERCENTAGE)) {
                $errors[$kind . '_fee_value'] = lang('BusinessMaster.percentageTooHigh', [self::MAX_PERCENTAGE]);
            }
        }

        return $errors;
    }

    /**
     * The last moment to cancel a booking of $start for free: the deadline before the start, never after it
     * @param array             $policy The FIELDS
     * @param DateTimeInterface $start  A group session's first slot's
     * @param DateTimeZone      $zone   The store's
     * @return DateTimeImmutable In UTC
     */
    public static function freeUntil(array $policy, DateTimeInterface $start, DateTimeZone $zone): DateTimeImmutable
    {
        return BookingWindow::closesAt([
            'booking_notice_unit'  => $policy['cancel_notice_unit'] ?? self::DEFAULTS['cancel_notice_unit'],
            'booking_notice_value' => (int) ($policy['cancel_notice_value'] ?? self::DEFAULTS['cancel_notice_value']),
            'booking_notice_time'  => BookingWindow::normalizeTime($policy['cancel_notice_time'] ?? null),
        ], $start, $zone);
    }

    /**
     * What cancelling late or not coming costs on a service line: its percentage of the line, or its fixed amount,
     * never more than the line
     * @param array  $policy The FIELDS
     * @param string $kind   LATE_CANCEL or NO_SHOW
     * @param string $line   The booked service line, its service charge included and its tip not, as "800.00"
     * @return string As "400.00"
     */
    public static function fee(array $policy, string $kind, string $line): string
    {
        $lineCents = Money::toCents($line);
        $value     = Money::toCents(Money::format($policy[$kind . '_fee_value'] ?? '0.00') ?? '0.00');
        $cents     = ($policy[$kind . '_fee_unit'] ?? self::UNIT_PERCENTAGE) === self::UNIT_PERCENTAGE
            ? intdiv($lineCents * $value + 5000, 10000) // value is the percentage in cents: 5000 for 50.00%
            : $value;

        return Money::fromCents(min($cents, $lineCents));
    }
}
