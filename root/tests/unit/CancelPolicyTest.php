<?php

use App\Libraries\CancelPolicy;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class CancelPolicyTest extends CIUnitTestCase
{
    public function testFreeUntilTheDeadlineBeforeTheStart(): void
    {
        $bangkok = new DateTimeZone('Asia/Bangkok');
        $start   = new DateTimeImmutable('2026-11-14 09:00', $bangkok);
        $shown   = static fn (DateTimeImmutable $at): string => $at->setTimezone($bangkok)->format('Y-m-d H:i');

        $this->assertSame('2026-11-13 09:00', $shown(CancelPolicy::freeUntil(CancelPolicy::DEFAULTS, $start, $bangkok))); // 24 hours
        $this->assertSame('2026-11-13 18:00', $shown(CancelPolicy::freeUntil(['cancel_notice_unit' => 'D', 'cancel_notice_value' => '1',
            'cancel_notice_time' => '18:00:00'], $start, $bangkok)));
        $this->assertSame('2026-11-14 09:00', $shown(CancelPolicy::freeUntil(['cancel_notice_unit' => 'M', 'cancel_notice_value' => 0], $start, $bangkok)));
        $this->assertSame('UTC', CancelPolicy::freeUntil(CancelPolicy::DEFAULTS, $start, $bangkok)->getTimezone()->getName());
    }

    public function testFeeIsAShareOrAnAmountNeverMoreThanTheLine(): void
    {
        $policy = ['late_cancel_fee_value' => '50.00', 'late_cancel_fee_unit' => 'P', 'no_show_fee_value' => '1000', 'no_show_fee_unit' => 'F'];
        $this->assertSame('400.00', CancelPolicy::fee($policy, CancelPolicy::LATE_CANCEL, '800.00'));
        $this->assertSame('0.50', CancelPolicy::fee($policy, CancelPolicy::LATE_CANCEL, '0.99')); // rounded half up
        $this->assertSame('800.00', CancelPolicy::fee($policy, CancelPolicy::NO_SHOW, '800.00')); // capped at the line
        $this->assertSame('0.00', CancelPolicy::fee(CancelPolicy::DEFAULTS, CancelPolicy::NO_SHOW, '800.00'));
        $this->assertSame('800.00', CancelPolicy::fee(['no_show_fee_value' => 100, 'no_show_fee_unit' => 'P'], CancelPolicy::NO_SHOW, '800.00')); // non-refundable
    }

    public function testErrors(): void
    {
        $this->assertSame([], CancelPolicy::errors(CancelPolicy::DEFAULTS));
        $this->assertSame([], CancelPolicy::errors(['cancel_notice_unit' => 'D', 'cancel_notice_value' => '2', 'cancel_notice_time' => '12:00:00',
            'late_cancel_fee_value' => '100.00', 'late_cancel_fee_unit' => 'P', 'no_show_fee_value' => '5000.00', 'no_show_fee_unit' => 'F']));
        $this->assertSame(['cancel_notice_time', 'late_cancel_fee_value', 'no_show_fee_value'], array_keys(CancelPolicy::errors(['cancel_notice_unit' => 'D',
            'cancel_notice_value' => '1', 'late_cancel_fee_value' => '100.01', 'late_cancel_fee_unit' => 'P', 'no_show_fee_value' => '150.00', 'no_show_fee_unit' => 'P'])));
        $this->assertSame(['cancel_notice_value'], array_keys(CancelPolicy::errors(['cancel_notice_value' => '366', 'cancel_notice_unit' => 'D', 'cancel_notice_time' => '12:00:00'] + CancelPolicy::DEFAULTS)));
    }
}
