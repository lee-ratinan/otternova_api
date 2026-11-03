<?php

use App\Libraries\EmailService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestLogger;
use CodeIgniter\Test\TestResponse;
use Config\Auth as AuthConfig;
use Config\ImageUpload;
use Config\Services;
use Firebase\JWT\JWT;

/**
 * POST /login, /verify-otp, /change-expired-password and /logout, and the jwt filter
 *
 * @internal
 */
final class AuthTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    private const PASSWORD = 'correct horse battery staple';

    private const NEW_PASSWORD = 'otters hold hands 2 sleep';

    protected $namespace = 'App'; // the app's own migrations, the schema of the real database

    /** Captures emails instead of logging them */
    private EmailService $mailer;

    /** Stands in for writable/uploads/, where the initials avatars are made */
    private string $uploads;

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

        $this->uploads                            = sys_get_temp_dir() . '/otternova-auth-' . bin2hex(random_bytes(4)) . '/';
        config(ImageUpload::class)->uploadPath = $this->uploads;
    }

    protected function tearDown(): void
    {
        foreach (glob($this->uploads . 'initials-avatar/*.svg') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->uploads . 'initials-avatar');
        @rmdir($this->uploads);

        parent::tearDown();
    }

    // ---- /login ----

    public function testLoginWithoutOtpSignsInStraightAway(): void
    {
        $this->createStaff('none@otternova.com', ['otp_mode' => 'X']);

        $data = $this->json($this->login('none@otternova.com'), 200);

        $this->assertFalse($data['otp_required']);
        $this->assertArrayNotHasKey('business', $data);
        $this->assertNotEmpty($data['access_token']);
        $this->assertSame([
            'id', 'uid', 'email_address', 'telephone_number', 'full_name', 'avatar_path', 'otp_mode',
            'account_status', 'account_type', 'password_expiry', 'created_at', 'updated_at', 'employee_code',
            'job_title', 'employment_status', 'gender', 'date_of_birth', 'status_message', 'department_code',
        ], array_keys($data['user']));
        $this->assertSame('none@otternova.com', $data['user']['email_address']);
        // no picture uploaded: the initials avatar of "Otter Tester"
        $this->assertSame(base_url('image/initials-avatar/OT.svg'), $data['user']['avatar_path']);
        $this->assertFileExists($this->uploads . 'initials-avatar/OT.svg');
        $this->assertNull($this->db->table('auth_master')->select('avatar_path')->where('email_address', 'none@otternova.com')->get()->getRow()->avatar_path);
        $this->assertSame('EMP-1', $data['user']['employee_code']);
        $this->assertSame('ENG', $data['user']['department_code']);
        $this->assertIsInt($data['user']['id']);

        $claims = service('jwt')->decode($data['access_token']);
        $this->assertSame($data['user']['uid'], $claims->sub);
        $this->assertSame('S', $claims->typ);
        $this->assertSame($data['expires_at'], $claims->exp);
        $this->assertLessThanOrEqual(8 * HOUR, $claims->exp - $claims->iat);
        $this->assertSame([], $this->mailer->sent);
    }

    public function testUsersWithTheSameInitialsShareOneAvatarAndAnUploadedOneIsKept(): void
    {
        $this->createStaff('mjw@otternova.com', ['otp_mode' => 'X', 'full_name' => 'Mary Jane Watson']);
        $this->createStaff('mw@otternova.com', ['otp_mode' => 'X', 'full_name' => 'Molly Weasley']);
        $this->createStaff('pic@otternova.com', ['otp_mode' => 'X', 'full_name' => 'Has Picture', 'avatar_path' => 'https://example.com/me.webp']);

        $first = $this->json($this->login('mjw@otternova.com'), 200)['user']['avatar_path'];
        $svg   = file_get_contents($this->uploads . 'initials-avatar/MW.svg');
        touch($this->uploads . 'initials-avatar/MW.svg', 1_000_000);
        $this->assertSame($first, $this->json($this->login('mw@otternova.com'), 200)['user']['avatar_path']);
        clearstatcache();
        $this->assertSame(1_000_000, filemtime($this->uploads . 'initials-avatar/MW.svg')); // re-used, not re-made
        $this->assertSame($svg, file_get_contents($this->uploads . 'initials-avatar/MW.svg'));
        $this->assertSame(['MW.svg'], array_map('basename', glob($this->uploads . 'initials-avatar/*')));

        $this->assertSame('https://example.com/me.webp', $this->json($this->login('pic@otternova.com'), 200)['user']['avatar_path']);
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
        $this->assertNull($data['business']);
    }

    public function testClientLoginReturnsTheirSelectedBusiness(): void
    {
        $account  = $this->createClient('client@example.com', ['otp_mode' => 'X']);
        $clientId = (int) $this->db->table('client_master')->where('auth_id', $account['id'])->get()->getRowArray()['id'];
        $this->db->table('business_type')->insert(['public_id' => 'cafe', 'type_code' => 'cafe']);
        $this->db->table('business_master')->insert([
            'business_type_id'      => $this->db->insertID(),
            'business_name'         => 'OtterCafe',
            'business_slug'         => 'ottercafe-sg',
            'contact_email_address' => 'support@example.com',
            'country_code'          => 'SG',
            'currency_code'         => 'SGD',
        ]);
        $this->db->table('business_client_link')->insert([
            'business_id'         => $this->db->insertID(),
            'client_id'           => $clientId,
            'client_role'         => 'O',
            'is_default_business' => 'Y',
            'start_date'          => '2026-11-01',
        ]);

        $data = $this->json($this->login('client@example.com', self::PASSWORD, 'C'), 200);

        $this->assertSame(['O', 'A', 'Y'], [$data['business']['client_role'], $data['business']['client_status'], $data['business']['is_default_business']]);
        $this->assertSame('ottercafe-sg', $data['business']['business']['business_slug']);
        $this->assertSame('cafe', $data['business']['business']['business_type_public_id']);
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
            // a pending account is waiting for its activation link, the others for support
            $this->assertSame(lang($status === 'P' ? 'Auth.accountPending' : 'Auth.accountNotActive'), $this->errorMessage($response));
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

    // ---- expired passwords ----

    public function testExpiredPasswordWithoutOtpGetsChangeChallengeNotToken(): void
    {
        foreach (['X', 'T'] as $i => $mode) {
            $this->createStaff("expired{$i}@otternova.com", ['otp_mode' => $mode, 'password_expiry' => $this->yesterday()]);

            $data = $this->json($this->login("expired{$i}@otternova.com"), 200);

            $this->assertSame(['otp_required', 'password_change_required', 'password_change_token', 'password_change_expires_at'], array_keys($data));
            $this->assertFalse($data['otp_required']);
            $this->assertTrue($data['password_change_required']);
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $data['password_change_token']);
            $this->assertEqualsWithDelta(time() + 10 * MINUTE, $data['password_change_expires_at'], 2);
        }

        // only the hash is stored
        $hashes = array_column($this->db->table('auth_password_change')->get()->getResultArray(), 'token_hash');
        $this->assertContains(hash('sha256', $data['password_change_token']), $hashes);
        $this->assertNotContains($data['password_change_token'], $hashes);
        $this->assertSame([], $this->mailer->sent);
    }

    public function testPasswordExpiringTodayIsExpired(): void
    {
        $this->createStaff('today@otternova.com', ['otp_mode' => 'X', 'password_expiry' => gmdate('Y-m-d')]);

        $this->assertTrue($this->json($this->login('today@otternova.com'), 200)['password_change_required']);
    }

    public function testPasswordNotExpiredOrNeverExpiringSignsIn(): void
    {
        $this->createStaff('tomorrow@otternova.com', ['otp_mode' => 'X', 'password_expiry' => gmdate('Y-m-d', time() + DAY)]);
        $this->createStaff('never@otternova.com', ['otp_mode' => 'X', 'password_expiry' => null]);

        foreach (['tomorrow@otternova.com', 'never@otternova.com'] as $email) {
            $data = $this->json($this->login($email), 200);
            $this->assertFalse($data['password_change_required']);
            $this->assertNotEmpty($data['access_token']);
        }
        $this->seeNumRecords(0, 'auth_password_change', []);
    }

    public function testExpiredPasswordWithInactiveAccountIsRejected(): void
    {
        $this->createStaff('blocked@otternova.com', ['otp_mode' => 'X', 'account_status' => 'B', 'password_expiry' => $this->yesterday()]);

        $this->login('blocked@otternova.com')->assertStatus(403);
        $this->seeNumRecords(0, 'auth_password_change', []);
    }

    public function testExpiredPasswordWithOtpAsksForCodeFirstThenChange(): void
    {
        $this->createStaff('otp@otternova.com', ['otp_mode' => 'E', 'password_expiry' => $this->yesterday()]);

        // same answer as an account whose password has not expired
        $data = $this->json($this->login('otp@otternova.com'), 200);
        $this->assertSame(['otp_required', 'otp_token', 'otp_expires_at'], array_keys($data));
        $this->seeNumRecords(0, 'auth_password_change', []);

        $data = $this->json($this->verifyOtp($data['otp_token'], $this->sentOtp()), 200);

        $this->assertTrue($data['password_change_required']);
        $this->assertArrayNotHasKey('access_token', $data);
        $this->assertArrayNotHasKey('user', $data);
        $this->seeNumRecords(1, 'auth_password_change', []);
        $this->seeNumRecords(0, 'auth_otp_challenge', []);

        $data = $this->json($this->changePassword($data['password_change_token']), 200);
        $this->assertNotEmpty($data['access_token']);
    }

    public function testWrongOtpDoesNotRevealExpiry(): void
    {
        $this->createStaff('expired@otternova.com', ['otp_mode' => 'E', 'password_expiry' => $this->yesterday()]);
        $this->createStaff('valid@otternova.com', ['otp_mode' => 'E']);
        $responses = [];
        foreach (['expired@otternova.com', 'valid@otternova.com'] as $email) {
            $token       = $this->json($this->login($email), 200)['otp_token'];
            $responses[] = $this->verifyOtp($token, $this->wrongOtp());
        }

        foreach ($responses as $response) {
            $response->assertStatus(401);
            $this->assertSame(['status' => 'error', 'message' => lang('Auth.otpIncorrect')], json_decode($response->getJSON(), true));
        }
        $this->seeNumRecords(0, 'auth_password_change', []);
    }

    public function testChangeExpiredPasswordSignsInAndMovesExpiry(): void
    {
        $account = $this->createStaff('expired@otternova.com', ['otp_mode' => 'X', 'password_expiry' => $this->yesterday()]);
        $token   = $this->json($this->login('expired@otternova.com'), 200)['password_change_token'];

        $data = $this->json($this->changePassword($token), 200);

        $this->assertFalse($data['otp_required']);
        $this->assertFalse($data['password_change_required']);
        $this->assertSame($account['uid'], service('jwt')->decode($data['access_token'])->sub);
        $this->assertSame('expired@otternova.com', $data['user']['email_address']);
        $this->assertSame(gmdate('Y-m-d', time() + 90 * DAY), $data['user']['password_expiry']);
        $this->seeNumRecords(0, 'auth_password_change', []);

        // the old password no longer works and the new one signs in straight away
        $this->login('expired@otternova.com')->assertStatus(401);
        $data = $this->json($this->login('expired@otternova.com', self::NEW_PASSWORD), 200);
        $this->assertFalse($data['password_change_required']);
        $this->assertNotEmpty($data['access_token']);

        // single use
        $this->changePassword($token, 'another password 3')->assertStatus(410);
    }

    public function testPasswordLifetimeComesFromConfig(): void
    {
        config(AuthConfig::class)->passwordLifetimeDays = 30;
        $this->createStaff('expired@otternova.com', ['otp_mode' => 'X', 'password_expiry' => $this->yesterday()]);
        $token = $this->json($this->login('expired@otternova.com'), 200)['password_change_token'];

        $this->assertSame(gmdate('Y-m-d', time() + 30 * DAY), $this->json($this->changePassword($token), 200)['user']['password_expiry']);
    }

    public function testWeakOrSamePasswordIsRejectedAndChallengeStaysUsable(): void
    {
        $this->createStaff('expired@otternova.com', ['otp_mode' => 'X', 'password_expiry' => $this->yesterday()]);
        $token = $this->json($this->login('expired@otternova.com'), 200)['password_change_token'];

        $cases = [
            'short1'             => lang('Auth.passwordTooShort', [8]),
            str_repeat('a1', 37) => lang('Auth.passwordTooLong', [72]), // 74 bytes
            'onlyletters'        => lang('Auth.passwordTooWeak'),
        ];
        foreach ($cases as $password => $message) {
            $response = $this->changePassword($token, (string) $password);
            $response->assertStatus(422);
            $this->assertSame($message, $this->errorMessage($response));
        }

        // the same password as now, with a digit added so it passes the other rules
        $hash = password_hash('same password 1', PASSWORD_DEFAULT);
        $this->db->table('auth_master')->update(['password_hash' => $hash]);
        $response = $this->changePassword($token, 'same password 1');
        $response->assertStatus(422);
        $this->assertSame(lang('Auth.passwordSameAsOld'), $this->errorMessage($response));

        $this->assertSame(4, (int) $this->db->table('auth_password_change')->get()->getRowArray()['attempts']);
        $this->assertSame($hash, $this->db->table('auth_master')->get()->getRowArray()['password_hash']);
        $this->assertSame($this->yesterday(), $this->db->table('auth_master')->get()->getRowArray()['password_expiry']);

        // still usable on the last attempt
        $this->changePassword($token)->assertStatus(200);
    }

    public function testChangeChallengeIsGoneAfterFiveAttempts(): void
    {
        $this->createStaff('expired@otternova.com', ['otp_mode' => 'X', 'password_expiry' => $this->yesterday()]);
        $token = $this->json($this->login('expired@otternova.com'), 200)['password_change_token'];

        for ($i = 0; $i < 5; $i++) {
            $this->changePassword($token, 'weak')->assertStatus(422);
        }

        $this->seeNumRecords(0, 'auth_password_change', []);
        $response = $this->changePassword($token);
        $response->assertStatus(410);
        $this->assertSame(lang('Auth.passwordChangeExpired'), $this->errorMessage($response));
    }

    public function testExpiredUnknownOrMissingChangeTokenIsGone(): void
    {
        $this->createStaff('expired@otternova.com', ['otp_mode' => 'X', 'password_expiry' => $this->yesterday()]);
        $token = $this->json($this->login('expired@otternova.com'), 200)['password_change_token'];
        $this->db->table('auth_password_change')->update(['expires_at' => time() - 1]);

        $this->changePassword($token)->assertStatus(410);
        $this->seeNumRecords(0, 'auth_password_change', []);
        $this->changePassword(str_repeat('a', 64))->assertStatus(410);
        $this->withBodyFormat('json')->post('v1/change-expired-password', ['new_password' => self::NEW_PASSWORD])->assertStatus(410);
        $this->assertSame($this->yesterday(), $this->db->table('auth_master')->get()->getRowArray()['password_expiry']);
    }

    public function testChangeForAccountNoLongerActiveIsRejected(): void
    {
        $this->createStaff('expired@otternova.com', ['otp_mode' => 'X', 'password_expiry' => $this->yesterday()]);
        $token = $this->json($this->login('expired@otternova.com'), 200)['password_change_token'];
        $this->db->table('auth_master')->update(['account_status' => 'S']);

        $response = $this->changePassword($token);

        $response->assertStatus(403);
        $this->assertSame(lang('Auth.accountNotActive'), $this->errorMessage($response));
        $this->assertSame($this->yesterday(), $this->db->table('auth_master')->get()->getRowArray()['password_expiry']);
    }

    public function testNewSignInReplacesOpenChangeChallenge(): void
    {
        $this->createStaff('expired@otternova.com', ['otp_mode' => 'X', 'password_expiry' => $this->yesterday()]);
        $first  = $this->json($this->login('expired@otternova.com'), 200)['password_change_token'];
        $second = $this->json($this->login('expired@otternova.com'), 200)['password_change_token'];

        $this->seeNumRecords(1, 'auth_password_change', []);
        $this->changePassword($first)->assertStatus(410);
        $this->changePassword($second)->assertStatus(200);
    }

    public function testPasswordChangeIsLoggedWithoutSecrets(): void
    {
        $account = $this->createStaff('expired@otternova.com', ['otp_mode' => 'X', 'password_expiry' => $this->yesterday()]);
        $oldHash = $this->db->table('auth_master')->get()->getRowArray()['password_hash'];
        $token   = $this->json($this->login('expired@otternova.com'), 200)['password_change_token'];
        $row     = $this->db->table('auth_password_change')->get()->getRowArray();

        $this->changePassword($token, 'weak')->assertStatus(422);
        $this->changePassword($token)->assertStatus(200);
        $newHash = $this->db->table('auth_master')->get()->getRowArray()['password_hash'];

        $logs = $this->activity();
        $this->assertSame([
            ['Started password change challenge on sign-in', 'insert', 'auth_password_change'],
            ['Used a password change attempt', 'update', 'auth_password_change'],
            ['Used a password change attempt', 'update', 'auth_password_change'],
            ['Ended password change challenge', 'delete', 'auth_password_change'],
            ['Changed expired password on sign-in', 'update', 'auth_master'],
        ], array_map(static fn ($log) => [$log['activity_summary'], $log['detail']['action'], $log['table_involved']], $logs));
        foreach ($logs as $log) {
            $this->assertSame($account['id'], (int) $log['changed_by_acc']);
            foreach ([$token, $row['token_hash'], $oldHash, $newHash, self::PASSWORD, self::NEW_PASSWORD] as $secret) {
                $this->assertStringNotContainsString($secret, $log['activity_detail']);
            }
        }
        $this->assertSame([
            'password_hash'   => ['old' => '***', 'new' => '***'],
            'password_expiry' => ['old' => $this->yesterday(), 'new' => gmdate('Y-m-d', time() + 90 * DAY)],
        ], end($logs)['detail']['changes']);
    }

    // ---- /staff/change-password and /client/change-password ----

    public function testChangeOwnPasswordMovesExpiryAndKeepsTheSession(): void
    {
        $account = $this->createStaff('own@otternova.com', ['otp_mode' => 'X']);
        $token   = $this->json($this->login('own@otternova.com'), 200)['access_token'];

        $data = $this->json($this->changeOwnPassword($token, self::PASSWORD), 200);

        $this->assertSame(['password_expiry' => gmdate('Y-m-d', time() + 90 * DAY)], $data);
        $this->seeInDatabase('auth_master', ['id' => $account['id'], 'password_expiry' => gmdate('Y-m-d', time() + 90 * DAY)]);
        // still signed in, and only the new password signs in from now on
        $this->getWithToken('v1/staff/me', $token)->assertStatus(200);
        $this->login('own@otternova.com')->assertStatus(401);
        $this->json($this->login('own@otternova.com', self::NEW_PASSWORD), 200);

        $log = $this->activity()[0];
        $this->assertSame('Changed own password', $log['activity_summary']);
        $this->assertSame($account['id'], (int) $log['changed_by_acc']);
        $this->assertSame(['old' => '***', 'new' => '***'], $log['detail']['changes']['password_hash']);
        foreach ([self::PASSWORD, self::NEW_PASSWORD] as $secret) {
            $this->assertStringNotContainsString($secret, $log['activity_detail']);
        }
    }

    public function testChangeOwnPasswordNeedsTheCurrentOneAndAValidNewOne(): void
    {
        $this->createStaff('own@otternova.com', ['otp_mode' => 'X']);
        $token = $this->json($this->login('own@otternova.com'), 200)['access_token'];

        foreach (['wrong password 1', ''] as $current) {
            $response = $this->changeOwnPassword($token, $current);
            $response->assertStatus(422);
            $this->assertSame(['current_password' => lang('Auth.currentPasswordIncorrect')], json_decode($response->getJSON(), true)['errors']);
        }
        foreach (['short1' => lang('Auth.passwordTooShort', [8]), 'no digits here' => lang('Auth.passwordTooWeak')] as $new => $message) {
            $response = $this->changeOwnPassword($token, self::PASSWORD, (string) $new);
            $response->assertStatus(422);
            $this->assertSame(['new_password' => $message], json_decode($response->getJSON(), true)['errors']);
        }

        // nothing changed
        $this->json($this->login('own@otternova.com'), 200);
        $this->seeNumRecords(0, 'log_activity', ['activity_summary' => 'Changed own password']);

        // the fixture password breaks the rules itself, so the same-as-old check is tried from the new one
        $this->json($this->changeOwnPassword($token, self::PASSWORD), 200);
        $response = $this->changeOwnPassword($token, self::NEW_PASSWORD, self::NEW_PASSWORD);
        $response->assertStatus(422);
        $this->assertSame(['new_password' => lang('Auth.passwordSameAsOld')], json_decode($response->getJSON(), true)['errors']);
    }

    public function testWrongCurrentPasswordsAreRateLimited(): void
    {
        $this->createStaff('own@otternova.com', ['otp_mode' => 'X']);
        $token = $this->json($this->login('own@otternova.com'), 200)['access_token'];

        for ($i = 0; $i < 5; $i++) {
            $this->changeOwnPassword($token, 'wrong password ' . $i)->assertStatus(422);
        }

        // blocked even with the right password now, as sign-in is
        $response = $this->changeOwnPassword($token, self::PASSWORD);
        $response->assertStatus(429);
        $this->assertSame(lang('Auth.tooManyAttempts'), $this->errorMessage($response));
        $this->login('own@otternova.com')->assertStatus(429);
    }

    public function testClientChangesTheirOwnPassword(): void
    {
        $this->createClient('client@example.com', ['otp_mode' => 'X']);
        $token = $this->json($this->login('client@example.com', self::PASSWORD, 'C'), 200)['access_token'];

        $this->json($this->changeOwnPassword($token, self::PASSWORD, self::NEW_PASSWORD, 'client'), 200);
        $this->json($this->login('client@example.com', self::NEW_PASSWORD, 'C'), 200);
        // a client token does not open the staff route
        $response = $this->changeOwnPassword($token, self::NEW_PASSWORD, 'another password 3');
        $response->assertStatus(403);
        $this->assertSame(lang('Auth.wrongAccountType'), $this->errorMessage($response));
    }

    public function testDeletingAccountDeletesItsChangeChallenge(): void
    {
        $this->createStaff('admin@otternova.com', ['otp_mode' => 'X']);
        $this->createStaff('expired@otternova.com', ['otp_mode' => 'X', 'password_expiry' => $this->yesterday()]);
        $this->login('expired@otternova.com')->assertStatus(200);

        $model  = model(App\Models\AuthAccountModel::class);
        $record = $model->findForForm('expiredotternovacom');
        $this->assertTrue($model->deleteWithProfile($record, 1));

        $this->seeNumRecords(0, 'auth_password_change', []);
        $this->assertContains('Deleted password change challenge of expiredotternovacom', array_column($this->activity(), 'activity_summary'));
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

    private function changePassword(string $token, string $newPassword = self::NEW_PASSWORD): TestResponse
    {
        return $this->withBodyFormat('json')->post('v1/change-expired-password', ['password_change_token' => $token, 'new_password' => $newPassword]);
    }

    private function changeOwnPassword(string $accessToken, string $current, string $newPassword = self::NEW_PASSWORD, string $group = 'staff'): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . $accessToken])->withBodyFormat('json')
            ->post('v1/' . $group . '/change-password', ['current_password' => $current, 'new_password' => $newPassword]);
    }

    private function yesterday(): string
    {
        return gmdate('Y-m-d', time() - DAY);
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
