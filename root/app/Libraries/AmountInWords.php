<?php

namespace App\Libraries;

/**
 * An amount of baht written out, as on Thai invoices and receipts: in Thai (สามพันหนึ่งร้อยเก้าสิบบาทถ้วน) and in
 * English (Three thousand one hundred and ninety baht only)
 */
final class AmountInWords
{
    private const THAI_DIGITS = ['ศูนย์', 'หนึ่ง', 'สอง', 'สาม', 'สี่', 'ห้า', 'หก', 'เจ็ด', 'แปด', 'เก้า'];
    private const THAI_PLACES = ['', 'สิบ', 'ร้อย', 'พัน', 'หมื่น', 'แสน'];

    private const ENGLISH_ONES = ['', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven',
        'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen'];
    private const ENGLISH_TENS   = ['', '', 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety'];
    private const ENGLISH_SCALES = ['', 'thousand', 'million', 'billion', 'trillion', 'quadrillion'];

    /**
     * @param mixed $amount Up to 2 decimal places, not negative
     * @return string สามพันหนึ่งร้อยเก้าสิบบาทถ้วน, ห้าสิบบาทยี่สิบห้าสตางค์
     */
    public static function thaiBaht(mixed $amount): string
    {
        [$baht, $satang] = self::split($amount);
        if ($baht === '0' && $satang === 0) {
            return 'ศูนย์บาทถ้วน';
        }
        $words = $baht === '0' ? '' : self::thaiNumber($baht) . 'บาท';

        return $words . ($satang === 0 ? 'ถ้วน' : self::thaiNumber((string) $satang) . 'สตางค์');
    }

    /**
     * @param mixed $amount Up to 2 decimal places, not negative
     * @return string Three thousand one hundred and ninety baht only, Fifty baht and twenty-five satang
     */
    public static function englishBaht(mixed $amount): string
    {
        [$baht, $satang] = self::split($amount);
        if ($satang === 0) {
            $words = self::englishNumber($baht) . ' baht only';
        } elseif ($baht === '0') {
            $words = self::englishNumber((string) $satang) . ' satang';
        } else {
            $words = self::englishNumber($baht) . ' baht and ' . self::englishNumber((string) $satang) . ' satang';
        }

        return ucfirst($words);
    }

    /**
     * @param mixed $amount
     * @return array{string, int} The whole baht as digits, and the satang
     */
    private static function split(mixed $amount): array
    {
        $cents = Money::toCents(Money::format($amount ?? '0') ?? '0.00');
        $cents = max(0, $cents);
        $baht  = intdiv($cents, 100);

        return [(string) $baht, $cents % 100];
    }

    /**
     * A whole number in Thai: in groups of six digits joined by ล้าน; a last 1 after anything else is เอ็ด
     * @param string $digits
     * @return string
     */
    private static function thaiNumber(string $digits): string
    {
        $digits = ltrim($digits, '0');
        if ($digits === '') {
            return self::THAI_DIGITS[0];
        }
        if (strlen($digits) > 6) {
            $high = substr($digits, 0, -6);
            $low  = ltrim(substr($digits, -6), '0');

            return self::thaiNumber($high) . 'ล้าน' . ($low === '' ? '' : self::thaiGroup($low, true));
        }

        return self::thaiGroup($digits, false);
    }

    /**
     * Up to six digits, with no leading zeros
     * @param string $digits
     * @param bool   $afterMillion Whether ล้าน comes before it, so that a lone 1 is เอ็ด
     * @return string
     */
    private static function thaiGroup(string $digits, bool $afterMillion): string
    {
        $words  = '';
        $length = strlen($digits);
        for ($i = 0; $i < $length; $i++) {
            $digit = (int) $digits[$i];
            $place = $length - $i - 1;
            if ($digit === 0) {
                continue;
            }
            if ($place === 1) {
                $words .= ($digit === 1 ? '' : ($digit === 2 ? 'ยี่' : self::THAI_DIGITS[$digit])) . 'สิบ';
            } elseif ($place === 0 && $digit === 1 && ($length > 1 || $afterMillion)) {
                $words .= 'เอ็ด';
            } else {
                $words .= self::THAI_DIGITS[$digit] . self::THAI_PLACES[$place];
            }
        }

        return $words;
    }

    /**
     * A whole number in English, British style: one hundred and ninety
     * @param string $digits
     * @return string
     */
    private static function englishNumber(string $digits): string
    {
        $digits = ltrim($digits, '0');
        if ($digits === '') {
            return 'zero';
        }
        $groups = array_reverse(str_split(str_pad($digits, (int) ceil(strlen($digits) / 3) * 3, '0', STR_PAD_LEFT), 3));
        $words  = [];
        foreach ($groups as $scale => $group) {
            $value = (int) $group;
            if ($value === 0) {
                continue;
            }
            $text = self::englishHundreds($value);
            // one thousand and five: "and" before a last group under a hundred
            if ($scale === 0 && $value < 100 && count($groups) > 1) {
                $text = 'and ' . $text;
            }
            array_unshift($words, $text . (self::ENGLISH_SCALES[$scale] === '' ? '' : ' ' . self::ENGLISH_SCALES[$scale]));
        }

        return implode(' ', $words);
    }

    /**
     * @param int $value 1-999
     * @return string
     */
    private static function englishHundreds(int $value): string
    {
        $hundreds = intdiv($value, 100);
        $rest     = $value % 100;
        $words    = $hundreds > 0 ? self::ENGLISH_ONES[$hundreds] . ' hundred' : '';
        if ($rest === 0) {
            return $words;
        }
        $restWords = $rest < 20 ? self::ENGLISH_ONES[$rest]
            : self::ENGLISH_TENS[intdiv($rest, 10)] . ($rest % 10 === 0 ? '' : '-' . self::ENGLISH_ONES[$rest % 10]);

        return $words === '' ? $restWords : $words . ' and ' . $restWords;
    }
}
