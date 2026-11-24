<?php

namespace App\Libraries;

use InvalidArgumentException;
use OverflowException;

/**
 * Exact arithmetic on the ledger's DECIMAL amounts without bcmath, which the server's PHP does not have:
 * amounts are added as whole units of 0.0001 in a 64-bit int (up to 922 trillion, far beyond any of the ledger's
 * totals) and multiplied digit by digit.
 */
final class Decimal
{
    /** Decimal places of the ledger's amounts */
    public const SCALE = 4;

    private const UNIT = 10_000;

    /**
     * "1234.5" or "-0.25" as whole units of 0.0001; more than 4 decimal places are cut off
     * @param string|int|float|null $amount
     * @return int
     * @throws OverflowException Beyond what a 64-bit int holds
     */
    public static function toUnits(string|int|float|null $amount): int
    {
        [$negative, $whole, $fraction] = self::parts((string) ($amount ?? '0'));
        $whole = ltrim($whole, '0') ?: '0';
        if (strlen($whole) > 15 || (int) $whole > intdiv(PHP_INT_MAX, self::UNIT) - 1) {
            throw new OverflowException('Too large for the ledger: ' . $amount);
        }
        $units = (int) $whole * self::UNIT + (int) str_pad(substr($fraction, 0, self::SCALE), self::SCALE, '0');

        return $negative ? -$units : $units;
    }

    /**
     * Whole units of 0.0001 as "1234.5000"
     * @param int $units
     * @return string
     */
    public static function fromUnits(int $units): string
    {
        $sign  = $units < 0 ? '-' : '';
        $units = abs($units);

        return $sign . intdiv($units, self::UNIT) . '.' . str_pad((string) ($units % self::UNIT), self::SCALE, '0', STR_PAD_LEFT);
    }

    /**
     * $a × $b rounded half away from zero to $scale decimal places, both non-negative decimal strings
     * @param string $a
     * @param string $b
     * @param int    $scale
     * @return string
     */
    public static function multiply(string $a, string $b, int $scale = self::SCALE): string
    {
        [$negA, $wholeA, $fractionA] = self::parts($a);
        [$negB, $wholeB, $fractionB] = self::parts($b);
        $digitsA = ltrim($wholeA . $fractionA, '0') ?: '0';
        $digitsB = ltrim($wholeB . $fractionB, '0') ?: '0';
        $places  = strlen($fractionA) + strlen($fractionB);

        // schoolbook multiplication, least significant digit first
        $product = array_fill(0, strlen($digitsA) + strlen($digitsB), 0);
        foreach (array_reverse(str_split($digitsA)) as $i => $x) {
            $carry = 0;
            foreach (array_reverse(str_split($digitsB)) as $j => $y) {
                $sum             = $product[$i + $j] + (int) $x * (int) $y + $carry;
                $product[$i + $j] = $sum % 10;
                $carry           = intdiv($sum, 10);
            }
            $product[$i + strlen($digitsB)] += $carry;
        }
        $digits = ltrim(implode('', array_reverse($product)), '0');

        // round away the places beyond $scale
        $drop = $places - $scale;
        if ($drop > 0) {
            $digits    = str_pad($digits, $drop + 1, '0', STR_PAD_LEFT);
            $roundUp   = $digits[strlen($digits) - $drop] >= '5';
            $digits    = substr($digits, 0, -$drop);
            if ($roundUp) {
                $digits = self::increment($digits);
            }
        } else {
            $digits .= str_repeat('0', -$drop);
        }
        $digits = str_pad(ltrim($digits, '0'), $scale + 1, '0', STR_PAD_LEFT);
        $whole  = ltrim(substr($digits, 0, strlen($digits) - $scale), '0') ?: '0';
        $result = $scale > 0 ? $whole . '.' . substr($digits, -$scale) : $whole;

        return ($negA xor $negB) && trim($result, '0.') !== '' ? '-' . $result : $result;
    }

    /**
     * @param string $amount
     * @return array{0: bool, 1: string, 2: string} negative, whole digits, fraction digits
     * @throws InvalidArgumentException When it is not a decimal number
     */
    private static function parts(string $amount): array
    {
        $amount = trim($amount);
        if (! preg_match('/^(-?)(\d*)(?:\.(\d*))?$/', $amount, $match) || ($match[2] === '' && ($match[3] ?? '') === '')) {
            throw new InvalidArgumentException('Not a decimal number: ' . $amount);
        }

        return [$match[1] === '-', $match[2] === '' ? '0' : $match[2], $match[3] ?? ''];
    }

    /**
     * A digit string plus one
     * @param string $digits
     * @return string
     */
    private static function increment(string $digits): string
    {
        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            if ($digits[$i] !== '9') {
                return substr($digits, 0, $i) . ((int) $digits[$i] + 1) . str_repeat('0', strlen($digits) - $i - 1);
            }
        }

        return '1' . str_repeat('0', strlen($digits));
    }
}
