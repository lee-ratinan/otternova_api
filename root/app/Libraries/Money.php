<?php

namespace App\Libraries;

use InvalidArgumentException;

/**
 * Amounts of the billing tables (DECIMAL(19,2)) as whole cents, so that no sum or discount goes through a float
 */
class Money
{
    /**
     * @param string $amount "299", "299.5" or "299.50"
     * @return int 29950
     * @throws InvalidArgumentException When it is not a non-negative amount of up to 2 decimal places
     */
    public static function toCents(string $amount): int
    {
        if (! preg_match('/^(\d{1,17})(?:\.(\d{1,2}))?$/', $amount, $parts)) {
            throw new InvalidArgumentException('Not an amount: ' . $amount);
        }

        return (int) $parts[1] * 100 + (int) str_pad($parts[2] ?? '', 2, '0');
    }

    /**
     * @param int $cents 29950
     * @return string "299.50", as DECIMAL(19,2) gives it back
     */
    public static function fromCents(int $cents): string
    {
        return intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * An amount as a driver gives it back (MySQL "299.00", SQLite 299 or 269.1), as the API sends it: "299.00"
     * @param mixed $amount
     * @return string|null Null stays null
     */
    public static function format(mixed $amount): ?string
    {
        return $amount === null ? null : self::normalize(is_float($amount) ? sprintf('%.2F', $amount) : $amount);
    }

    /**
     * Whether an amount as the driver gives it back is zero
     * @param mixed $amount
     * @return bool
     */
    public static function isZero(mixed $amount): bool
    {
        return self::format($amount) === '0.00';
    }

    /**
     * An amount from the body, written as the database gives it back ("299" → "299.00"), or null when it is not one
     * @param mixed $value
     * @return string|null
     */
    public static function normalize(mixed $value): ?string
    {
        $value = is_string($value) || is_int($value) || is_float($value) ? trim((string) $value) : '';
        try {
            return self::fromCents(self::toCents($value));
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
