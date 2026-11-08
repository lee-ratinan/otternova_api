<?php

use App\Libraries\EmailService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\Services;

/**
 * POST /request-password-reset, /check-password-reset and /reset-password
 *
 * @internal
 */
final class PasswordResetTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    private const PASSWORD = 'correct horse battery staple';

    private const NEW_PASSWORD = 'otters hold hands 2 sleep';

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

            /** @var list<string|null> the locale passed to each send() */
            public array $locales = [];

            public function send(string $type, string $to, array $data = [], ?string $locale = null): bool
            {
                $this->locales[] = $locale;

                return parent::send($type, $to, $data, $locale);
            }

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

    // ---- /request-password-reset ----

    public function testKnownAndUnknownAddressesGetTheSameAnswer(): void
    {
        $this->createClient('known@otternova.com');

        $known   = $this->request('known@otternova.com');
        $unknown = $this->request('unknown@otternova.com');

        $known->assertStatus(200);
        $unknown->assertStatus(200);
        $this->assertSame(
            lang('Auth.resetRequested', ['unknown@otternova.com']),
            json_decode($unknown->getJSON(), true)['message']
        );
        $this->assertSame(array_keys(json_decode($known->getJSON(), true)), array_keys(json_decode($unknown->getJSON(), true)));

        $this->assertCount(1, $this->mailer->sent);
        $this->assertSame('known@otternova.com', $this->mailer->sent[0]['to']);
        $this->assertSame(lang('Email.password_reset.subject'), $this->mailer->sent[0]['subject']);
        $this->assertNotSame('', $this->sentToken());
        $this->seeNumRecords(1, 'auth_password_reset', []);
    }

    public function testTheLinkIsSentInTheAccountsPreferredLanguage(): void
    {
        $this->createClient('thai@example.com');
        $this->createClient('unset@example.com');
        $authId = (int) $this->db->table('auth_master')->where('email_address', 'thai@example.com')->get()->getRowArray()['id'];
        $this->db->table('client_master')->where('auth_id', $authId)->update(['preferred_language' => 'th-TH']);

        $this->request('thai@example.com')->assertStatus(200);
        $this->request('unset@example.com')->assertStatus(200);

        $this->assertSame(['th-TH', null], $this->mailer->locales);
        $this->assertStringContainsString('ตั้งรหัสผ่านใหม่', $this->mailer->sent[0]['body']);
        // no preferred language: the request's locale, the default one here
        $this->assertStringContainsString('reset the password', $this->mailer->sent[1]['body']);
    }

    public function testOnlyTheRequestedAccountTypeGetsALink(): void
    {
        $this->createClient('staff@otternova.com', ['account_type' => 'S']);

        $this->request('staff@otternova.com')->assertStatus(200);
        $this->assertSame([], $this->mailer->sent);

        $this->request('staff@otternova.com', 'S')->assertStatus(200);
        $this->assertStringContainsString('http://admin.test/reset-password?token=', $this->mailer->sent[0]['body']);
    }

    public function testBlockedOrSuspendedAccountGetsNoLink(): void
    {
        $this->createClient('blocked@otternova.com', ['account_status' => 'B']);
        $this->createClient('suspended@otternova.com', ['account_status' => 'S']);

        $this->request('blocked@otternova.com')->assertStatus(200);
        $this->request('suspended@otternova.com')->assertStatus(200);

        $this->assertSame([], $this->mailer->sent);
        $this->seeNumRecords(0, 'auth_password_reset', []);
    }

    public function testInvalidInputIsRejected(): void
    {
        $this->request('not-an-email')->assertStatus(422);
        $this->request('a@b.com', 'Z')->assertStatus(400);
        $this->assertSame([], $this->mailer->sent);
    }

    public function testNewRequestReplacesTheOldLink(): void
    {
        $this->createClient('known@otternova.com');

        $this->request('known@otternova.com');
        $first = $this->sentToken();
        $this->request('known@otternova.com');
        $second = $this->sentToken();

        $this->seeNumRecords(1, 'auth_password_reset', []);
        $this->check($first)->assertStatus(410);
        $this->check($second)->assertStatus(200);
    }

    public function testRequestsAreRateLimitedPerEmailWhetherOrNotItHasAnAccount(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->request('unknown@otternova.com')->assertStatus(200);
        }

        $this->request('unknown@otternova.com')->assertStatus(429);
    }

    // ---- /check-password-reset ----

    public function testCheckChangesNothing(): void
    {
        $this->createClient('known@otternova.com');
        $this->request('known@otternova.com');
        $token = $this->sentToken();

        $this->check($token)->assertStatus(200);
        $this->check($token)->assertStatus(200);
        $this->seeNumRecords(1, 'auth_password_reset', []);
    }

    public function testExpiredUnknownOrMalformedTokenIsGone(): void
    {
        $this->createClient('known@otternova.com');
        $this->request('known@otternova.com');
        $token = $this->sentToken();
        $this->db->table('auth_password_reset')->update(['expires_at' => time() - 1]);

        $response = $this->check($token);
        $response->assertStatus(410);
        $this->assertSame(lang('Auth.resetInvalid'), json_decode($response->getJSON(), true)['message']);
        $this->seeNumRecords(0, 'auth_password_reset', []);

        $this->check(str_repeat('a', 64))->assertStatus(410);
        $this->check('not a token')->assertStatus(410);
        $this->withBodyFormat('json')->post('v1/check-password-reset', [])->assertStatus(410);
        $this->reset(str_repeat('a', 64))->assertStatus(410);
    }

    // ---- /reset-password ----

    public function testResetSetsTheNewPasswordOnce(): void
    {
        $this->createClient('known@otternova.com', ['password_expiry' => gmdate('Y-m-d', time() - DAY)]);
        $this->request('known@otternova.com');
        $token = $this->sentToken();

        $response = $this->reset($token);
        $response->assertStatus(200);
        $this->assertSame(lang('Auth.passwordReset'), json_decode($response->getJSON(), true)['message']);

        $account = $this->db->table('auth_master')->get()->getRowArray();
        $this->assertTrue(password_verify(self::NEW_PASSWORD, $account['password_hash']));
        $this->assertGreaterThan(gmdate('Y-m-d'), $account['password_expiry']);
        $this->seeNumRecords(0, 'auth_password_reset', []);

        $this->reset($token, 'yet another password 3')->assertStatus(410);
        $this->login('known@otternova.com', self::NEW_PASSWORD)->assertStatus(200);
        $this->login('known@otternova.com', self::PASSWORD)->assertStatus(401);
    }

    public function testWeakOrSamePasswordIsRejectedAndTheLinkStaysUsable(): void
    {
        $this->createClient('known@otternova.com');
        $this->request('known@otternova.com');
        $token = $this->sentToken();

        $short = $this->reset($token, 'short');
        $short->assertStatus(422);
        $this->assertSame(lang('Auth.passwordTooShort', [8]), json_decode($short->getJSON(), true)['errors']['new_password']);
        $this->reset($token, 'lettersonly')->assertStatus(422);
        $this->reset($token, self::PASSWORD . ' 1')->assertStatus(200);
    }

    public function testSameAsCurrentPasswordIsRejected(): void
    {
        $this->createClient('known@otternova.com', ['password_hash' => password_hash(self::NEW_PASSWORD, PASSWORD_DEFAULT)]);
        $this->request('known@otternova.com');

        $response = $this->reset($this->sentToken());

        $response->assertStatus(422);
        $this->assertSame(lang('Auth.passwordSameAsOld'), json_decode($response->getJSON(), true)['message']);
    }

    public function testResettingAPendingAccountActivatesIt(): void
    {
        $this->withBodyFormat('json')->post('v1/create-client-account', [
            'full_name'     => 'Sea Otter',
            'email_address' => 'sea@otternova.com',
            'password'      => 'forgotten password 1',
        ])->assertStatus(200);
        $this->request('sea@otternova.com');

        $this->reset($this->sentToken())->assertStatus(200);

        $this->seeInDatabase('auth_master', ['email_address' => 'sea@otternova.com', 'account_status' => 'A']);
        $this->seeNumRecords(0, 'auth_activation', []);
    }

    public function testAccountBlockedAfterTheLinkWasSentIsRejected(): void
    {
        $this->createClient('known@otternova.com');
        $this->request('known@otternova.com');
        $this->db->table('auth_master')->update(['account_status' => 'B']);

        $this->reset($this->sentToken())->assertStatus(410);
        $this->seeNumRecords(0, 'auth_password_reset', []);
    }

    public function testResetIsLoggedWithoutSecrets(): void
    {
        $this->createClient('known@otternova.com');
        $this->request('known@otternova.com');
        $this->reset($this->sentToken())->assertStatus(200);

        $rows = $this->db->table('log_activity')->orderBy('id')->get()->getResultArray();
        $this->assertSame(
            ['Started password reset link', 'Ended password reset link', 'Reset forgotten password'],
            array_column($rows, 'activity_summary')
        );
        foreach ($rows as $row) {
            $this->assertStringNotContainsString('$2y$', (string) $row['activity_detail']);
            $this->assertStringNotContainsString('token_hash', (string) $row['activity_detail']);
        }
    }

    // ---- helpers ----

    private function createClient(string $email, array $overrides = []): void
    {
        $this->db->table('auth_master')->insert($overrides + [
            'uid'            => preg_replace('/[^a-z0-9]/', '', $email),
            'email_address'  => $email,
            'password_hash'  => password_hash(self::PASSWORD, PASSWORD_DEFAULT),
            'full_name'      => 'Otter Tester',
            'otp_mode'       => 'X',
            'account_status' => 'A',
            'account_type'   => 'C',
        ]);
        $id = (int) $this->db->insertID();
        if (($overrides['account_type'] ?? 'C') === 'S') {
            if ($this->db->table('otternova_department')->countAllResults() === 0) {
                $this->db->table('otternova_department')->insert(['department_code' => 'ENG', 'department_name' => 'Engineering', 'country_code' => 'TH']);
            }
            $this->db->table('staff_master')->insert([
                'auth_id' => $id, 'department_id' => 1, 'employee_code' => 'EMP-' . $id, 'job_title' => 'Engineer',
                'employment_status' => 'A', 'employment_start_date' => '2024-01-01', 'work_country_code' => 'TH',
                'legal_first_name' => 'Otter', 'legal_last_name' => 'Tester', 'gender' => 'N', 'date_of_birth' => '1990-01-01',
            ]);
        } else {
            $this->db->table('client_master')->insert(['auth_id' => $id]);
        }
    }

    private function request(string $email, string $accountType = 'C'): TestResponse
    {
        return $this->withBodyFormat('json')->post('v1/request-password-reset', ['email_address' => $email, 'account_type' => $accountType]);
    }

    private function check(string $token): TestResponse
    {
        return $this->withBodyFormat('json')->post('v1/check-password-reset', ['token' => $token]);
    }

    private function reset(string $token, string $newPassword = self::NEW_PASSWORD): TestResponse
    {
        return $this->withBodyFormat('json')->post('v1/reset-password', ['token' => $token, 'new_password' => $newPassword]);
    }

    private function login(string $email, string $password): TestResponse
    {
        return $this->withBodyFormat('json')->post('v1/login', ['email_address' => $email, 'password' => $password, 'account_type' => 'C']);
    }

    /**
     * The token of the reset link in the last email
     */
    private function sentToken(): string
    {
        $body = end($this->mailer->sent)['body'];
        $this->assertMatchesRegularExpression('#http://(client|admin)\.test/reset-password\?token=([0-9a-f]{64})#', $body);
        preg_match('#reset-password\?token=([0-9a-f]{64})#', $body, $matches);

        return $matches[1];
    }
}
