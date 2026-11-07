<?php

use App\Libraries\AmountInWords;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class AmountInWordsTest extends CIUnitTestCase
{
    public function testThaiBaht(): void
    {
        foreach ([
            '0.00'         => 'ศูนย์บาทถ้วน',
            '1.00'         => 'หนึ่งบาทถ้วน',
            '11.00'        => 'สิบเอ็ดบาทถ้วน',
            '21.00'        => 'ยี่สิบเอ็ดบาทถ้วน',
            '101.00'       => 'หนึ่งร้อยเอ็ดบาทถ้วน',
            '299.00'       => 'สองร้อยเก้าสิบเก้าบาทถ้วน',
            '3190.00'      => 'สามพันหนึ่งร้อยเก้าสิบบาทถ้วน',
            '3094.30'      => 'สามพันเก้าสิบสี่บาทสามสิบสตางค์',
            '0.25'         => 'ยี่สิบห้าสตางค์',
            '1000000.00'   => 'หนึ่งล้านบาทถ้วน',
            '1000001.00'   => 'หนึ่งล้านเอ็ดบาทถ้วน',
            '21500000.00'  => 'ยี่สิบเอ็ดล้านห้าแสนบาทถ้วน',
        ] as $amount => $words) {
            $this->assertSame($words, AmountInWords::thaiBaht((string) $amount), (string) $amount);
        }
    }

    public function testEnglishBaht(): void
    {
        foreach ([
            '0.00'       => 'Zero baht only',
            '299.00'     => 'Two hundred and ninety-nine baht only',
            '3190.00'    => 'Three thousand one hundred and ninety baht only',
            '1005.00'    => 'One thousand and five baht only',
            '3094.30'    => 'Three thousand and ninety-four baht and thirty satang',
            '0.25'       => 'Twenty-five satang',
            '2000000.00' => 'Two million baht only',
        ] as $amount => $words) {
            $this->assertSame($words, AmountInWords::englishBaht((string) $amount), (string) $amount);
        }
    }
}
