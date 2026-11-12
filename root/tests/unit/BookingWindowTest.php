<?php

use App\Libraries\BookingWindow;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class BookingWindowTest extends CIUnitTestCase
{
    private DateTimeZone $bangkok;
    private DateTimeZone $utc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bangkok = new DateTimeZone('Asia/Bangkok'); // UTC+7, no daylight saving
        $this->utc     = new DateTimeZone('UTC');
    }

    public function testVariantRuleOverridesTheBusinessOnlyWhenItHasOne(): void
    {
        $business = ['booking_horizon_days' => '3', 'booking_notice_unit' => 'M', 'booking_notice_value' => '60', 'booking_notice_time' => null];
        $none     = ['booking_horizon_days' => null, 'booking_notice_unit' => null, 'booking_notice_value' => null, 'booking_notice_time' => null];
        $own      = ['booking_horizon_days' => '30', 'booking_notice_unit' => 'D', 'booking_notice_value' => '1', 'booking_notice_time' => '18:00:00'];

        $this->assertSame(['booking_horizon_days' => 3, 'booking_notice_unit' => 'M', 'booking_notice_value' => 60, 'booking_notice_time' => null],
            BookingWindow::effective($business, $none));
        $this->assertSame(['booking_horizon_days' => 30, 'booking_notice_unit' => 'D', 'booking_notice_value' => 1, 'booking_notice_time' => '18:00:00'],
            BookingWindow::effective($business, $own));
        $this->assertSame(3, BookingWindow::effective([])['booking_horizon_days']); // the defaults
    }

    public function testMinutesNotice(): void
    {
        $rule   = $this->rule(3, 'M', 60);
        $window = BookingWindow::window($rule, $this->bangkok, $this->local('2026-11-12 10:00'));
        $this->assertSame(['2026-11-12 11:00', '2026-11-16 00:00'], $this->shown($window)); // to the end of the 15th

        $this->assertSame('2026-11-14 08:45', $this->shownAt(BookingWindow::closesAt($rule, $this->local('2026-11-14 09:45'), $this->bangkok)));
    }

    public function testDaysNoticeByATime(): void
    {
        // no same-day booking: by the end of the day before
        $noSameDay = $this->rule(30, 'D', 1, '23:59:00');
        $this->assertSame('2026-11-13 00:00', $this->shown(BookingWindow::window($noSameDay, $this->bangkok, $this->local('2026-11-12 10:00')))[0]);
        $this->assertSame('2026-11-14 00:00', $this->shown(BookingWindow::window($noSameDay, $this->bangkok, $this->local('2026-11-12 23:59:30')))[0]);

        // by 6pm the day before
        $byEvening = $this->rule(30, 'D', 1, '18:00:00');
        $this->assertSame('2026-11-13 18:00', $this->shownAt(BookingWindow::closesAt($byEvening, $this->local('2026-11-14 09:00'), $this->bangkok)));

        // the same day by 10am, but never after the start
        $sameMorning = $this->rule(30, 'D', 0, '10:00:00');
        $this->assertSame('2026-11-14 09:00', $this->shownAt(BookingWindow::closesAt($sameMorning, $this->local('2026-11-14 09:00'), $this->bangkok)));
        $this->assertSame('2026-11-14 10:00', $this->shownAt(BookingWindow::closesAt($sameMorning, $this->local('2026-11-14 15:00'), $this->bangkok)));
    }

    public function testAllowsAgreesWithTheWindow(): void
    {
        $rules = [$this->rule(3, 'M', 0), $this->rule(3, 'M', 90), $this->rule(5, 'D', 1, '23:59:00'), $this->rule(5, 'D', 1, '18:00:00'),
            $this->rule(5, 'D', 0, '10:00:00'), $this->rule(5, 'D', 2, '12:00:00')];
        foreach (['2026-11-12 08:10', '2026-11-12 12:00', '2026-11-12 19:30', '2026-11-12 23:59:30'] as $at) {
            $now = $this->local($at);
            foreach ($rules as $rule) {
                $window = BookingWindow::window($rule, $this->bangkok, $now);
                for ($start = $this->local('2026-11-11 00:00'); $start < $this->local('2026-11-19 00:00'); $start = $start->modify('+15 minutes')) {
                    $this->assertSame($start >= $window['earliest'] && $start < $window['latestBefore'],
                        BookingWindow::allows($rule, $start, $this->bangkok, $now),
                        json_encode($rule) . ' at ' . $at . ' for ' . $start->setTimezone($this->bangkok)->format('Y-m-d H:i'));
                }
            }
        }
    }

    public function testErrors(): void
    {
        $this->assertSame([], BookingWindow::errors($this->rule(3, 'M', 60)));
        $this->assertSame([], BookingWindow::errors($this->rule(3, 'D', 3, '12:00:00')));
        $this->assertSame([], BookingWindow::errors(['booking_horizon_days' => '3', 'booking_notice_unit' => 'M', 'booking_notice_value' => '4320', 'booking_notice_time' => null]));
        $this->assertSame(['booking_notice_value'], array_keys(BookingWindow::errors($this->rule(3, 'M', 4321))));
        $this->assertSame(['booking_notice_value'], array_keys(BookingWindow::errors($this->rule(3, 'D', 4, '12:00:00'))));
        $this->assertSame(['booking_notice_time'], array_keys(BookingWindow::errors($this->rule(3, 'D', 1))));
        $this->assertSame(['booking_notice_time'], array_keys(BookingWindow::errors($this->rule(3, 'M', 1, '12:00:00'))));
        $this->assertSame(['booking_horizon_days', 'booking_notice_unit'], array_keys(BookingWindow::errors(['booking_horizon_days' => '1.5', 'booking_notice_unit' => 'X'])));
        $this->assertSame('18:00:00', BookingWindow::normalizeTime(' 18:00 '));
        $this->assertSame('6pm', BookingWindow::normalizeTime('6pm'));
    }

    private function rule(int $horizon, string $unit, int $notice, ?string $time = null): array
    {
        return ['booking_horizon_days' => $horizon, 'booking_notice_unit' => $unit, 'booking_notice_value' => $notice, 'booking_notice_time' => $time];
    }

    private function local(string $at): DateTimeImmutable
    {
        return new DateTimeImmutable($at, $this->bangkok);
    }

    private function shownAt(DateTimeImmutable $at): string
    {
        $this->assertSame('UTC', $at->getTimezone()->getName());

        return $at->setTimezone($this->bangkok)->format('Y-m-d H:i');
    }

    private function shown(array $window): array
    {
        return [$this->shownAt($window['earliest']), $this->shownAt($window['latestBefore'])];
    }
}
