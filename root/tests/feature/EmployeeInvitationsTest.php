<?php

use App\Libraries\EmailService;
use App\Models\BusinessTypeModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\PlanLimits;
use Config\Services;

/**
 * /client/employee/find, /client/employee/add and /client/employee/invite: the active owners of a business add
 * someone who has an account, or invite someone who has none; /client-invitation and /create-client-account with
 * an invitation: the invitee signs up and is linked to the business at once
 *
 * @internal
 */
final class EmployeeInvitationsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    private const PASSWORD = 'otters hold hands 2 sleep';

    protected $namespace = 'App'; // the app's own migrations, the schema of the real database

    private string $token;
    private int $businessId;
    private ?string $linkClient = null;

    /** Captures emails instead of logging them */
    private EmailService $mailer;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['jwt', 'authContext', 'loginThrottle'] as $service) {
            Services::resetSingle($service);
        }
        $this->linkClient     = $_ENV['LINK_CLIENT'] ?? null;
        $_ENV['LINK_CLIENT']  = 'http://client.test/';
        $this->mailer = new class () extends EmailService {
            public array $sent = [];

            protected function deliver(string $to, string $subject, string $body): bool
            {
                $this->sent[] = ['to' => $to, 'subject' => $subject, 'body' => $body];

                return true;
            }
        };
        Services::injectMock('emailService', $this->mailer);
        config(PlanLimits::class)->plans['free'][PlanLimits::EMPLOYEES] = 5;

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

    protected function tearDown(): void
    {
        if ($this->linkClient === null) {
            unset($_ENV['LINK_CLIENT']);
        } else {
            $_ENV['LINK_CLIENT'] = $this->linkClient;
        }
        parent::tearDown();
    }

    public function testFindTellsWhetherAClientAccountHasTheAddress(): void
    {
        $emma = $this->createClient('Emma@Example.com', 'Emma Employee');
        $this->createClient('fred@example.com', 'Fred Former', 'E', 'T');

        $data = $this->body($this->find('emma@example.COM'))['data'];
        $this->assertSame([true, null], [$data['found'], $data['client_status']]);
        $this->assertSame(['uid' => $emma['uid'], 'email_address' => 'Emma@Example.com', 'full_name' => 'Emma Employee', 'avatar_path' => null], $data['account']);
        $this->assertSame('T', $this->body($this->find('fred@example.com'))['data']['client_status']);
        $this->assertSame(['found' => false], $this->body($this->find('nobody@example.com'))['data']);
        $this->assertSame(['found' => false], $this->body($this->find('admin@otternova.com'))['data']); // staff
        $this->find('emma@')->assertStatus(422);
    }

    public function testAddLinksAnExistingAccountStartingToday(): void
    {
        $emma = $this->createClient('emma@example.com', 'Emma Employee');

        $response = $this->send(['business_slug' => 'otter-cafe-th', 'email_address' => 'EMMA@example.com', 'client_role' => 'm', 'owner_notes' => ' Opens on Mondays '], null, 'v1/client/employee/add');
        $response->assertStatus(200);
        $this->assertSame($emma['uid'], $this->body($response)['data']['uid']);
        $link = $this->db->table('business_client_link')->where('client_id', $emma['client_id'])->get()->getRowArray();
        $this->assertSame(['A', 'M', gmdate('Y-m-d'), null, 'Opens on Mondays'],
            [$link['client_status'], $link['client_role'], $link['start_date'], $link['termination_date'], $link['owner_notes']]);

        $response = $this->send(['business_slug' => 'otter-cafe-th', 'email_address' => 'emma@example.com', 'client_role' => 'E'], null, 'v1/client/employee/add');
        $response->assertStatus(409);
        $this->assertSame(lang('Employee.alreadyActive'), $this->body($response)['message']);
    }

    public function testAddIsRefusedForTheWrongPeopleAndFields(): void
    {
        $this->createClient('fred@example.com', 'Fred Former', 'E', 'T');
        $add = fn (array $body): TestResponse => $this->send(['business_slug' => 'otter-cafe-th'] + $body, null, 'v1/client/employee/add');

        $response = $add(['email_address' => 'fred@example.com', 'client_role' => 'E']);
        $response->assertStatus(409);
        $this->assertSame(lang('Employee.useRehire'), $this->body($response)['message']);
        $add(['email_address' => 'nobody@example.com', 'client_role' => 'E'])->assertStatus(404);
        $add(['email_address' => 'admin@otternova.com', 'client_role' => 'E'])->assertStatus(404);
        $response = $add(['email_address' => 'fred@example.com', 'client_role' => 'X', 'owner_notes' => ['x']]);
        $response->assertStatus(422);
        $this->assertSame(['client_role', 'owner_notes'], array_keys($this->body($response)['errors']));
    }

    public function testInviteEmailsALinkAndHoldsASeat(): void
    {
        config(PlanLimits::class)->plans['free'][PlanLimits::EMPLOYEES] = 2;

        $response = $this->invite('New.Person@Example.com', 'E', 'Weekend shifts');
        $response->assertStatus(200);
        $this->assertSame(lang('Employee.invited', ['New.Person@Example.com']), $this->body($response)['message']);
        $row = $this->db->table('business_invitation')->get()->getRowArray();
        $this->assertSame(['new.person@example.com', 'E', 'Weekend shifts'], [$row['email_address'], $row['client_role'], $row['owner_notes']]);
        $this->assertCount(1, $this->mailer->sent);
        $this->assertSame('New.Person@Example.com', $this->mailer->sent[0]['to']);
        $this->assertStringContainsString('Olive Owner invited you to work at OtterCafe on OtterNova as Employee', $this->mailer->sent[0]['body']);
        $this->assertMatchesRegularExpression('#http://client\.test/create-account\?invitation=[0-9a-f]{64}#', $this->mailer->sent[0]['body']);
        $this->assertStringNotContainsString($row['token_hash'], $this->mailer->sent[0]['body']);

        // the owner and the invitation fill the plan's 2
        $list = $this->body($this->send(['business_slug' => 'otter-cafe-th'], null, 'v1/client/employees'))['data'];
        $this->assertFalse($list['can_add']);
        $this->assertSame(['new.person@example.com'], array_column($list['invitations'], 'email_address'));
        $response = $this->invite('other@example.com', 'E');
        $response->assertStatus(409);
        $this->assertSame(lang('Employee.limitReachedAdd', [2, 'Free']), $this->body($response)['message']);

        // inviting the same address again replaces it: a new link, the old one stops working
        $old = $this->tokenFrom($this->mailer->sent[0]['body']);
        $this->invite('new.person@example.com', 'M')->assertStatus(200);
        $this->assertSame(1, $this->db->table('business_invitation')->countAllResults());
        $this->send(['token' => $old], null, 'v1/client-invitation')->assertStatus(410);
        $response = $this->send(['token' => $this->tokenFrom($this->mailer->sent[1]['body'])], null, 'v1/client-invitation');
        $response->assertStatus(200);
        $this->assertSame(['email_address' => 'new.person@example.com', 'client_role' => 'M', 'business_name' => 'OtterCafe'], $this->body($response)['data']);

        // an expired invitation frees its seat
        $this->db->table('business_invitation')->update(['expires_at' => time() - 1]);
        $this->invite('other@example.com', 'E')->assertStatus(200);
    }

    public function testInviteIsRefusedForAnAddressWithAnAccount(): void
    {
        $this->createClient('emma@example.com', 'Emma Employee');

        $response = $this->invite('EMMA@example.com', 'E');
        $response->assertStatus(409);
        $this->assertSame(lang('Employee.accountExists'), $this->body($response)['message']);
        $this->assertSame([], $this->mailer->sent);
    }

    public function testSigningUpWithTheInvitationLinksTheBusinessAtOnce(): void
    {
        $this->invite('new.person@example.com', 'M', 'Weekend shifts');
        $token = $this->tokenFrom($this->mailer->sent[0]['body']);

        $response = $this->signUp('New Person', 'someone.else@example.com', $token);
        $response->assertStatus(422);
        $this->assertSame(lang('Auth.invitationEmailMismatch'), $this->body($response)['errors']['email_address']);
        $this->signUp('New Person', 'new.person@example.com', str_repeat('a', 64))->assertStatus(410);

        $this->signUp('New Person', 'New.Person@example.com', $token)->assertStatus(200);
        $account = $this->db->table('auth_master')->where('email_address', 'New.Person@example.com')->get()->getRowArray();
        $this->assertSame('P', $account['account_status']); // activated by the emailed link as any sign-up
        $client = $this->db->table('client_master')->where('auth_id', $account['id'])->get()->getRow();
        $link   = $this->db->table('business_client_link')->where('client_id', $client->id)->get()->getRowArray();
        $this->assertSame([(string) $this->businessId, 'A', 'M', gmdate('Y-m-d'), 'Weekend shifts'],
            [(string) $link['business_id'], $link['client_status'], $link['client_role'], $link['start_date'], $link['owner_notes']]);
        $this->assertSame(0, $this->db->table('business_invitation')->countAllResults());
        $this->assertSame('Activate your OtterNova account', end($this->mailer->sent)['subject']);
        // the link is used up
        $this->send(['token' => $token], null, 'v1/client-invitation')->assertStatus(410);
    }

    public function testAnAddressThatSignedUpMeanwhileIsLinkedThroughTheInvitation(): void
    {
        $this->invite('late@example.com', 'E');
        $token = $this->tokenFrom($this->mailer->sent[0]['body']);
        $late  = $this->createClient('late@example.com', 'Late Comer'); // signed up on their own before opening the link

        $this->signUp('Late Comer', 'late@example.com', $token)->assertStatus(200);
        $this->assertSame('A', $this->db->table('business_client_link')->where('client_id', $late['client_id'])->get()->getRow()->client_status);
        $this->assertSame('Your OtterNova account', end($this->mailer->sent)['subject']); // the same answer as any taken address
    }

    public function testOnlyActiveOwnersAddOrInvite(): void
    {
        $this->createClient('emma@example.com', 'Emma Employee');
        foreach (['M', 'E'] as $role) {
            $other = $this->createClient($role . 'x@example.com', 'Not Owner', $role, 'A');
            foreach (['find', 'add', 'invite'] as $path) {
                $this->send(['business_slug' => 'otter-cafe-th', 'email_address' => 'emma@example.com', 'client_role' => 'E'], $other['token'], 'v1/client/employee/' . $path)
                    ->assertStatus(403);
            }
        }
        $this->assertSame(0, $this->db->table('business_invitation')->countAllResults());
    }

    private function find(string $email): TestResponse
    {
        return $this->send(['business_slug' => 'otter-cafe-th', 'email_address' => $email], null, 'v1/client/employee/find');
    }

    private function invite(string $email, string $role, ?string $notes = null): TestResponse
    {
        return $this->send(['business_slug' => 'otter-cafe-th', 'email_address' => $email, 'client_role' => $role, 'owner_notes' => $notes], null, 'v1/client/employee/invite');
    }

    private function signUp(string $name, string $email, string $invitation): TestResponse
    {
        return $this->withHeaders([])->withBodyFormat('json')->post('v1/create-client-account',
            ['full_name' => $name, 'email_address' => $email, 'password' => self::PASSWORD, 'invitation' => $invitation]);
    }

    private function tokenFrom(string $body): string
    {
        preg_match('/invitation=([0-9a-f]{64})/', $body, $m);

        return $m[1];
    }

    private function createAccount(string $email, string $type, string $name): array
    {
        $account = [
            'uid'             => preg_replace('/[^a-z0-9]/', '', strtolower($email)),
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
