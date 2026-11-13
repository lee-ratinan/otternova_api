<?php

namespace App\Libraries;

/**
 * The text of a PromptPay QR (EMVCo merchant-presented, Thai QR Payment) to a PromptPay ID with the amount in it, for
 * a customer of a business to scan with their banking app; whoever shows it draws the QR from the text.
 * - a mobile number 0812345678 is sent as 0066812345678 (tag 01), a national or tax ID of 13 digits as it is (02), an
 *   e-wallet ID of 15 digits as it is (03)
 * - the amount makes the QR dynamic (point of initiation 12), in THB (764)
 */
final class PromptPay
{
    private const AID = 'A000000677010111';

    /**
     * @param string $promptPayId 10, 13 or 15 digits, spaces and dashes allowed
     * @param string $amount      123.45
     * @return string|null null when the ID is none of those
     */
    public static function payload(string $promptPayId, string $amount): ?string
    {
        $id = preg_replace('/[\s-]/', '', $promptPayId);
        if (preg_match('/^0\d{9}$/', $id)) {
            $account = self::field('01', '0066' . substr($id, 1));
        } elseif (preg_match('/^\d{13}$/', $id)) {
            $account = self::field('02', $id);
        } elseif (preg_match('/^\d{15}$/', $id)) {
            $account = self::field('03', $id);
        } else {
            return null;
        }

        $text = self::field('00', '01')
            . self::field('01', '12')
            . self::field('29', self::field('00', self::AID) . $account)
            . self::field('53', '764')
            . self::field('54', number_format((float) $amount, 2, '.', ''))
            . self::field('58', 'TH')
            . '6304';

        return $text . self::crc($text);
    }

    private static function field(string $tag, string $value): string
    {
        return $tag . str_pad((string) strlen($value), 2, '0', STR_PAD_LEFT) . $value;
    }

    /** CRC-16/CCITT-FALSE, as four upper-case hex digits */
    private static function crc(string $text): string
    {
        $crc = 0xFFFF;
        for ($i = 0, $n = strlen($text); $i < $n; $i++) {
            $crc ^= ord($text[$i]) << 8;
            for ($bit = 0; $bit < 8; $bit++) {
                $crc = ($crc & 0x8000) ? (($crc << 1) ^ 0x1021) : ($crc << 1);
                $crc &= 0xFFFF;
            }
        }

        return sprintf('%04X', $crc);
    }
}
