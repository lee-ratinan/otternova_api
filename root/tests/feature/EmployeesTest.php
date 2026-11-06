<?php

use App\Models\BusinessTypeModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\PlanLimits;
use Config\Services;

/**
 * /client/employees, /client/employee and /client/employee/edit: the active owners of a business list everyone
 * linked to it, and see and change a few things of their accounts
 *
 * @internal
 */
final class EmployeesTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'App'; // the app's own migrations, the schema of the real database

    private string $token;
    private int $businessId;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['jwt', 'authContext'] as $service) {
            Services::resetSingle($service);
        }
        config(PlanLimits::class)->plans['free'][PlanLimits::EMPLOYEES] = 3;
        $owner       = $this->createClient('owner@example.com', 'Olive Owner');
        $this->token = $owner['token'];

        $staff  = $this->createAccount('admin@otternova.com', 'S', 'Admin');
        $types  = new BusinessTypeModel();
        $typeId = $types->publicIdOf($types->createWithNames(['type_code' => 'cafe'], ['en-US' => 'Café', 'th' => 'คาเฟ่'], (int) $staff['id']));
        $this->send([
            'business' => [
                'business_type_public_id' => $typeId,
                'business_name'           => 'OtterCafe',
                'business_slug'           => 'otter-cafe-th',
                'contact_email_address'   => 'hello@ottercafe.th',
                'country_code'            => 'TH',
                'currency_code'           => 'THB',
            ],
        ], null, 'v1/client/business/create')->assertStatus(200);
        $this->businessId = (int) $this->db->table('business_master')->get()->getRow()->id;
    }

    public function testTheListHasEveryoneLinkedAndTheCapCountsTheActiveOnes(): void
    {
        $this->createClient('emma@example.com', 'Emma Employee', 'E', 'A');
        $this->createClient('fred@example.com', 'Fred Former', 'E', 'T');
        $this->createClient('stranger@example.com', 'Stranger');

        $response = $this->send(['business_slug' => 'otter-cafe-th'], null, 'v1/client/employees');
        $response->assertStatus(200);
        $data = $this->body($response)['data'];
        $this->assertSame(['Emma Employee', 'Fred Former', 'Olive Owner'], array_column($data['employees'], 'full_name'));
        $this->assertSame(['E', 'E', 'O'], array_column($data['employees'], 'client_role'));
        $this->assertSame(['A', 'T', 'A'], array_column($data['employees'], 'client_status'));
        $this->assertArrayNotHasKey('id', $data['employees'][0]);
        $this->assertSame(['free', 3, 2, true], [$data['plan_code'], $data['max_employees'], $data['active_employees'], $data['can_add']]);

        config(PlanLimits::class)->plans['free'][PlanLimits::EMPLOYEES] = 2;
        $this->assertFalse($this->body($this->send(['business_slug' => 'otter-cafe-th'], null, 'v1/client/employees'))['data']['can_add']);
    }

    public function testShowGivesOnlyWhatTheOwnerMaySee(): void
    {
        $emma = $this->createClient('emma@example.com', 'Emma Employee', 'E', 'A');
        $this->send(['business_slug' => 'otter-cafe-th', 'uid' => $emma['uid'], 'profile' => ['national_id_type' => 'P', 'national_id_number' => 'AB1234567', 'internal_notes' => 'ignored'], 'link' => ['owner_notes' => ' Keys holder ']],
            null, 'v1/client/employee/edit')->assertStatus(200);

        $response = $this->send(['business_slug' => 'otter-cafe-th', 'uid' => $emma['uid']], null, 'v1/client/employee');
        $response->assertStatus(200);
        $data = $this->body($response)['data'];
        $this->assertSame(['uid', 'email_address', 'telephone_number', 'password_expiry', 'full_name', 'otp_mode', 'account_status', 'account_type', 'avatar_path'],
            array_keys($data['account']));
        $this->assertSame(['legal_first_name', 'legal_middle_name', 'legal_last_name', 'display_name', 'national_id_type', 'national_id_number'],
            array_keys($data['profile']));
        $this->assertSame(['P', 'AB1234567'], [$data['profile']['national_id_type'], $data['profile']['national_id_number']]);
        $this->assertSame(['E', 'A', 'Keys holder'], [$data['link']['client_role'], $data['link']['client_status'], $data['link']['owner_notes']]);
        // the business's own notes, not the person's internal_notes shared with every business and Otternova staff
        $this->assertNull($this->db->table('client_master')->where('id', $emma['client_id'])->get()->getRow()->internal_notes);
        $this->assertSame(['A'], $data['status_options']); // an active account is not the owner's to take out
        $this->assertFalse($data['is_self']);
        // stored encrypted
        $this->assertNotSame('AB1234567', $this->db->table('client_master')->where('id', $emma['client_id'])->get()->getRow()->national_id_number);

        $self = $this->body($this->send(['business_slug' => 'otter-cafe-th', 'uid' => 'ownerexamplecom'], null, 'v1/client/employee'))['data'];
        $this->assertTrue($self['is_self']);
        $this->assertSame(['A'], $self['status_options']);
    }

    public function testEditWritesOnlyTheOwnersFields(): void
    {
        $emma = $this->createClient('emma@example.com', 'Emma Employee', 'E', 'T'); // a former employee too
        $response = $this->send([
            'business_slug' => 'otter-cafe-th',
            'uid'           => $emma['uid'],
            'account'       => ['full_name' => ' Emma E. ', 'email_address' => 'hacked@example.com', 'telephone_number' => '+6621234567', 'otp_mode' => 'E', 'admin_notes' => 'x'],
            'profile'       => ['legal_first_name' => 'Emma', 'display_name' => 'Em', 'bio' => 'hacked', 'gender' => 'F', 'marketing_opt_in' => 'Y'],
        ], null, 'v1/client/employee/edit');
        $response->assertStatus(200);

        $account = $this->db->table('auth_master')->where('uid', $emma['uid'])->get()->getRowArray();
        $this->assertSame(['Emma E.', 'emma@example.com', null, 'X', null], [$account['full_name'], $account['email_address'], $account['telephone_number'], $account['otp_mode'], $account['admin_notes']]);
        $profile = $this->db->table('client_master')->where('id', $emma['client_id'])->get()->getRowArray();
        $this->assertSame(['Emma', 'Em', null, null], [$profile['legal_first_name'], $profile['display_name'], $profile['bio'], $profile['gender']]);

        $response = $this->send(['business_slug' => 'otter-cafe-th', 'uid' => $emma['uid'], 'account' => ['full_name' => ''], 'profile' => ['national_id_type' => 'X']],
            null, 'v1/client/employee/edit');
        $response->assertStatus(422);
        $this->assertSame(['account.full_name', 'profile.national_id_type'], array_keys($this->body($response)['errors']));
    }

    public function testTheStatusMovesOnlyAsTheOwnerMay(): void
    {
        $emma   = $this->createClient('emma@example.com', 'Emma Employee', 'E', 'A');
        $status = fn (string $to): TestResponse => $this->send(['business_slug' => 'otter-cafe-th', 'uid' => $emma['uid'], 'account' => ['account_status' => $to]], null, 'v1/client/employee/edit');
        $stored = fn (): string => $this->db->table('auth_master')->where('uid', $emma['uid'])->get()->getRow()->account_status;

        // an active account is the person's in every business they work at: never the owner's to take out
        foreach (['S', 'B', 'P'] as $to) {
            $response = $status($to);
            $response->assertStatus(422);
            $this->assertSame(lang('Accounts.statusChangeNotAllowed'), $this->body($response)['errors']['account.account_status']);
            $this->assertSame('A', $stored());
        }
        $status('A')->assertStatus(200); // unchanged is fine

        $this->db->table('auth_master')->where('uid', $emma['uid'])->update(['account_status' => 'S']);
        $status('S')->assertStatus(200); // unchanged is fine
        $status('B')->assertStatus(422);
        $status('A')->assertStatus(200); // the owner lets a suspended account back in
        $this->assertSame('A', $stored());

        $this->db->table('auth_master')->where('uid', $emma['uid'])->update(['account_status' => 'B']);
        $status('S')->assertStatus(422);
        $status('A')->assertStatus(200);
        $this->assertSame('A', $stored());

        $this->db->table('auth_master')->where('uid', $emma['uid'])->update(['account_status' => 'P']);
        $status('A')->assertStatus(422);

        $response = $this->send(['business_slug' => 'otter-cafe-th', 'uid' => 'ownerexamplecom', 'account' => ['account_status' => 'S']], null, 'v1/client/employee/edit');
        $response->assertStatus(422);
        $this->assertSame(lang('Accounts.ownStatus'), $this->body($response)['errors']['account.account_status']);
    }

    public function testOwnerNotesAreTheBusinesssOwnAndSavedWithTheAccount(): void
    {
        $emma = $this->createClient('emma@example.com', 'Emma Employee', 'E', 'T');
        $edit = fn (array $body): TestResponse => $this->send(['business_slug' => 'otter-cafe-th', 'uid' => $emma['uid']] + $body, null, 'v1/client/employee/edit');
        $notes = fn (): ?string => $this->db->table('business_client_link')->where('client_id', $emma['client_id'])->get()->getRow()->owner_notes;

        $edit(['link' => ['owner_notes' => 'Rehire welcome']])->assertStatus(200);
        $this->assertSame('Rehire welcome', $notes());
        $edit(['account' => ['full_name' => 'Emma E.']])->assertStatus(200); // left out: kept
        $this->assertSame('Rehire welcome', $notes());

        // an invalid account change saves no notes either
        $response = $edit(['account' => ['full_name' => ''], 'link' => ['owner_notes' => 'Changed']]);
        $response->assertStatus(422);
        $this->assertSame('Rehire welcome', $notes());
        $response = $edit(['link' => ['owner_notes' => ['not', 'text']]]);
        $response->assertStatus(422);
        $this->assertSame(['link.owner_notes'], array_keys($this->body($response)['errors']));

        $edit(['link' => ['owner_notes' => '  ']])->assertStatus(200);
        $this->assertNull($notes());
    }

    public function testTerminateEndsTheEmploymentButNotTheAccount(): void
    {
        $emma      = $this->createClient('emma@example.com', 'Emma Employee', 'E', 'A');
        $terminate = fn (string $uid, mixed $date): TestResponse => $this->send(['business_slug' => 'otter-cafe-th', 'uid' => $uid, 'termination_date' => $date], null, 'v1/client/employee/terminate');
        $link      = fn (): array => $this->db->table('business_client_link')->where('client_id', $emma['client_id'])->get()->getRowArray();
        $error     = function (TestResponse $response): string {
            $response->assertStatus(422);

            return $this->body($response)['errors']['termination_date'];
        };

        $this->assertSame(lang('Employee.invalidTerminationDate'), $error($terminate($emma['uid'], '2026-02-30')));
        $this->assertSame(lang('Employee.invalidTerminationDate'), $error($terminate($emma['uid'], null)));
        $this->assertSame(lang('Employee.terminationBeforeStart', ['2026-01-01']), $error($terminate($emma['uid'], '2025-12-31')));
        $this->assertSame(lang('Employee.terminationInFuture'), $error($terminate($emma['uid'], gmdate('Y-m-d', time() + 3 * 86400))));
        $this->assertSame('A', $link()['client_status']);

        $terminate($emma['uid'], '2026-03-15')->assertStatus(200);
        $this->assertSame(['T', '2026-03-15'], [$link()['client_status'], $link()['termination_date']]);
        // the account stays as it is: they keep working in their other businesses
        $this->assertSame('A', $this->db->table('auth_master')->where('uid', $emma['uid'])->get()->getRow()->account_status);
        $this->seeInDatabase('log_activity', ['table_involved' => 'business_client_link']);

        $response = $terminate($emma['uid'], '2026-03-16');
        $response->assertStatus(409);
        $this->assertSame(lang('Employee.alreadyTerminated'), $this->body($response)['message']);

        // the active count of the plan's cap drops
        $this->assertSame(1, $this->body($this->send(['business_slug' => 'otter-cafe-th'], null, 'v1/client/employees'))['data']['active_employees']);
    }

    public function testAnOwnerLeavesOnlyWhenAnotherOwnerCanTakeOver(): void
    {
        $leave = fn (): TestResponse => $this->send(['business_slug' => 'otter-cafe-th', 'uid' => 'ownerexamplecom', 'termination_date' => gmdate('Y-m-d')], null, 'v1/client/employee/terminate'); // started today, with the business
        $show  = fn (): array => $this->body($this->send(['business_slug' => 'otter-cafe-th', 'uid' => 'ownerexamplecom'], null, 'v1/client/employee'))['data'];

        // the only owner: an employee or a former owner cannot take over
        $this->createClient('emma@example.com', 'Emma Employee', 'E', 'A');
        $this->createClient('former@example.com', 'Former Owner', 'O', 'T');
        $this->assertFalse($show()['can_leave']);
        $response = $leave();
        $response->assertStatus(409);
        $this->assertSame(lang('Employee.lastOwnerCannotLeave'), $this->body($response)['message']);

        $other = $this->createClient('other@example.com', 'Otto Owner', 'O', 'A');
        $this->assertTrue($show()['can_leave']);
        $this->assertFalse($this->body($this->send(['business_slug' => 'otter-cafe-th', 'uid' => $other['uid']], null, 'v1/client/employee'))['data']['can_leave']); // not theirs
        $leave()->assertStatus(200);
        // out: the business is no longer theirs to see, and the other owner keeps it
        $this->send(['business_slug' => 'otter-cafe-th'], null, 'v1/client/employees')->assertStatus(404);
        $this->send(['business_slug' => 'otter-cafe-th'], $other['token'], 'v1/client/employees')->assertStatus(200);

        // and the other owner cannot leave now
        $response = $this->send(['business_slug' => 'otter-cafe-th', 'uid' => $other['uid'], 'termination_date' => gmdate('Y-m-d')], $other['token'], 'v1/client/employee/terminate');
        $response->assertStatus(409);
        $this->assertSame(lang('Employee.lastOwnerCannotLeave'), $this->body($response)['message']);
    }

    public function testRehireTakesBackAFormerEmployeeWithinThePlanCap(): void
    {
        config(PlanLimits::class)->plans['free'][PlanLimits::EMPLOYEES] = 2;
        $fred   = $this->createClient('fred@example.com', 'Fred Former', 'M', 'T');
        $gina   = $this->createClient('gina@example.com', 'Gina Former', 'E', 'T');
        $this->db->table('business_client_link')->where('client_id', $fred['client_id'])->update(['owner_notes' => 'Good barista']);
        $rehire = fn (array $who): TestResponse => $this->send(['business_slug' => 'otter-cafe-th', 'uid' => $who['uid']], null, 'v1/client/employee/rehire');
        $link   = fn (array $who): array => $this->db->table('business_client_link')->where('client_id', $who['client_id'])->get()->getRowArray();

        $this->assertSame(['plan_code' => 'free', 'max_employees' => 2, 'can_add' => true],
            $this->body($this->send(['business_slug' => 'otter-cafe-th', 'uid' => $fred['uid']], null, 'v1/client/employee'))['data']['rehire']);

        $rehire($fred)->assertStatus(200);
        $row = $link($fred);
        $this->assertSame(['A', null, 'M', '2026-01-01', 'Good barista'],
            [$row['client_status'], $row['termination_date'], $row['client_role'], $row['start_date'], $row['owner_notes']]);
        $this->seeInDatabase('log_activity', ['table_involved' => 'business_client_link']);
        $this->assertNull($this->body($this->send(['business_slug' => 'otter-cafe-th', 'uid' => $fred['uid']], null, 'v1/client/employee'))['data']['rehire']);

        $response = $rehire($fred);
        $response->assertStatus(409);
        $this->assertSame(lang('Employee.alreadyActive'), $this->body($response)['message']);

        // the owner and Fred fill the free plan's 2
        $this->assertFalse($this->body($this->send(['business_slug' => 'otter-cafe-th', 'uid' => $gina['uid']], null, 'v1/client/employee'))['data']['rehire']['can_add']);
        $response = $rehire($gina);
        $response->assertStatus(409);
        $this->assertSame(lang('Employee.limitReached', [2, 'Free']), $this->body($response)['message']);
        $this->assertSame('T', $link($gina)['client_status']);
    }

    public function testOnlyActiveOwnersGetIn(): void
    {
        $emma     = $this->createClient('emma@example.com', 'Emma Employee', 'E', 'A');
        $stranger = $this->createClient('stranger@example.com', 'Stranger');
        $list     = [['business_slug' => 'otter-cafe-th'], 'v1/client/employees'];
        $show     = [['business_slug' => 'otter-cafe-th', 'uid' => $emma['uid']], 'v1/client/employee'];
        $edit     = [['business_slug' => 'otter-cafe-th', 'uid' => $emma['uid'], 'account' => ['full_name' => 'Hacked']], 'v1/client/employee/edit'];
        $end      = [['business_slug' => 'otter-cafe-th', 'uid' => $emma['uid'], 'termination_date' => '2026-03-01'], 'v1/client/employee/terminate'];
        $back     = [['business_slug' => 'otter-cafe-th', 'uid' => $emma['uid']], 'v1/client/employee/rehire'];

        $this->withHeaders([])->post('v1/client/employees')->assertStatus(401);
        foreach ([$this->createClient('manager@example.com', 'Manager', 'M', 'A'), $emma] as $notOwner) {
            foreach ([$list, $show, $edit, $end, $back] as [$body, $path]) {
                $response = $this->send($body, $notOwner['token'], $path);
                $response->assertStatus(403);
                $this->assertSame(lang('Employee.managersOnly'), $this->body($response)['message']);
            }
        }
        foreach ([$this->createClient('former@example.com', 'Former Owner', 'O', 'T'), $stranger] as $outsider) {
            foreach ([$list, $show, $edit, $end, $back] as [$body, $path]) {
                $this->send($body, $outsider['token'], $path)->assertStatus(404);
            }
        }

        // someone not linked to the business, and a staff account
        $this->send(['business_slug' => 'otter-cafe-th', 'uid' => $stranger['uid']], null, 'v1/client/employee')->assertStatus(404);
        $this->send(['business_slug' => 'otter-cafe-th', 'uid' => 'adminotternovacom'], null, 'v1/client/employee')->assertStatus(404);
        $this->send(['business_slug' => 'otter-cafe-th'], null, 'v1/client/employee')->assertStatus(400);
        $this->send(['uid' => $emma['uid']], null, 'v1/client/employee')->assertStatus(400);

        $this->assertSame('Emma Employee', $this->db->table('auth_master')->where('uid', $emma['uid'])->get()->getRow()->full_name);
        $this->assertSame('A', $this->db->table('business_client_link')->where('client_id', $emma['client_id'])->get()->getRow()->client_status);
    }

    private function createAccount(string $email, string $type, string $name): array
    {
        $account = [
            'uid'             => preg_replace('/[^a-z0-9]/', '', $email),
            'email_address'   => $email,
            'password_hash'   => password_hash('correct horse battery staple', PASSWORD_DEFAULT),
            'password_expiry' => '2027-01-01',
            'full_name'       => $name,
            'otp_mode'        => 'X',
            'account_status'  => 'A',
            'account_type'    => $type,
        ];
        $this->db->table('auth_master')->insert($account);
        $account['id'] = $this->db->insertID();

        return $account;
    }

    /**
     * A client account, linked to the business with $role and $status when given
     * @return array{client_id: int, uid: string, token: string}
     */
    private function createClient(string $email, string $name, ?string $role = null, string $status = 'A'): array
    {
        $account = $this->createAccount($email, 'C', $name);
        $this->db->table('client_master')->insert(['auth_id' => $account['id']]);
        $clientId = (int) $this->db->insertID();
        if ($role !== null) {
            $this->db->table('business_client_link')->insert(['business_id' => $this->businessId, 'client_id' => $clientId, 'client_role' => $role, 'client_status' => $status,
                'start_date' => '2026-01-01', 'termination_date' => $status === 'T' ? '2026-02-01' : null]);
        }

        return ['client_id' => $clientId, 'uid' => $account['uid'], 'token' => service('jwt')->issue($account)['access_token']];
    }

    private function send(array $body, ?string $token = null, string $path = 'v1/client/employees'): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . ($token ?? $this->token)])
            ->withBodyFormat('json')
            ->post($path, $body);
    }

    private function body(TestResponse $response): array
    {
        return json_decode($response->getJSON(), true);
    }
}
