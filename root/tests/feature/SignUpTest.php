<?php

use App\Libraries\EmailService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\Services;

/**
 * POST /create-client-account and /activate-client-account
 *
 * @internal
 */
final class SignUpTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    private const PASSWORD = 'otters hold hands 2 sleep';

    protected $namespace = 'App'; // the app's own migrations, the schema of the real database

    /** Captures emails instead of logging them */
    private EmailService $mailer;

    /** The app links from .env, put back after each test */
    private array $links = [];

    protected function setUp(): void
    {
        parent::setUp();

        // shared services built in an earlier test would still hold that test's cache and config
        foreach (['loginThrottle', 'jwt', 'authContext'] as $service) {
            Services::resetSingle($service);
        }
        // env() reads $_ENV first, where .env put the real links
        foreach (['LINK_CLIENT' => 'http://client.test/', 'LINK_ADMIN' => 'http://admin.test/'] as $name => $link) {
            $this->links[$name] = $_ENV[$name] ?? null;
            $_ENV[$name]        = $link;
        }

        $this->mailer = new class () extends EmailService {
            public array $sent = [];

            protected function deliver(string $to, string $subject, string $body): bool
            {
                $this->sent[] = ['to' => $to, 'subject' => $subject, 'body' => $body];

                return true;
            }
        };
        Services::injectMock('emailService', $this->mailer);
    }

    protected function tearDown(): void
    {
        foreach ($this->links as $name => $link) {
            if ($link === null) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $link;
            }
        }

        parent::tearDown();
    }

    // ---- /create-client-account ----

    public function testSignUpCreatesPendingClientAndEmailsActivationLink(): void
    {
        $response = $this->signUp('Sea Otter', 'sea.otter@otternova.com');

        $response->assertStatus(200);
        $this->assertSame(lang('Auth.signUpReceived', ['sea.otter@otternova.com']), json_decode($response->getJSON(), true)['message']);

        $account = $this->db->table('auth_master')->where('email_address', 'sea.otter@otternova.com')->get()->getRowArray();
        $this->assertSame('seaotterotternovacom', $account['uid']);
        $this->assertSame('Sea Otter', $account['full_name']);
        $this->assertSame('C', $account['account_type']);
        $this->assertSame('P', $account['account_status']);
        $this->assertTrue(password_verify(self::PASSWORD, $account['password_hash']));
        $this->assertGreaterThan(gmdate('Y-m-d'), $account['password_expiry']);

        $profile = $this->db->table('client_master')->where('auth_id', $account['id'])->get()->getRowArray();
        $this->assertSame('Sea Otter', $profile['legal_first_name']);
        $this->assertNull($profile['display_name']);

        $this->assertCount(1, $this->mailer->sent);
        $this->assertSame('sea.otter@otternova.com', $this->mailer->sent[0]['to']);
        $this->assertStringContainsString('Hello Sea Otter', $this->mailer->sent[0]['body']);
        $this->assertNotSame('', $this->sentToken());
        $this->seeNumRecords(1, 'auth_activation', ['auth_id' => $account['id']]);
    }

    public function testPendingAccountCannotSignInUntilActivated(): void
    {
        $this->signUp('Sea Otter', 'sea@otternova.com')->assertStatus(200);

        $response = $this->login('sea@otternova.com');
        $response->assertStatus(403);
        $this->assertSame(lang('Auth.accountPending'), json_decode($response->getJSON(), true)['message']);

        $this->activate($this->sentToken())->assertStatus(200);

        $this->login('sea@otternova.com')->assertStatus(200);
    }

    public function testInvalidInputIsRejectedWithFieldErrors(): void
    {
        $response = $this->signUp('  ', 'not-an-email', 'short');

        $response->assertStatus(422);
        $body = json_decode($response->getJSON(), true);
        $this->assertSame(['full_name', 'email_address', 'password'], array_keys($body['errors']));
        $this->assertSame(lang('Auth.passwordTooShort', [8]), $body['errors']['password']);

        $this->assertSame(lang('Auth.passwordTooWeak'), json_decode($this->signUp('Otter', 'a@b.com', 'lettersonly')->getJSON(), true)['errors']['password']);
        $this->seeNumRecords(0, 'auth_master', []);
        $this->assertSame([], $this->mailer->sent);
    }

    public function testExistingActiveAccountGetsSameAnswerAndANoticeEmail(): void
    {
        $this->createAccount('taken@otternova.com', ['account_type' => 'S', 'account_status' => 'A']);

        $response = $this->signUp('Someone Else', 'taken@otternova.com', 'another password 9');

        $response->assertStatus(200);
        $this->assertSame(lang('Auth.signUpReceived', ['taken@otternova.com']), json_decode($response->getJSON(), true)['message']);
        $this->seeNumRecords(1, 'auth_master', []);
        $this->seeNumRecords(0, 'auth_activation', []);
        $this->assertSame(lang('Email.account_exists.subject'), $this->mailer->sent[0]['subject']);
        $this->assertStringContainsString('http://admin.test/', $this->mailer->sent[0]['body']);
    }

    public function testSigningUpAgainWhilePendingSendsANewLinkAndKeepsTheFirstPassword(): void
    {
        $this->signUp('Sea Otter', 'sea@otternova.com')->assertStatus(200);
        $first = $this->sentToken();

        $this->signUp('Impostor', 'sea@otternova.com', 'impostor password 1')->assertStatus(200);
        $second = $this->sentToken();

        $this->assertNotSame($first, $second);
        $this->seeNumRecords(1, 'auth_master', []);
        $this->seeNumRecords(1, 'auth_activation', []);
        $account = $this->db->table('auth_master')->get()->getRowArray();
        $this->assertSame('Sea Otter', $account['full_name']);
        $this->assertTrue(password_verify(self::PASSWORD, $account['password_hash']));

        $this->activate($first)->assertStatus(410);
        $this->activate($second)->assertStatus(200);
    }

    public function testUidCollisionGetsANumber(): void
    {
        $this->signUp('One', 'a.b@otternova.com')->assertStatus(200);
        $this->signUp('Two', 'ab@otternova.com')->assertStatus(200);

        $this->seeInDatabase('auth_master', ['email_address' => 'a.b@otternova.com', 'uid' => 'abotternovacom']);
        $this->seeInDatabase('auth_master', ['email_address' => 'ab@otternova.com', 'uid' => 'abotternovacom2']);
    }

    public function testSignUpsAreRateLimitedPerEmail(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->signUp('Sea Otter', 'sea@otternova.com')->assertStatus(200);
        }

        $this->signUp('Sea Otter', 'sea@otternova.com')->assertStatus(429);
        $this->assertCount(3, $this->mailer->sent);
    }

    public function testSignUpIsLoggedWithoutSecrets(): void
    {
        $this->signUp('Sea Otter', 'sea@otternova.com')->assertStatus(200);
        $this->activate($this->sentToken())->assertStatus(200);

        $rows = $this->db->table('log_activity')->orderBy('id')->get()->getResultArray();
        $this->assertSame([
            'Signed up as client seaotternovacom',
            'Created client profile of seaotternovacom',
            'Started activation link',
            'Ended activation link',
            'Activated account from the emailed link',
        ], array_column($rows, 'activity_summary'));
        foreach ($rows as $row) {
            $this->assertStringNotContainsString('password_hash', (string) $row['activity_detail']);
            $this->assertStringNotContainsString('token_hash', (string) $row['activity_detail']);
        }
    }

    // ---- /activate-client-account ----

    public function testActivationIsSingleUse(): void
    {
        $this->signUp('Sea Otter', 'sea@otternova.com')->assertStatus(200);
        $token = $this->sentToken();

        $response = $this->activate($token);
        $response->assertStatus(200);
        $this->assertSame(lang('Auth.accountActivated'), json_decode($response->getJSON(), true)['message']);
        $this->seeInDatabase('auth_master', ['email_address' => 'sea@otternova.com', 'account_status' => 'A']);
        $this->seeNumRecords(0, 'auth_activation', []);

        $this->activate($token)->assertStatus(410);
    }

    public function testExpiredUnknownOrMalformedTokenIsGone(): void
    {
        $this->signUp('Sea Otter', 'sea@otternova.com')->assertStatus(200);
        $this->db->table('auth_activation')->update(['expires_at' => time() - 1]);

        $this->activate($this->sentToken())->assertStatus(410);
        $this->seeNumRecords(0, 'auth_activation', []);
        $this->seeInDatabase('auth_master', ['email_address' => 'sea@otternova.com', 'account_status' => 'P']);

        $this->activate(str_repeat('a', 64))->assertStatus(410);
        $this->activate('not a token')->assertStatus(410);
        $this->withBodyFormat('json')->post('v1/activate-client-account', [])->assertStatus(410);
    }

    public function testBlockedAccountIsNotActivated(): void
    {
        $this->signUp('Sea Otter', 'sea@otternova.com')->assertStatus(200);
        $this->db->table('auth_master')->update(['account_status' => 'B']);

        $this->activate($this->sentToken())->assertStatus(410);
        $this->seeInDatabase('auth_master', ['email_address' => 'sea@otternova.com', 'account_status' => 'B']);
    }

    // ---- helpers ----

    private function createAccount(string $email, array $overrides = []): void
    {
        $this->db->table('auth_master')->insert($overrides + ['public_id' => bin2hex(random_bytes(6)), 
            'uid'            => preg_replace('/[^a-z0-9]/', '', $email),
            'email_address'  => $email,
            'password_hash'  => password_hash(self::PASSWORD, PASSWORD_DEFAULT),
            'full_name'      => 'Otter Tester',
            'otp_mode'       => 'X',
            'account_status' => 'A',
            'account_type'   => 'C',
        ]);
    }

    private function signUp(string $fullName, string $email, string $password = self::PASSWORD): TestResponse
    {
        return $this->withBodyFormat('json')->post('v1/create-client-account', [
            'full_name'     => $fullName,
            'email_address' => $email,
            'password'      => $password,
        ]);
    }

    private function activate(string $token): TestResponse
    {
        return $this->withBodyFormat('json')->post('v1/activate-client-account', ['token' => $token]);
    }

    private function login(string $email): TestResponse
    {
        return $this->withBodyFormat('json')->post('v1/login', [
            'email_address' => $email,
            'password'      => self::PASSWORD,
            'account_type'  => 'C',
        ]);
    }

    /**
     * The token of the activation link in the last email
     */
    private function sentToken(): string
    {
        $body = end($this->mailer->sent)['body'];
        $this->assertMatchesRegularExpression('#http://client\.test/activate-account\?token=([0-9a-f]{64})#', $body);
        preg_match('#activate-account\?token=([0-9a-f]{64})#', $body, $matches);

        return $matches[1];
    }
}
