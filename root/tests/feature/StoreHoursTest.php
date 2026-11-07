<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\ArtifactFixturesTrait;

/**
 * /client/store/hours*: the active owners and managers of a business set the weekly and special opening hours of
 * its stores
 *
 * @internal
 */
final class StoreHoursTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use ArtifactFixturesTrait;

    protected $namespace = 'App'; // the app's own migrations, the schema of the real database

    private string $ownerToken;
    private int $fixtureSeq = 0;
    private int $businessId;
    private string $storeId;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['jwt', 'authContext'] as $service) {
            Services::resetSingle($service);
        }
        $this->businessId      = $this->business('otter-music');
        $this->ownerToken      = $this->createClient('owner@example.com', $this->businessId, 'O');
        [, $this->storeId]     = $this->store($this->businessId, 'Bangkok');
    }

    public function testOnlyActiveOwnersAndManagersGetIn(): void
    {
        $manager  = $this->createClient('manager@example.com', $this->businessId, 'M');
        $employee = $this->createClient('employee@example.com', $this->businessId, 'E');
        $other    = $this->createClient('other@example.com', $this->business('other-shop'), 'O');

        $this->send('v1/client/store/hours', ['public_id' => $this->storeId], $manager)->assertStatus(200);
        $this->send('v1/client/store/hours', ['public_id' => $this->storeId], $employee)->assertStatus(403);
        $this->send('v1/client/store/hours/save', ['public_id' => $this->storeId, 'weekly' => []], $other)->assertStatus(404);
        $this->send('v1/client/store/hours', ['public_id' => 'nosuchstore1'])->assertStatus(404);
        $this->send('v1/client/store/hours', [])->assertStatus(400);
    }

    public function testWeeklyHoursAreReplaced(): void
    {
        $save = fn (array $weekly) => $this->send('v1/client/store/hours/save', ['public_id' => $this->storeId, 'weekly' => $weekly]);
        $save([
            ['day_of_week' => 6, 'open_time' => '17:00', 'close_time' => '02:00'],
            ['day_of_week' => 6, 'open_time' => '10:00', 'close_time' => '14:00'],
            ['day_of_week' => 1, 'open_time' => '09:00', 'close_time' => '18:00'],
        ])->assertStatus(200);
        // Monday's closing time changes, Saturday's lunch goes
        $save([
            ['day_of_week' => 6, 'open_time' => '17:00', 'close_time' => '02:00'],
            ['day_of_week' => '1', 'open_time' => '09:00:00', 'close_time' => '17:30'],
        ])->assertStatus(200);

        $data = $this->hours();
        $this->assertSame('Asia/Bangkok', $data['store_timezone']);
        $this->assertSame([
            ['day_of_week' => 1, 'open_time' => '09:00', 'close_time' => '17:30'],
            ['day_of_week' => 6, 'open_time' => '17:00', 'close_time' => '02:00'],
        ], $data['weekly']);
    }

    public function testWeeklyHoursMustNotOverlapIncludingPastMidnight(): void
    {
        $save = fn (array $weekly) => $this->send('v1/client/store/hours/save', ['public_id' => $this->storeId, 'weekly' => $weekly]);
        $overlap = fn (array $weekly) => $this->body($save($weekly))['errors']['weekly'] ?? null;

        $this->assertSame(lang('StoreHours.overlap'), $overlap([['day_of_week' => 2, 'open_time' => '10:00', 'close_time' => '14:00'], ['day_of_week' => 2, 'open_time' => '13:00', 'close_time' => '20:00']]));
        // Tuesday's late opening runs into Wednesday's first
        $this->assertSame(lang('StoreHours.overlap'), $overlap([['day_of_week' => 2, 'open_time' => '18:00', 'close_time' => '03:00'], ['day_of_week' => 3, 'open_time' => '02:00', 'close_time' => '10:00']]));
        // Sunday's into Monday's
        $this->assertSame(lang('StoreHours.overlap'), $overlap([['day_of_week' => 7, 'open_time' => '18:00', 'close_time' => '03:00'], ['day_of_week' => 1, 'open_time' => '01:00', 'close_time' => '10:00']]));
        // one closing as the next opens is fine
        $save([['day_of_week' => 7, 'open_time' => '18:00', 'close_time' => '03:00'], ['day_of_week' => 1, 'open_time' => '03:00', 'close_time' => '10:00']])->assertStatus(200);

        $tooMany = array_map(static fn (int $h) => ['day_of_week' => 4, 'open_time' => sprintf('%02d:00', $h), 'close_time' => sprintf('%02d:30', $h)], range(1, 7));
        $this->assertSame(lang('StoreHours.tooManyOpenings', [6]), $overlap($tooMany));

        $errors = $this->body($save([['day_of_week' => 8, 'open_time' => '10:00', 'close_time' => '11:00'], ['day_of_week' => 1, 'open_time' => '25:00', 'close_time' => 'noon']]))['errors'];
        $this->assertSame(['weekly.0.day_of_week', 'weekly.1.open_time', 'weekly.1.close_time'], array_keys($errors));
    }

    public function testExceptionsReplaceADateAndCanBeRemoved(): void
    {
        $today = $this->hours()['today'];
        $date  = (new DateTimeImmutable($today))->modify('+10 days')->format('Y-m-d');
        $save  = fn (array $body) => $this->send('v1/client/store/hours-exception/save', ['public_id' => $this->storeId] + $body);

        $save(['exception_date' => $date, 'exception_reason' => "X'Mas", 'hours' => [['open_time' => '18:00', 'close_time' => '23:00'], ['open_time' => '12:00', 'close_time' => '15:00']]])->assertStatus(200);
        $this->assertSame([[
            'exception_date'   => $date,
            'exception_reason' => "X'Mas",
            'closed'           => false,
            'hours'            => [['open_time' => '12:00', 'close_time' => '15:00'], ['open_time' => '18:00', 'close_time' => '23:00']],
        ]], $this->hours()['exceptions']);

        // saved again: closed all day, the openings it had are gone
        $save(['exception_date' => $date, 'exception_reason' => 'Employee training', 'hours' => []])->assertStatus(200);
        $this->assertSame([['exception_date' => $date, 'exception_reason' => 'Employee training', 'closed' => true, 'hours' => []]], $this->hours()['exceptions']);
        $this->assertSame(1, $this->db->table('business_store_hours_exceptions')->countAllResults());

        $this->send('v1/client/store/hours-exception/delete', ['public_id' => $this->storeId, 'exception_date' => $date])->assertStatus(200);
        $this->send('v1/client/store/hours-exception/delete', ['public_id' => $this->storeId, 'exception_date' => $date])->assertStatus(404);
        $this->assertSame([], $this->hours()['exceptions']);
    }

    public function testAnExceptionIsCheckedAgainstItsNeighbours(): void
    {
        $today = new DateTimeImmutable($this->hours()['today']);
        $date  = $today->modify('+14 days');
        // the weekly hours of the day before run to 02:00
        $this->send('v1/client/store/hours/save', ['public_id' => $this->storeId, 'weekly' => [
            ['day_of_week' => (int) $date->modify('-1 day')->format('N'), 'open_time' => '20:00', 'close_time' => '02:00'],
        ]])->assertStatus(200);
        $save = fn (array $body) => $this->send('v1/client/store/hours-exception/save', ['public_id' => $this->storeId, 'exception_date' => $date->format('Y-m-d'), 'exception_reason' => 'Open early'] + $body);

        $this->assertSame(lang('StoreHours.overlapsNeighbour'), $this->body($save(['hours' => [['open_time' => '01:00', 'close_time' => '09:00']]]))['errors']['hours']);
        $save(['hours' => [['open_time' => '02:00', 'close_time' => '09:00']]])->assertStatus(200);

        $errors = $this->body($this->send('v1/client/store/hours-exception/save', ['public_id' => $this->storeId,
            'exception_date' => $today->modify('-1 day')->format('Y-m-d'), 'exception_reason' => ' ', 'hours' => []]))['errors'];
        $this->assertSame([lang('StoreHours.pastDate'), lang('StoreHours.reasonRequired')], [$errors['exception_date'], $errors['exception_reason']]);
    }

    private function hours(): array
    {
        $response = $this->send('v1/client/store/hours', ['public_id' => $this->storeId]);
        $response->assertStatus(200);

        return $this->body($response)['data'];
    }
}
