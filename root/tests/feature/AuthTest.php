<?php

use App\Libraries\EmailService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestLogger;
use CodeIgniter\Test\TestResponse;
use Config\Auth as AuthConfig;
use Config\Services;
use Firebase\JWT\JWT;

/**
 * POST /login, /verify-otp and /logout, and the jwt filter
 *
 * @internal
 */
final class AuthTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    private const PASSWORD = 'correct horse battery staple';

    protected $namespace = 'App'; // the app's own migrations, the schema of the real database

    /** Captures emails instead of logging them */
    private EmailService $mailer;

    protected function setUp(): void
    {
        parent::setUp();

        // shared services built in an earlier test would still hold that test's cache and config
        foreach (['loginThrottle', 'jwt', 'authContext'] as $service) {
            Services::resetSingle($service);
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

    // ---- /login ----

    public function testLoginWithoutOtpSignsInStraightAway(): void
    {
        $this->createStaff('none@otternova.com', ['otp_mode' => 'X']);

        $data = $this->json($this->login('none@otternova.com'), 200);

        $this->assertFalse($data['otp_required']);
        $this->assertNotEmpty($data['access_token']);
        $this->assertSame([
            'id', 'uid', 'email_address', 'telephone_number', 'full_name', 'avatar_path', 'otp_mode',
            'account_status', 'account_type', 'password_expiry', 'created_at', 'updated_at', 'employee_code',
            'job_title', 'employment_status', 'gender', 'date_of_birth', 'status_message',
        ], array_keys($data['user']));
        $this->assertSame('none@otternova.com', $data['user']['email_address']);
        $this->assertNull($data['user']['avatar_path']); // empty: the app shows its default avatar
        $this->assertSame('EMP-1', $data['user']['employee_code']);
        $this->assertIsInt($data['user']['id']);

        $claims = service('jwt')->decode($data['access_token']);
        $this->assertSame($data['user']['uid'], $claims->sub);
        $this->assertSame('S', $claims->typ);
        $this->assertSame($data['expires_at'], $claims->exp);
        $this->assertLessThanOrEqual(8 * HOUR, $claims->exp - $claims->iat);
        $this->assertSame([], $this->mailer->sent);
    }

    public function testLoginWithTelephoneOtpSignsInStraightAway(): void
    {
        $this->createStaff('tel@otternova.com', ['otp_mode' => 'T']);

        $data = $this->json($this->login('tel@otternova.com'), 200);

        $this->assertFalse($data['otp_required']);
        $this->assertNotEmpty($data['access_token']);
        $this->assertSame([], $this->mailer->sent);
    }

    public function testClientLoginReturnsClientProfile(): void
    {
        $this->createClient('client@example.com', ['otp_mode' => 'X']);

        $data = $this->json($this->login('client@example.com', self::PASSWORD, 'C'), 200);

        $this->assertSame('C', $data['user']['account_type']);
        $this->assertSame('Otter Fan', $data['user']['display_name']);
        $this->assertArrayNotHasKey('employee_code', $data['user']);
        $this->assertSame('C', service('jwt')->decode($data['access_token'])->typ);
    }

    public function testLoginWithEmailOtpReturnsChallengeAndEmailsCode(): void
    {
        $this->createStaff('otp@otternova.com', ['otp_mode' => 'E']);

        $data = $this->json($this->login('otp@otternova.com'), 200);

        $this->assertTrue($data['otp_required']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $data['otp_token']);
        $this->assertEqualsWithDelta(time() + 5 * MINUTE, $data['otp_expires_at'], 2);
        $this->assertArrayNotHasKey('access_token', $data);

        $this->assertCount(1, $this->mailer->sent);
        $this->assertSame('otp@otternova.com', $this->mailer->sent[0]['to']);
        $otp = $this->sentOtp();

        // only hashes are stored
        $row = $this->db->table('auth_otp_challenge')->get()->getRowArray();
        $this->assertNotSame($data['otp_token'], $row['token_hash']);
        $this->assertStringNotContainsString($otp, $row['otp_hash']);
        $this->assertTrue(password_verify($otp, $row['otp_hash']));
    }

    public function testWrongPasswordUnknownEmailAndWrongTypeGetTheSameAnswer(): void
    {
        $this->createStaff('staff@otternova.com', ['otp_mode' => 'X']);
        $this->createClient('client@example.com', ['otp_mode' => 'X']);
        $this->createAccount('noprofile@otternova.com', ['account_type' => 'S', 'otp_mode' => 'X']);

        $responses = [
            $this->login('staff@otternova.com', 'wrong password'),
            $this->login('nobody@otternova.com'),
            $this->login('client@example.com', self::PASSWORD, 'S'), // a client trying the Admin app
            $this->login('staff@otternova.com', self::PASSWORD, 'C'),
            $this->login('noprofile@otternova.com'),                 // staff without a staff_master row
        ];

        foreach ($responses as $response) {
            $response->assertStatus(401);
            $this->assertSame(['status' => 'error', 'message' => lang('Auth.invalidCredentials')], json_decode($response->getJSON(), true));
        }
    }

    public function testInactiveAccountIsRejected(): void
    {
        foreach (['P', 'B', 'S'] as $i => $status) {
            $this->createStaff("inactive{$i}@otternova.com", ['account_status' => $status, 'otp_mode' => 'X']);

            $response = $this->login("inactive{$i}@otternova.com");

            $response->assertStatus(403);
            $this->assertSame(lang('Auth.accountNotActive'), $this->errorMessage($response));
        }
    }

    public function testMissingFieldsAreRejected(): void
    {
        $this->withBodyFormat('json')->post('v1/login', ['email_address' => 'a@b.com'])->assertStatus(400);
        $this->withBodyFormat('json')->post('v1/login', ['email_address' => 'a@b.com', 'password' => 'x', 'account_type' => 'Z'])->assertStatus(400);
    }

    public function testFailedLoginsAreRateLimitedPerEmail(): void
    {
        $this->createStaff('limited@otternova.com', ['otp_mode' => 'X']);

        for ($i = 0; $i < 5; $i++) {
            $this->login('limited@otternova.com', 'wrong password')->assertStatus(401);
        }

        // blocked even with the right password now
        $response = $this->login('limited@otternova.com');
        $response->assertStatus(429);
        $this->assertSame(lang('Auth.tooManyAttempts'), $this->errorMessage($response));
    }

    public function testFailedLoginsAreRateLimitedPerIp(): void
    {
        $this->createStaff('victim@otternova.com', ['otp_mode' => 'X']);

        for ($i = 0; $i < 20; $i++) {
            $this->login("guess{$i}@otternova.com", 'wrong password')->assertStatus(401);
        }

        $this->login('victim@otternova.com')->assertStatus(429);
    }

    // ---- /verify-otp ----

    public function testCorrectOtpSignsInOnce(): void
    {
        $this->createStaff('otp@otternova.com', ['otp_mode' => 'E']);
        $token = $this->json($this->login('otp@otternova.com'), 200)['otp_token'];

        $data = $this->json($this->verifyOtp($token, $this->sentOtp()), 200);

        $this->assertFalse($data['otp_required']);
        $this->assertNotEmpty($data['access_token']);
        $this->assertSame('otp@otternova.com', $data['user']['email_address']);

        // single use
        $this->verifyOtp($token, $this->sentOtp())->assertStatus(410);
    }

    public function testWrongOtpCountsAnAttempt(): void
    {
        $this->createStaff('otp@otternova.com', ['otp_mode' => 'E']);
        $token = $this->json($this->login('otp@otternova.com'), 200)['otp_token'];

        $response = $this->verifyOtp($token, $this->wrongOtp());

        $response->assertStatus(401);
        $this->assertSame(lang('Auth.otpIncorrect'), $this->errorMessage($response));
        $this->assertSame(1, (int) $this->db->table('auth_otp_challenge')->get()->getRowArray()['attempts']);

        // still usable
        $this->verifyOtp($token, $this->sentOtp())->assertStatus(200);
    }

    public function testExpiredOtpIsGone(): void
    {
        $this->createStaff('otp@otternova.com', ['otp_mode' => 'E']);
        $token = $this->json($this->login('otp@otternova.com'), 200)['otp_token'];
        $this->db->table('auth_otp_challenge')->update(['expires_at' => time() - 1]);

        $response = $this->verifyOtp($token, $this->sentOtp());

        $response->assertStatus(410);
        $this->assertSame(lang('Auth.otpExpired'), $this->errorMessage($response));
    }

    public function testUnknownOtpTokenIsGone(): void
    {
        $this->verifyOtp(str_repeat('a', 64), '123456')->assertStatus(410);
    }

    public function testOtpIsInvalidatedAfterFiveWrongCodes(): void
    {
        $this->createStaff('otp@otternova.com', ['otp_mode' => 'E']);
        $token = $this->json($this->login('otp@otternova.com'), 200)['otp_token'];

        for ($i = 0; $i < 5; $i++) {
            $this->verifyOtp($token, $this->wrongOtp())->assertStatus(401);
        }

        $this->seeNumRecords(0, 'auth_otp_challenge', []);
        $this->verifyOtp($token, $this->sentOtp())->assertStatus(410);
    }

    // ---- jwt filter and /logout ----

    public function testLogoutRevokesTheToken(): void
    {
        $this->createStaff('staff@otternova.com', ['otp_mode' => 'X']);
        $data = $this->json($this->login('staff@otternova.com'), 200);

        $response = $this->logout($data['access_token']);
        $response->assertStatus(200);
        $this->assertSame(['status' => 'success'], json_decode($response->getJSON(), true));
        $this->seeInDatabase('auth_token_denylist', ['jti' => service('jwt')->decode($data['access_token'])->jti]);

        $response = $this->logout($data['access_token']);
        $response->assertStatus(401);
        $this->assertSame(lang('Auth.sessionExpired'), $this->errorMessage($response));
    }

    public function testTokenPastExpIsRejected(): void
    {
        $account = $this->createStaff('staff@otternova.com', ['otp_mode' => 'X']);
        $token   = $this->signToken(['sub' => $account['uid'], 'iat' => time() - 9 * HOUR, 'nbf' => time() - 9 * HOUR, 'exp' => time() - HOUR]);

        $response = $this->logout($token);

        $response->assertStatus(401);
        $this->assertSame(lang('Auth.sessionExpired'), $this->errorMessage($response));
    }

    public function testTokenBeforeNbfIsRejected(): void
    {
        $account = $this->createStaff('staff@otternova.com', ['otp_mode' => 'X']);

        $this->logout($this->signToken(['sub' => $account['uid'], 'nbf' => time() + HOUR]))->assertStatus(401);
    }

    public function testTamperedOrMissingTokenIsRejected(): void
    {
        $account = $this->createStaff('staff@otternova.com', ['otp_mode' => 'X']);
        $forged  = JWT::encode($this->claims(['sub' => $account['uid']]), str_repeat('x', 32), 'HS256');

        $this->logout($forged)->assertStatus(401);
        $this->logout('not-a-jwt')->assertStatus(401);
        $this->post('v1/logout')->assertStatus(401);
    }

    public function testAccountDeactivatedAfterSignInIsRejected(): void
    {
        $this->createStaff('staff@otternova.com', ['otp_mode' => 'X']);
        $data = $this->json($this->login('staff@otternova.com'), 200);
        $this->db->table('auth_master')->update(['account_status' => 'S']);

        $response = $this->logout($data['access_token']);

        $response->assertStatus(403);
        $this->assertSame(lang('Auth.accountNotActive'), $this->errorMessage($response));
    }

    // ---- staff and client route groups ----

    public function testEachGroupLetsInOnlyItsOwnAccountType(): void
    {
        $this->createStaff('staff@otternova.com', ['otp_mode' => 'X']);
        $this->createClient('client@example.com', ['otp_mode' => 'X']);
        $staffToken  = $this->json($this->login('staff@otternova.com'), 200)['access_token'];
        $clientToken = $this->json($this->login('client@example.com', self::PASSWORD, 'C'), 200)['access_token'];

        $staff = $this->json($this->getWithToken('v1/staff/me', $staffToken), 200)['user'];
        $this->assertSame('staff@otternova.com', $staff['email_address']);
        $this->assertArrayNotHasKey('password_hash', $staff);

        $client = $this->json($this->getWithToken('v1/client/me', $clientToken), 200)['user'];
        $this->assertSame('client@example.com', $client['email_address']);

        foreach ([['v1/staff/me', $clientToken], ['v1/client/me', $staffToken]] as [$path, $token]) {
            $response = $this->getWithToken($path, $token);
            $response->assertStatus(403);
            $this->assertSame(lang('Auth.wrongAccountType'), $this->errorMessage($response));
        }
    }

    public function testGroupsRejectMissingExpiredAndRevokedTokens(): void
    {
        $account = $this->createStaff('staff@otternova.com', ['otp_mode' => 'X']);
        $expired = $this->signToken(['sub' => $account['uid'], 'iat' => time() - 9 * HOUR, 'nbf' => time() - 9 * HOUR, 'exp' => time() - HOUR]);
        $revoked = $this->json($this->login('staff@otternova.com'), 200)['access_token'];
        $this->logout($revoked)->assertStatus(200);

        $this->get('v1/staff/me')->assertStatus(401);
        $this->getWithToken('v1/staff/me', $expired)->assertStatus(401);
        $this->getWithToken('v1/staff/me', $revoked)->assertStatus(401);
        $this->getWithToken('v1/client/me', $expired)->assertStatus(401);
    }

    public function testTokenLifetimeIsCappedAtEightHours(): void
    {
        putenv('JWT_TTL=' . (24 * HOUR));
        try {
            $this->assertSame(8 * HOUR, (new AuthConfig())->jwtTtl);
        } finally {
            putenv('JWT_TTL');
        }
    }

    // ---- nothing secret leaks ----

    public function testNoPasswordHashOrOtpLeaksIntoResponsesOrLogs(): void
    {
        Services::injectMock('emailService', new EmailService()); // the real one, which logs the email
        $account = $this->createStaff('otp@otternova.com', ['otp_mode' => 'E']);
        $hash    = $this->db->table('auth_master')->where('id', $account['id'])->get()->getRowArray()['password_hash'];

        $responses = [$this->login('otp@otternova.com', 'wrong password'), $this->login('otp@otternova.com')];
        $token     = $this->json($responses[1], 200)['otp_token'];

        $emailLogs = array_values(array_filter($this->logs(), static fn ($line) => str_starts_with($line, 'Email to otp@otternova.com')));
        $this->assertCount(1, $emailLogs);
        $this->assertMatchesRegularExpression('/code is (\d{6})\./', $emailLogs[0]);
        preg_match('/code is (\d{6})\./', $emailLogs[0], $matches);
        $otp = $matches[1];

        $responses[] = $this->verifyOtp($token, $otp);
        $responses[1]->assertStatus(200);
        end($responses)->assertStatus(200);

        foreach ($responses as $response) {
            $body = $response->getJSON();
            $this->assertStringNotContainsString(self::PASSWORD, $body);
            $this->assertStringNotContainsString($hash, $body);
            $this->assertStringNotContainsString('password_hash', $body);
            $this->assertStringNotContainsString('admin_notes', $body);
            $this->assertStringNotContainsString('"' . $otp . '"', $body);
        }
        foreach ($this->logs() as $line) {
            $this->assertStringNotContainsString(self::PASSWORD, $line);
            $this->assertStringNotContainsString($hash, $line);
            if ($line !== $emailLogs[0]) {
                $this->assertStringNotContainsString($otp, $line);
            }
        }
    }

    // ---- log_activity ----

    public function testOtpSignInIsLoggedWithoutHashes(): void
    {
        $account = $this->createStaff('otp@otternova.com', ['otp_mode' => 'E']);
        $token   = $this->json($this->login('otp@otternova.com'), 200)['otp_token'];
        $row     = $this->db->table('auth_otp_challenge')->get()->getRowArray();

        $this->verifyOtp($token, $this->wrongOtp())->assertStatus(401);
        $this->verifyOtp($token, $this->sentOtp())->assertStatus(200);

        $logs = $this->activity();
        $this->assertSame([
            ['Started OTP challenge on sign-in', 'insert'],
            ['Used an OTP attempt', 'update'],
            ['Used an OTP attempt', 'update'],
            ['Ended OTP challenge', 'delete'],
        ], array_map(static fn ($log) => [$log['activity_summary'], $log['detail']['action']], $logs));
        foreach ($logs as $log) {
            $this->assertSame('auth_otp_challenge', $log['table_involved']);
            $this->assertSame((int) $row['id'], (int) $log['table_id_updated']);
            $this->assertSame($account['id'], (int) $log['changed_by_acc']);
            $this->assertStringNotContainsString($row['otp_hash'], $log['activity_detail']);
            $this->assertStringNotContainsString($row['token_hash'], $log['activity_detail']);
            $this->assertStringNotContainsString($token, $log['activity_detail']);
        }
        $this->assertSame(['auth_id', 'account_type', 'attempts', 'expires_at'], array_keys($logs[0]['detail']['new']));
        $this->assertSame(['attempts' => ['old' => 1, 'new' => 2]], $logs[2]['detail']['changes']);
    }

    public function testReplacedAndExpiredChallengesAreLogged(): void
    {
        $account = $this->createStaff('otp@otternova.com', ['otp_mode' => 'E']);
        $other   = $this->createStaff('other@otternova.com', ['otp_mode' => 'E']);
        $this->login('other@otternova.com')->assertStatus(200);
        $this->db->table('auth_otp_challenge')->update(['expires_at' => time() - 1]);
        $this->login('otp@otternova.com')->assertStatus(200);
        $this->login('otp@otternova.com')->assertStatus(200);

        $logs = array_map(static fn ($log) => [$log['activity_summary'], $log['changed_by_acc'] === null ? null : (int) $log['changed_by_acc']], $this->activity());

        $this->assertSame([
            ['Started OTP challenge on sign-in', $other['id']],
            ['Deleted expired OTP challenge', null], // the system cleaning up, not the account signing in
            ['Started OTP challenge on sign-in', $account['id']],
            ['Replaced OTP challenge on sign-in', $account['id']],
            ['Started OTP challenge on sign-in', $account['id']],
        ], $logs);
    }

    public function testLogoutIsLogged(): void
    {
        $account = $this->createStaff('staff@otternova.com', ['otp_mode' => 'X']);
        $token   = $this->json($this->login('staff@otternova.com'), 200)['access_token'];
        $this->db->table('auth_token_denylist')->insert(['jti' => 'old-jti', 'expires_at' => time() - 1]);

        $this->logout($token)->assertStatus(200);

        $logs = $this->activity();
        $this->assertSame(['Deleted expired token from the denylist', null, 'old-jti'], [$logs[0]['activity_summary'], $logs[0]['changed_by_acc'], $logs[0]['detail']['old']['jti']]);
        $this->assertSame('Signed out', $logs[1]['activity_summary']);
        $this->assertSame('auth_token_denylist', $logs[1]['table_involved']);
        $this->assertSame($account['id'], (int) $logs[1]['changed_by_acc']);
        $this->assertSame(service('jwt')->decode($token)->jti, $logs[1]['detail']['new']['jti']);
    }

    public function testSignInWithoutOtpWritesNothing(): void
    {
        $this->createStaff('none@otternova.com', ['otp_mode' => 'X']);

        $this->login('none@otternova.com')->assertStatus(200);
        $this->login('none@otternova.com', 'wrong password')->assertStatus(401);

        $this->assertSame([], $this->activity());
    }

    // ---- helpers ----

    private function createAccount(string $email, array $overrides = []): array
    {
        $account = $overrides + [
            'uid'              => preg_replace('/[^a-z0-9]/', '', $email),
            'email_address'    => $email,
            'telephone_number' => null,
            'password_hash'    => password_hash(self::PASSWORD, PASSWORD_DEFAULT),
            'password_expiry'  => '2027-01-01',
            'full_name'        => 'Otter Tester',
            'otp_mode'         => 'E',
            'account_status'   => 'A',
            'account_type'     => 'S',
            'admin_notes'      => 'internal note',
        ];
        $this->db->table('auth_master')->insert($account);
        $account['id'] = $this->db->insertID();

        return $account;
    }

    private function createStaff(string $email, array $overrides = []): array
    {
        $account = $this->createAccount($email, $overrides + ['account_type' => 'S']);
        if ($this->db->table('otternova_department')->countAllResults() === 0) {
            $this->db->table('otternova_department')->insert(['department_code' => 'ENG', 'department_name' => 'Engineering', 'country_code' => 'TH']);
        }
        $this->db->table('staff_master')->insert([
            'auth_id'               => $account['id'],
            'department_id'         => 1,
            'employee_code'         => 'EMP-' . $account['id'],
            'job_title'             => 'Engineer',
            'employment_status'     => 'A',
            'employment_start_date' => '2024-01-01',
            'work_country_code'     => 'TH',
            'legal_first_name'      => 'Otter',
            'legal_last_name'       => 'Tester',
            'gender'                => 'N',
            'date_of_birth'         => '1990-01-01',
        ]);

        return $account;
    }

    private function createClient(string $email, array $overrides = []): array
    {
        $account = $this->createAccount($email, $overrides + ['account_type' => 'C']);
        $this->db->table('client_master')->insert([
            'auth_id'            => $account['id'],
            'display_name'       => 'Otter Fan',
            'status_emoji'       => '🦦',
            'preferred_language' => 'en',
        ]);

        return $account;
    }

    private function login(string $email, string $password = self::PASSWORD, string $accountType = 'S'): TestResponse
    {
        return $this->withBodyFormat('json')->post('v1/login', [
            'email_address' => $email,
            'password'      => $password,
            'account_type'  => $accountType,
        ]);
    }

    private function verifyOtp(string $token, string $otp): TestResponse
    {
        return $this->withBodyFormat('json')->post('v1/verify-otp', ['otp_token' => $token, 'otp' => $otp]);
    }

    private function logout(string $accessToken): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . $accessToken])->post('v1/logout');
    }

    private function getWithToken(string $path, string $accessToken): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . $accessToken])->get($path);
    }

    /**
     * The data of a success response
     */
    private function json(TestResponse $response, int $status): array
    {
        $response->assertStatus($status);
        $body = json_decode($response->getJSON(), true);
        $this->assertSame('success', $body['status']);

        return $body['data'];
    }

    private function errorMessage(TestResponse $response): string
    {
        $body = json_decode($response->getJSON(), true);
        $this->assertSame('error', $body['status']);

        return $body['message'];
    }

    private function sentOtp(): string
    {
        $this->assertMatchesRegularExpression('/code is (\d{6})\./', end($this->mailer->sent)['body']);
        preg_match('/code is (\d{6})\./', end($this->mailer->sent)['body'], $matches);

        return $matches[1];
    }

    private function wrongOtp(): string
    {
        return $this->sentOtp() === '000000' ? '111111' : '000000';
    }

    private function claims(array $overrides): array
    {
        return $overrides + [
            'iss' => config(AuthConfig::class)->jwtIssuer,
            'typ' => 'S',
            'iat' => time(),
            'nbf' => time(),
            'exp' => time() + HOUR,
            'jti' => bin2hex(random_bytes(16)),
        ];
    }

    private function signToken(array $overrides): string
    {
        return JWT::encode($this->claims($overrides), config(AuthConfig::class)->jwtSecret, 'HS256');
    }

    /**
     * The log_activity rows, oldest first, with activity_detail decoded as 'detail'
     */
    private function activity(): array
    {
        $rows = $this->db->table('log_activity')->orderBy('id')->get()->getResultArray();

        return array_map(static fn ($row) => $row + ['detail' => json_decode((string) $row['activity_detail'], true)], $rows);
    }

    /**
     * Every message logged during the test
     *
     * @return list<string>
     */
    private function logs(): array
    {
        return array_column((new ReflectionProperty(TestLogger::class, 'op_logs'))->getValue(), 'message');
    }
}
