<?php

namespace App\Libraries;

use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * The order numbers of a business: its prefix, then a date part, then "-", then its running number, as
 * IN2611-0001. How they look is the business's (business_master.order_*); the running numbers are counted in
 * business_order_sequence, by prefix and reset period, in the transaction that places the order
 * (MartOrderModel::place()), so an order that is not placed takes none.
 *
 * - order_prefix:    0 to 4 letters A-Z
 * - order_date_part: N none, Y year (26), M year and month (2611), D year, month and day (261113)
 * - order_reset:     N never, Y yearly, M monthly, D daily
 * - order_digits:    3 to 6, the least width of the running number, zero-padded; a larger number is longer
 * The reset may not be more often than the date part shows (daily needs the day, monthly the month, yearly any
 * date part), or the same number would be given twice. Days are those of the business's clock (timezoneOf()).
 */
final class OrderNumber
{
    public const FIELDS     = ['order_prefix', 'order_date_part', 'order_reset', 'order_digits'];
    public const PARTS      = ['N', 'Y', 'M', 'D'];
    public const DIGITS_MIN = 3;
    public const DIGITS_MAX = 6;
    public const PREFIX_MAX = 4;
    public const DEFAULTS   = ['order_prefix' => 'IN', 'order_date_part' => 'M', 'order_reset' => 'M', 'order_digits' => 4];

    public const TABLE = 'business_order_sequence';

    /** How fine each date part and reset is */
    private const RANK = ['N' => 0, 'Y' => 1, 'M' => 2, 'D' => 3];

    /**
     * The checked settings, or why not
     * @param array $settings FIELDS, as sent
     * @return array{0: array, 1: array<string, string>} [settings, errors by field: invalid | too_often]
     */
    public static function check(array $settings): array
    {
        $errors = [];
        $prefix = $settings['order_prefix'] ?? '';
        if (! is_string($prefix) || ! preg_match('/^[A-Z]{0,' . self::PREFIX_MAX . '}$/', strtoupper(trim($prefix)))) {
            $errors['order_prefix'] = 'invalid';
            $prefix                 = '';
        }
        $part  = $settings['order_date_part'] ?? null;
        $reset = $settings['order_reset'] ?? null;
        foreach (['order_date_part' => $part, 'order_reset' => $reset] as $field => $value) {
            if (! in_array($value, self::PARTS, true)) {
                $errors[$field] = 'invalid';
            }
        }
        $digits = $settings['order_digits'] ?? null;
        $digits = is_int($digits) || (is_string($digits) && ctype_digit($digits)) ? (int) $digits : null;
        if ($digits === null || $digits < self::DIGITS_MIN || $digits > self::DIGITS_MAX) {
            $errors['order_digits'] = 'invalid';
        }
        if (! isset($errors['order_date_part']) && ! isset($errors['order_reset']) && ! self::allowed($part, $reset)) {
            $errors['order_reset'] = 'too_often';
        }

        return [['order_prefix' => strtoupper(trim((string) $prefix)), 'order_date_part' => $part, 'order_reset' => $reset, 'order_digits' => $digits], $errors];
    }

    /** Whether the date part shows enough for the reset, so no number comes twice */
    public static function allowed(string $datePart, string $reset): bool
    {
        return $reset === 'N' || self::RANK[$datePart] >= self::RANK[$reset];
    }

    /** The order number of running number $n at $local (the business's clock) */
    public static function format(array $settings, DateTimeImmutable $local, int $n): string
    {
        return $settings['order_prefix'] . self::datePart((string) $settings['order_date_part'], $local) . '-'
            . str_pad((string) $n, (int) $settings['order_digits'], '0', STR_PAD_LEFT);
    }

    /** The reset period $local is in, as business_order_sequence.period keeps it: '' never, 2026, 202611, 20261113 */
    public static function period(string $reset, DateTimeImmutable $local): string
    {
        return match ($reset) {
            'Y'     => $local->format('Y'),
            'M'     => $local->format('Ym'),
            'D'     => $local->format('Ymd'),
            default => '',
        };
    }

    private static function datePart(string $part, DateTimeImmutable $local): string
    {
        return match ($part) {
            'Y'     => $local->format('y'),
            'M'     => $local->format('ym'),
            'D'     => $local->format('ymd'),
            default => '',
        };
    }

    /**
     * The business's clock: that of its first physical store, else its country's, else UTC
     * @param array $business business_master, with id and country_code
     */
    public static function timezoneOf(BaseConnection $db, array $business): DateTimeZone
    {
        $row = $db->table('business_store')->select('store_timezone')->where('business_id', $business['id'])
            ->where('store_type', 'P')->orderBy('id')->get(1)->getRowArray()
            ?? $db->table('business_store')->select('store_timezone')->where('business_id', $business['id'])->orderBy('id')->get(1)->getRowArray();
        helper('list');
        foreach ([$row['store_timezone'] ?? null, array_key_first(listSupportedTimezones((string) ($business['country_code'] ?? '')))] as $zone) {
            if (is_string($zone) && $zone !== '') {
                try {
                    return new DateTimeZone($zone);
                } catch (Throwable) {
                    // not a timezone: the next one
                }
            }
        }

        return new DateTimeZone('UTC');
    }

    /**
     * The number the business's next order would get now, without taking it: for its settings page
     * @param array $business business_master, with id, country_code and FIELDS
     */
    public static function upcoming(BaseConnection $db, array $business, DateTimeImmutable $now): string
    {
        $settings = array_intersect_key($business, self::DEFAULTS) + self::DEFAULTS;
        $local    = $now->setTimezone(self::timezoneOf($db, $business));
        $row      = $db->table(self::TABLE)->select('last_number')->where(['business_id' => (int) $business['id'],
            'prefix' => (string) $settings['order_prefix'], 'period' => self::period((string) $settings['order_reset'], $local)])->get()->getRow();

        return self::format($settings, $local, ($row === null ? 0 : (int) $row->last_number) + 1);
    }

    /**
     * The next order number of the business, counted in business_order_sequence; to be called in the transaction that
     * writes the order, which holds the counter's row until it ends
     * @param array             $business business_master, with id, country_code and FIELDS
     * @param DateTimeImmutable $now
     */
    public static function next(BaseConnection $db, array $business, DateTimeImmutable $now): string
    {
        $settings = array_intersect_key($business, self::DEFAULTS) + self::DEFAULTS;
        $local    = $now->setTimezone(self::timezoneOf($db, $business));
        $key      = [(int) $business['id'], (string) $settings['order_prefix'], self::period((string) $settings['order_reset'], $local)];
        $table    = $db->escapeIdentifiers($db->prefixTable(self::TABLE));
        if ($db->DBDriver === 'MySQLi') {
            $db->query('INSERT INTO ' . $table . ' (business_id, prefix, period, last_number) VALUES (?, ?, ?, 1)'
                . ' ON DUPLICATE KEY UPDATE last_number = last_number + 1', $key);
        } else {
            $db->query('INSERT INTO ' . $table . ' (business_id, prefix, period, last_number) VALUES (?, ?, ?, 1)'
                . ' ON CONFLICT (business_id, prefix, period) DO UPDATE SET last_number = last_number + 1', $key);
        }
        $n = (int) $db->table(self::TABLE)->select('last_number')
            ->where(['business_id' => $key[0], 'prefix' => $key[1], 'period' => $key[2]])->get()->getRow()->last_number;

        return self::format($settings, $local, $n);
    }
}
