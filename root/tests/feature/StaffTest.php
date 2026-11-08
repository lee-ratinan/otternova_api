<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\ArtifactFixturesTrait;

/**
 * /client/staff*, /client/commission-policy*: the active owners and managers of a business say where its people
 * work, what services they perform and at what commission, what they are paid, and how commission is paid
 *
 * @internal
 */
final class StaffTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use ArtifactFixturesTrait;

    protected $namespace = 'App'; // the app's own migrations, the schema of the real database

    private string $ownerToken;
    private int $fixtureSeq = 0;
    private int $businessId;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['jwt', 'authContext'] as $service) {
            Services::resetSingle($service);
        }
        $this->businessId = $this->business('otter-music');
        $this->ownerToken = $this->createClient('owner@example.com', $this->businessId, 'O');
        $this->createClient('teacher@example.com', $this->businessId, 'E'); // uid teacherexamplecom
    }

    public function testOnlyActiveOwnersAndManagersGetIn(): void
    {
        $manager  = $this->createClient('manager@example.com', $this->businessId, 'M');
        $employee = $this->createClient('employee@example.com', $this->businessId, 'E');
        $former   = $this->createClient('former@example.com', $this->businessId, 'M', 'T');
        $stranger = $this->createClient('stranger@example.com', null, null);

        $this->send('v1/client/staff', ['business_slug' => 'otter-music'], $manager)->assertStatus(200);
        $this->send('v1/client/staff', ['business_slug' => 'otter-music'], $employee)->assertStatus(403);
        $this->send('v1/client/staff', ['business_slug' => 'otter-music'], $former)->assertStatus(404);
        $this->send('v1/client/staff', ['business_slug' => 'otter-music'], $stranger)->assertStatus(404);
        $this->send('v1/client/commission-policy/save', ['business_slug' => 'otter-music', 'commission' => ['commission_status' => 'A']], $employee)->assertStatus(403);
        $this->send('v1/client/staff/show', ['business_slug' => 'otter-music', 'uid' => 'nobody'], $manager)->assertStatus(404);
        $this->send('v1/client/staff/show', ['business_slug' => 'otter-music'], $manager)->assertStatus(400);
    }

    public function testStoresAreReplacedAndKeptToTheBusiness(): void
    {
        [, $bangkok] = $this->store($this->businessId, 'Bangkok');
        [, $pattaya] = $this->store($this->businessId, 'Pattaya');
        [, $other]   = $this->store($this->business('other-shop'), 'Elsewhere');
        $save        = fn (array $stores) => $this->send('v1/client/staff/stores/save', ['business_slug' => 'otter-music', 'uid' => 'teacherexamplecom', 'stores' => $stores]);

        $save([$bangkok, $pattaya])->assertStatus(200);
        $save([$pattaya])->assertStatus(200);
        $response = $save([$pattaya, $other]);
        $response->assertStatus(422);
        $this->assertArrayHasKey('stores', $this->body($response)['errors']);

        $stores = $this->show()['stores'];
        $this->assertSame([[$bangkok, false], [$pattaya, true]], array_map(static fn ($s) => [$s['public_id'], $s['assigned']], $stores));
        // taken off a store, the row stays, inactive
        $this->assertSame([['A'], ['I']], array_map(static fn ($r) => [$r['link_status']],
            $this->db->table('business_store_client_link')->orderBy('link_status')->get()->getResultArray()));
    }

    public function testTakingSomeoneOffAStoreEndsTheirShiftsThereButKeepsThem(): void
    {
        [$storeId, $store] = $this->store($this->businessId, 'Bangkok');
        [$otherId, $other] = $this->store($this->businessId, 'Pattaya');
        $save = fn (array $stores) => $this->send('v1/client/staff/stores/save', ['business_slug' => 'otter-music', 'uid' => 'teacherexamplecom', 'stores' => $stores]);
        $save([$store, $other])->assertStatus(200);
        $clientId = (int) $this->db->table('business_store_client_link')->get()->getRow()->client_id;
        $shift    = fn (int $at, int $day) => $this->db->table('business_client_hours')->insert(['business_id' => $this->businessId, 'client_id' => $clientId,
            'store_id' => $at, 'day_of_week' => $day, 'start_time' => '09:00:00', 'end_time' => '17:00:00']);
        $shift($storeId, 1);
        $shift($otherId, 2);
        $exception = fn (string $date) => $this->db->table('business_client_hours_exceptions')->insert(['business_id' => $this->businessId, 'client_id' => $clientId,
            'exception_date' => $date, 'exception_type' => 'O', 'store_id' => $storeId, 'start_time' => '10:00:00', 'end_time' => '14:00:00']);
        $exception('2000-01-01'); // past: what happened
        $exception('2999-01-01'); // to come

        $save([$other])->assertStatus(200);
        $status = fn (string $table) => array_column($this->db->table($table)->select('store_id, active_status')->orderBy('id')->get()->getResultArray(), 'active_status');
        $this->assertSame(['I', 'A'], $status('business_client_hours'));
        $this->assertSame(['A', 'I'], $status('business_client_hours_exceptions'));

        // put back, the store is theirs again, but the old shifts stay inactive
        $save([$store, $other])->assertStatus(200);
        $this->assertSame(['A', 'A'], array_column($this->db->table('business_store_client_link')->orderBy('id')->get()->getResultArray(), 'link_status'));
        $this->assertSame(['I', 'A'], $status('business_client_hours'));
        $this->assertSame([true, true], array_column($this->show()['stores'], 'assigned'));
    }

    public function testServicesCarryTheCommissionAndTheThreshold(): void
    {
        [, $guitar]  = $this->artifactRow($this->businessId, 'S');
        [, $piano]   = $this->artifactRow($this->businessId, 'S');
        [, $product] = $this->artifactRow($this->businessId, 'P');
        [, $retired] = $this->artifactRow($this->businessId, 'S', 'D');
        $save        = fn (array $body) => $this->send('v1/client/staff/services/save', ['business_slug' => 'otter-music', 'uid' => 'teacherexamplecom'] + $body);

        $save(['services' => [
            ['public_id' => $guitar, 'commission_value' => 12.5, 'commission_unit' => 'P'],
            ['public_id' => $piano, 'commission_value' => '300', 'commission_unit' => 'F'],
        ], 'commission_threshold' => '20000'])->assertStatus(200);

        $show = $this->show();
        $this->assertSame('20000.00', $show['person']['commission_threshold']);
        $this->assertSame([[$guitar, true, '12.50', 'P'], [$piano, true, '300.00', 'F']],
            array_map(static fn ($s) => [$s['public_id'], $s['assigned'], $s['commission_value'], $s['commission_unit']], $show['services']));

        // the threshold left out stays; "" goes back to the business's
        $save(['services' => [['public_id' => $guitar, 'commission_value' => 0]]])->assertStatus(200);
        $this->assertSame('20000.00', $this->show()['person']['commission_threshold']);
        $save(['services' => [['public_id' => $guitar]], 'commission_threshold' => ''])->assertStatus(200);
        $show = $this->show();
        $this->assertNull($show['person']['commission_threshold']);
        $this->assertSame(['0.00', false], [$show['services'][0]['commission_value'], $show['services'][1]['assigned']]);

        $errors = $this->body($save(['services' => [
            ['public_id' => $guitar, 'commission_value' => 101],
            ['public_id' => $piano, 'commission_value' => '1.234', 'commission_unit' => 'F'],
            ['public_id' => $product],
            ['public_id' => $retired],
        ], 'commission_threshold' => -5]))['errors'];
        $this->assertSame([
            'commission_threshold',
            'services.' . $guitar . '.commission_value',
            'services.' . $piano . '.commission_value',
            'services.' . $product . '.public_id',
            'services.' . $retired . '.public_id',
        ], array_keys($errors));
        $this->assertSame(lang('Staff.discontinuedService'), $errors['services.' . $retired . '.public_id']);
    }

    public function testSalariesNeverOverlapAndANewOneEndsTheCurrent(): void
    {
        $add  = fn (array $salary) => $this->send('v1/client/staff/salary/add', ['business_slug' => 'otter-music', 'uid' => 'teacherexamplecom', 'salary' => $salary]);
        $edit = fn (array $salary) => $this->send('v1/client/staff/salary/edit', ['business_slug' => 'otter-music', 'uid' => 'teacherexamplecom', 'salary' => $salary]);

        $edit(['salary_amount' => 1, 'salary_period' => 'M', 'effective_date' => '2026-01-01'])->assertStatus(409);
        $this->assertSame(lang('Staff.salaryBeforeStart'), $this->body($add(['salary_amount' => 15000, 'salary_period' => 'M', 'effective_date' => '2025-12-31']))['errors']['effective_date']);

        $add(['salary_amount' => 15000, 'salary_period' => 'M', 'effective_date' => '2026-01-01'])->assertStatus(200);
        $add(['salary_amount' => '18000.50', 'salary_period' => 'S', 'effective_date' => '2026-07-01'])->assertStatus(200);
        $this->assertSame([
            ['salary_amount' => '18000.50', 'salary_period' => 'S', 'effective_date' => '2026-07-01', 'end_date' => null],
            ['salary_amount' => '15000.00', 'salary_period' => 'M', 'effective_date' => '2026-01-01', 'end_date' => '2026-06-30'],
        ], $this->show()['salaries']);

        // the new one must start after the current one started
        $this->assertSame(lang('Staff.salaryNotAfterCurrent'), $this->body($add(['salary_amount' => 1, 'salary_period' => 'M', 'effective_date' => '2026-07-01']))['errors']['effective_date']);
        // a correction of the latest must still start after the one before ended
        $this->assertSame(lang('Staff.salaryOverlaps'), $this->body($edit(['salary_amount' => 1, 'salary_period' => 'M', 'effective_date' => '2026-06-30']))['errors']['effective_date']);
        $edit(['salary_amount' => 18500, 'salary_period' => 'S', 'effective_date' => '2026-07-01', 'end_date' => '2026-09-30'])->assertStatus(200);
        // after an ended salary, the next starts after its end
        $this->assertSame(lang('Staff.salaryOverlaps'), $this->body($add(['salary_amount' => 1, 'salary_period' => 'M', 'effective_date' => '2026-09-30']))['errors']['effective_date']);
        $add(['salary_amount' => 20000, 'salary_period' => 'W', 'effective_date' => '2026-10-01'])->assertStatus(200);
        $this->assertSame(['2026-10-01', '2026-07-01', '2026-01-01'], array_column($this->show()['salaries'], 'effective_date'));
        $this->assertSame('2026-09-30', $this->show()['salaries'][1]['end_date']);

        $errors = $this->body($add(['salary_amount' => -1, 'salary_period' => 'Y', 'effective_date' => '2026-13-01', 'end_date' => 'soon']))['errors'];
        $this->assertSame(['salary_amount', 'salary_period', 'effective_date', 'end_date'], array_keys($errors));
        $this->assertSame(lang('Staff.endBeforeStart'), $this->body($add(['salary_amount' => 1, 'salary_period' => 'M', 'effective_date' => '2027-02-01', 'end_date' => '2027-01-31']))['errors']['end_date']);
    }

    public function testTheListTellsTheCountsAndTodaysSalary(): void
    {
        [, $store]   = $this->store($this->businessId, 'Bangkok');
        [, $service] = $this->artifactRow($this->businessId, 'S');
        $person      = ['business_slug' => 'otter-music', 'uid' => 'teacherexamplecom'];
        $this->send('v1/client/staff/stores/save', $person + ['stores' => [$store]])->assertStatus(200);
        $this->send('v1/client/staff/services/save', $person + ['services' => [['public_id' => $service]]])->assertStatus(200);
        $this->send('v1/client/staff/salary/add', $person + ['salary' => ['salary_amount' => 15000, 'salary_period' => 'M', 'effective_date' => '2026-01-01']])->assertStatus(200);

        $data    = $this->body($this->send('v1/client/staff', ['business_slug' => 'otter-music']))['data'];
        $teacher = array_values(array_filter($data['staff'], static fn ($p) => $p['uid'] === 'teacherexamplecom'))[0];
        $this->assertSame([1, 1, '15000.00', 'M'], [$teacher['store_count'], $teacher['service_count'], $teacher['salary_amount'], $teacher['salary_period']]);
        $this->assertArrayNotHasKey('email_address', $teacher);
        $this->assertSame('THB', $data['currency_code']);
    }

    public function testTheCommissionPolicy(): void
    {
        $this->assertSame(['commission_status' => 'I', 'commission_period' => 'M', 'commission_threshold' => '0.00'],
            $this->body($this->send('v1/client/commission-policy', ['business_slug' => 'otter-music']))['data']['commission']);

        $this->send('v1/client/commission-policy/save', ['business_slug' => 'otter-music', 'commission' => ['commission_status' => 'A', 'commission_threshold' => 30000]])->assertStatus(200);
        $this->assertSame(['commission_status' => 'A', 'commission_period' => 'M', 'commission_threshold' => '30000.00'],
            $this->body($this->send('v1/client/commission-policy', ['business_slug' => 'otter-music']))['data']['commission']);

        $response = $this->send('v1/client/commission-policy/save', ['business_slug' => 'otter-music', 'commission' => ['commission_status' => 'X', 'commission_period' => 'D', 'commission_threshold' => 'lots']]);
        $response->assertStatus(422);
        $this->assertSame(['commission_status', 'commission_period', 'commission_threshold'], array_keys($this->body($response)['errors']));
    }

    private function show(): array
    {
        $response = $this->send('v1/client/staff/show', ['business_slug' => 'otter-music', 'uid' => 'teacherexamplecom']);
        $response->assertStatus(200);

        return $this->body($response)['data'];
    }
}
