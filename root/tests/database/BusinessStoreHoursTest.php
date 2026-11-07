<?php

use App\Models\BusinessStoreHoursModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * BusinessStoreHoursModel: the hours of a business day, overnight openings, exceptions and isOpenAt()
 *
 * @internal
 */
final class BusinessStoreHoursTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace = 'App'; // the app's own migrations, the schema of the real database

    private BusinessStoreHoursModel $hours;
    private int $storeId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hours = new BusinessStoreHoursModel();
        $this->db->table('business_type')->insert(['type_code' => 'school', 'public_id' => 'ty01']);
        $this->db->table('business_master')->insert(['business_type_id' => $this->db->insertID(), 'business_name' => 'Otter Music', 'business_slug' => 'otter-music',
            'contact_email_address' => 'hello@ottermusic.th', 'country_code' => 'TH', 'currency_code' => 'THB']);
        $this->db->table('business_store')->insert(['public_id' => 'store0000001', 'business_id' => $this->db->insertID(), 'store_name' => 'Bangkok', 'store_timezone' => 'Asia/Bangkok']);
        $this->storeId = (int) $this->db->insertID();

        // Saturday (6): 10:00-14:00 and 17:00-02:00 (into Sunday); Sunday (7): 12:00-18:00; the other days closed
        foreach ([[6, '17:00:00', '02:00:00'], [6, '10:00:00', '14:00:00'], [7, '12:00:00', '18:00:00']] as [$day, $open, $close]) {
            $this->hours->insert(['store_id' => $this->storeId, 'day_of_week' => $day, 'open_time' => $open, 'close_time' => $close]);
        }
    }

    public function testTheWeeklyHoursAreInLocalTimeAndTheLastMayCloseTheNextDay(): void
    {
        $this->assertSame([
            'date'      => '2026-11-07', // a Saturday
            'timezone'  => 'Asia/Bangkok',
            'exception' => null,
            'hours'     => [
                ['open' => '2026-11-07T10:00:00+07:00', 'close' => '2026-11-07T14:00:00+07:00'],
                ['open' => '2026-11-07T17:00:00+07:00', 'close' => '2026-11-08T02:00:00+07:00'],
            ],
        ], $this->hours->getBusinessHoursOf($this->storeId, '2026-11-07'));
    }

    public function testTheHoursComeInUtcOrAnyTimezoneAsked(): void
    {
        $utc = $this->hours->getBusinessHoursOf($this->storeId, '2026-11-07', 'UTC');
        $this->assertSame('UTC', $utc['timezone']);
        $this->assertSame([
            ['open' => '2026-11-07T03:00:00Z', 'close' => '2026-11-07T07:00:00Z'],
            ['open' => '2026-11-07T10:00:00Z', 'close' => '2026-11-07T19:00:00Z'],
        ], $utc['hours']);

        $tokyo = $this->hours->getBusinessHoursOf($this->storeId, '2026-11-08', 'Asia/Tokyo');
        $this->assertSame([['open' => '2026-11-08T14:00:00+09:00', 'close' => '2026-11-08T20:00:00+09:00']], $tokyo['hours']);
    }

    public function testADayWithNoHoursIsClosed(): void
    {
        $this->assertSame([], $this->hours->getBusinessHoursOf($this->storeId, '2026-11-09')['hours']); // a Monday
    }

    public function testAnExceptionReplacesTheWholeDay(): void
    {
        $exceptions = $this->db->table(BusinessStoreHoursModel::EXCEPTIONS_TABLE);
        // closed all day
        $exceptions->insert(['store_id' => $this->storeId, 'exception_date' => '2026-12-26', 'exception_reason' => 'Employee training']);
        // a Friday, otherwise closed, opened late and with a break
        foreach ([['18:00:00', '23:00:00'], ['12:00:00', '15:00:00']] as [$open, $close]) {
            $exceptions->insert(['store_id' => $this->storeId, 'exception_date' => '2026-12-25', 'open_time' => $open, 'close_time' => $close, 'exception_reason' => "X'Mas"]);
        }

        $closed = $this->hours->getBusinessHoursOf($this->storeId, '2026-12-26'); // a Saturday, normally open
        $this->assertSame([['reason' => 'Employee training'], []], [$closed['exception'], $closed['hours']]);

        $special = $this->hours->getBusinessHoursOf($this->storeId, '2026-12-25');
        $this->assertSame(['reason' => "X'Mas"], $special['exception']);
        $this->assertSame([
            ['open' => '2026-12-25T12:00:00+07:00', 'close' => '2026-12-25T15:00:00+07:00'],
            ['open' => '2026-12-25T18:00:00+07:00', 'close' => '2026-12-25T23:00:00+07:00'],
        ], $special['hours']);
    }

    public function testIsOpenAtLooksAtTheDayBeforeForAnOpeningPastMidnight(): void
    {
        $this->assertTrue($this->hours->isOpenAt($this->storeId, '2026-11-08T01:30:00+07:00'));  // Saturday's late opening
        $this->assertTrue($this->hours->isOpenAt($this->storeId, '2026-11-07T18:30:00Z'));       // the same moment in UTC
        $this->assertTrue($this->hours->isOpenAt($this->storeId, '2026-11-07 10:00:00'));        // no offset: local; opens at 10
        $this->assertFalse($this->hours->isOpenAt($this->storeId, '2026-11-07T14:00:00+07:00')); // closes at 14
        $this->assertFalse($this->hours->isOpenAt($this->storeId, '2026-11-08T02:00:00+07:00'));
        $this->assertFalse($this->hours->isOpenAt($this->storeId, '2026-11-09T12:00:00+07:00')); // Monday
    }

    public function testATwentyFourHourDay(): void
    {
        $this->hours->insert(['store_id' => $this->storeId, 'day_of_week' => 1, 'open_time' => '00:00:00', 'close_time' => '00:00:00']);
        $this->assertSame([['open' => '2026-11-09T00:00:00+07:00', 'close' => '2026-11-10T00:00:00+07:00']],
            $this->hours->getBusinessHoursOf($this->storeId, '2026-11-09')['hours']);
        $this->assertTrue($this->hours->isOpenAt($this->storeId, '2026-11-09T23:59:59+07:00'));
    }

    public function testBadInputIsRefused(): void
    {
        foreach ([[$this->storeId, '2026-02-30', 'local'], [$this->storeId, '2026-11-07', 'Mars/Olympus'], [999, '2026-11-07', 'local']] as [$store, $date, $zone]) {
            try {
                $this->hours->getBusinessHoursOf($store, $date, $zone);
                $this->fail('Accepted ' . $date . ' ' . $zone . ' of store ' . $store);
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(InvalidArgumentException::class);
        $this->hours->isOpenAt($this->storeId, 'not a time');
    }
}
