<?php

use App\Models\BusinessClientHoursModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\ArtifactFixturesTrait;

/**
 * /client/staff/hours*: the active owners and managers of a business set when and where its people work, and
 * BusinessClientHoursModel's lookups of it
 *
 * @internal
 */
final class StaffHoursTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use ArtifactFixturesTrait;

    protected $namespace = 'App'; // the app's own migrations, the schema of the real database

    private string $ownerToken;
    private int $fixtureSeq = 0;
    private int $businessId;
    private int $clientId;
    private int $bangkokId;
    private string $bangkok;
    private int $papuaId;
    private string $papua;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['jwt', 'authContext'] as $service) {
            Services::resetSingle($service);
        }
        $this->businessId = $this->business('otter-spa');
        $this->ownerToken = $this->createClient('owner@example.com', $this->businessId, 'O');
        $this->createClient('therapist@example.com', $this->businessId, 'E'); // uid therapistexamplecom
        $this->clientId = (int) $this->db->table('business_client_link')->where('client_role', 'E')->get()->getRow()->client_id;
        [$this->bangkokId, $this->bangkok] = $this->store($this->businessId, 'Bangkok');  // UTC+7
        [$this->papuaId, $this->papua]     = $this->store($this->businessId, 'Jayapura'); // UTC+9
        $this->db->table('business_store')->where('id', $this->papuaId)->update(['store_timezone' => 'Asia/Jayapura']);
        $this->send('v1/client/staff/stores/save', $this->person() + ['stores' => [$this->bangkok, $this->papua]])->assertStatus(200);
    }

    public function testOnlyActiveOwnersAndManagersGetIn(): void
    {
        $employee = $this->createClient('employee@example.com', $this->businessId, 'E');
        $manager  = $this->createClient('manager@example.com', $this->businessId, 'M');
        $this->send('v1/client/staff/hours', $this->person(), $employee)->assertStatus(403);
        $this->send('v1/client/staff/hours/save', $this->person() + ['weekly' => []], $employee)->assertStatus(403);
        $this->send('v1/client/staff/hours', $this->person(), $manager)->assertStatus(200);
    }

    public function testRegularShiftsAtTwoStoresAreSavedAndRemovedOnesKept(): void
    {
        $this->saveWeekly([
            $this->weekly($this->bangkok, 1, '09:00', '12:00'),
            $this->weekly($this->bangkok, 1, '13:00', '18:00'),
            $this->weekly($this->papua, 2, '22:00', '06:00'), // ends on Wednesday
        ])->assertStatus(200);
        $data = $this->hours();
        $this->assertSame([$this->bangkok, $this->papua], array_column($data['stores'], 'public_id'));
        $this->assertSame([
            ['store_public_id' => $this->bangkok, 'day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '12:00'],
            ['store_public_id' => $this->bangkok, 'day_of_week' => 1, 'start_time' => '13:00', 'end_time' => '18:00'],
            ['store_public_id' => $this->papua, 'day_of_week' => 2, 'start_time' => '22:00', 'end_time' => '06:00'],
        ], $data['weekly']);

        // the lunch shift goes, the afternoon one ends later
        $this->saveWeekly([$this->weekly($this->bangkok, 1, '13:00', '19:00'), $this->weekly($this->papua, 2, '22:00', '06:00')])->assertStatus(200);
        $this->assertSame(['09:00 I', '13:00 A', '22:00 A'], array_map(static fn ($r) => substr($r['start_time'], 0, 5) . ' ' . $r['active_status'],
            $this->db->table('business_client_hours')->orderBy('start_time')->get()->getResultArray()));
        // wanted again, the kept row comes back
        $this->saveWeekly([$this->weekly($this->bangkok, 1, '09:00', '12:00'), $this->weekly($this->bangkok, 1, '13:00', '19:00')])->assertStatus(200);
        $this->assertSame(3, $this->db->table('business_client_hours')->countAllResults());
        $this->assertSame(2, $this->db->table('business_client_hours')->where('active_status', 'A')->countAllResults());
    }

    public function testShiftsNeverOverlapAtAnyStoreOrTimezone(): void
    {
        $overlap = fn (array $weekly) => $this->body($this->saveWeekly($weekly))['errors']['weekly'] ?? null;

        // 09:00-12:00 in Bangkok is 02:00-05:00 UTC; 10:00-12:00 in Jayapura is 01:00-03:00 UTC
        $this->assertSame(lang('Staff.shiftsOverlap'), $overlap([$this->weekly($this->bangkok, 3, '09:00', '12:00'), $this->weekly($this->papua, 3, '10:00', '12:00')]));
        // Sunday's late shift into Monday's first
        $this->assertSame(lang('Staff.shiftsOverlap'), $overlap([$this->weekly($this->bangkok, 7, '20:00', '04:00'), $this->weekly($this->bangkok, 1, '03:00', '08:00')]));
        // the same clock times in two timezones need not clash: 09:00-12:00 Jayapura ends at 10:00 Bangkok
        $this->saveWeekly([$this->weekly($this->papua, 3, '09:00', '12:00'), $this->weekly($this->bangkok, 3, '10:00', '15:00')])->assertStatus(200);

        [, $elsewhere] = $this->store($this->businessId, 'Pattaya'); // not theirs
        $errors = $this->body($this->saveWeekly([$this->weekly($elsewhere, 1, '09:00', '10:00'), ['day_of_week' => 9] + $this->weekly($this->bangkok, 1, '09:00', '10:00'),
            $this->weekly($this->bangkok, 1, '9am', '10:00')]))['errors'];
        $this->assertSame(['weekly.0.store_public_id', 'weekly.1.day_of_week', 'weekly.2.start_time'], array_keys($errors));
    }

    public function testADayIsChangedThenGivenBack(): void
    {
        $this->saveWeekly([$this->weekly($this->bangkok, 4, '09:00', '18:00')])->assertStatus(200);
        $date  = $this->nextWeekday(4);
        $save  = fn (array $body) => $this->send('v1/client/staff/hours-exception/save', $this->person() + ['exception_date' => $date] + $body);
        $model = new BusinessClientHoursModel();

        $save(['exception_type' => 'V', 'exception_reason' => 'Family trip', 'shifts' => []])->assertStatus(200);
        $this->assertSame([['exception_date' => $date, 'exception_type' => 'V', 'exception_reason' => 'Family trip', 'off' => true, 'shifts' => []]], $this->hours()['exceptions']);
        $this->assertSame([], $model->getWorkingHoursOf($this->businessId, $this->clientId, $date)['shifts']);

        // sick after lunch: in Bangkok in the morning only; saved again, the day off is replaced
        $save(['exception_type' => 'S', 'shifts' => [['store_public_id' => $this->bangkok, 'start_time' => '09:00', 'end_time' => '12:00']]])->assertStatus(200);
        $day = $model->getWorkingHoursOf($this->businessId, $this->clientId, $date);
        $this->assertSame(['type' => 'S', 'reason' => null], $day['exception']);
        $this->assertSame([['store_id' => $this->bangkokId, 'start' => $date . 'T09:00:00+07:00', 'end' => $date . 'T12:00:00+07:00']], $day['shifts']);
        $this->assertSame(2, $this->db->table('business_client_hours_exceptions')->countAllResults()); // the day off is kept, inactive

        $this->send('v1/client/staff/hours-exception/delete', $this->person() + ['exception_date' => $date])->assertStatus(200);
        $this->send('v1/client/staff/hours-exception/delete', $this->person() + ['exception_date' => $date])->assertStatus(404);
        $this->assertSame([], $this->hours()['exceptions']);
        $this->assertSame($date . 'T02:00:00Z', $model->getWorkingHoursOf($this->businessId, $this->clientId, $date, 'UTC')['shifts'][0]['start']);
    }

    public function testADayIsCheckedAgainstTheDaysAround(): void
    {
        $date = $this->nextWeekday(5);
        // Thursday's late shift ends at 03:00 on Friday
        $this->saveWeekly([$this->weekly($this->bangkok, 4, '20:00', '03:00')])->assertStatus(200);
        $save = fn (array $shifts) => $this->send('v1/client/staff/hours-exception/save', $this->person() + ['exception_date' => $date, 'exception_type' => 'O', 'shifts' => $shifts]);

        $this->assertSame(lang('Staff.shiftsOverlapNeighbour'), $this->body($save([['store_public_id' => $this->bangkok, 'start_time' => '02:00', 'end_time' => '06:00']]))['errors']['shifts']);
        $save([['store_public_id' => $this->papua, 'start_time' => '06:00', 'end_time' => '10:00']])->assertStatus(200); // 03:00 Bangkok time

        $errors = $this->body($this->send('v1/client/staff/hours-exception/save', $this->person() + ['exception_date' => '2000-01-01', 'exception_type' => 'X',
            'exception_reason' => str_repeat('x', 256), 'shifts' => []]))['errors'];
        $this->assertSame(['exception_date', 'exception_type', 'exception_reason'], array_keys($errors));
    }

    public function testIsWorkingAtLooksAcrossMidnightAndStores(): void
    {
        $this->saveWeekly([$this->weekly($this->bangkok, 2, '22:00', '06:00')])->assertStatus(200);
        $tuesday = $this->nextWeekday(2);
        $model   = new BusinessClientHoursModel();
        $after   = (new DateTimeImmutable($tuesday))->modify('+1 day')->format('Y-m-d');

        $this->assertTrue($model->isWorkingAt($this->businessId, $this->clientId, $after . 'T05:30:00+07:00'));
        $this->assertTrue($model->isWorkingAt($this->businessId, $this->clientId, $after . 'T05:30:00+07:00', $this->bangkokId));
        $this->assertFalse($model->isWorkingAt($this->businessId, $this->clientId, $after . 'T05:30:00+07:00', $this->papuaId));
        $this->assertFalse($model->isWorkingAt($this->businessId, $this->clientId, $after . 'T06:00:00+07:00'));
        $this->assertTrue($model->isWorkingAt($this->businessId, $this->clientId, $tuesday . 'T15:00:00Z')); // 22:00 Bangkok

        // none after they were terminated
        $this->db->table('business_client_link')->where('client_id', $this->clientId)->update(['client_status' => 'T', 'termination_date' => '2000-01-01']);
        $this->assertFalse($model->isWorkingAt($this->businessId, $this->clientId, $tuesday . 'T23:00:00+07:00'));
        $this->assertSame([], $model->getWorkingHoursOf($this->businessId, $this->clientId, $tuesday)['shifts']);
    }

    private function person(): array
    {
        return ['business_slug' => 'otter-spa', 'uid' => 'therapistexamplecom'];
    }

    private function weekly(string $store, int $day, string $start, string $end): array
    {
        return ['store_public_id' => $store, 'day_of_week' => $day, 'start_time' => $start, 'end_time' => $end];
    }

    private function saveWeekly(array $weekly)
    {
        return $this->send('v1/client/staff/hours/save', $this->person() + ['weekly' => $weekly]);
    }

    private function hours(): array
    {
        $response = $this->send('v1/client/staff/hours', $this->person());
        $response->assertStatus(200);

        return $this->body($response)['data'];
    }

    /** The first date with this ISO weekday at least two days from now, so it is "today or later" everywhere */
    private function nextWeekday(int $day): string
    {
        $date = new DateTimeImmutable('+2 days');
        while ((int) $date->format('N') !== $day) {
            $date = $date->modify('+1 day');
        }

        return $date->format('Y-m-d');
    }
}
