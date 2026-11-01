<?php

namespace App\Controllers;

use App\Libraries\ApiResponse;
use App\Libraries\EmailService;
use App\Models\AuthAccountModel;
use App\Models\AuthOtpChallengeModel;
use App\Models\AuthPasswordChangeModel;
use App\Models\AuthTokenDenylistModel;
use CodeIgniter\HTTP\Exceptions\HTTPException;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Auth as AuthConfig;
use Random\RandomException;

/**
 * Sign-in for the Admin app (staff, account_type S) and the Client app (clients, account_type C).
 * The apps keep the access token in their own session and send it back as "Authorization: Bearer <jwt>".
 */
class Auth extends BaseController
{
    /**
     * Checked against when no account matches, so an unknown email takes as long to reject as a wrong password
     */
    private const DUMMY_HASH = '$2y$12$dOj6FCfe3Bh1RnqoU9Mb1uV2cbYYofQOtT3A1bJE8H2HP2cXIiOV.';

    /** New password rules: characters at least, and bytes at most (bcrypt ignores anything past 72 bytes) */
    private const PASSWORD_MIN_LENGTH = 8;
    private const PASSWORD_MAX_BYTES  = 72;

    /**
     * POST /login { email_address, password, account_type }
     * The user object carries avatar_path, null when no avatar is set (the app shows its default avatar then)
     * @return ResponseInterface
     * @throws RandomException
     */
    public function login(): ResponseInterface
    {
        $input       = $this->jsonInput();
        $email       = is_string($input['email_address'] ?? null) ? trim($input['email_address']) : '';
        $password    = is_string($input['password'] ?? null) ? $input['password'] : '';
        $accountType = is_string($input['account_type'] ?? null) ? $input['account_type'] : '';
        if ($email === '' || $password === '' || ! isset(AuthAccountModel::PROFILES[$accountType])) {
            return ApiResponse::error(lang('Auth.missingCredentials'), 400);
        }

        $ip       = $this->request->getIPAddress();
        $throttle = service('loginThrottle');
        if ($throttle->isBlocked($email, $ip)) {
            return ApiResponse::error(lang('Auth.tooManyAttempts'), 429);
        }

        // unknown email, wrong password and wrong account type all get the same answer
        $accounts = model(AuthAccountModel::class);
        $account  = $accounts->findForLogin($email, $accountType);
        if (! password_verify($password, $account['password_hash'] ?? self::DUMMY_HASH) || $account === null) {
            $throttle->recordFailure($email, $ip);

            return ApiResponse::error(lang('Auth.invalidCredentials'), 401);
        }
        unset($account['password_hash']);

        if ($account['account_status'] !== AuthAccountModel::STATUS_ACTIVE) {
            return ApiResponse::error(lang('Auth.accountNotActive'), 403);
        }
        $throttle->clear($email);

        // telephone OTP is not supported yet, so T signs in straight away like X
        // with email OTP the expiry is checked after the code, so the password alone does not tell anyone it expired
        if ($account['otp_mode'] === AuthAccountModel::OTP_EMAIL) {
            return $this->startOtpChallenge($account);
        }
        if ($this->isPasswordExpired($account)) {
            return $this->startPasswordChange($account);
        }

        return $this->signedIn($account);
    }

    /**
     * POST /verify-otp { otp_token, otp }
     * @return ResponseInterface
     */
    public function verifyOtp(): ResponseInterface
    {
        $input = $this->jsonInput();
        $token = is_string($input['otp_token'] ?? null) ? $input['otp_token'] : '';
        $otp   = is_string($input['otp'] ?? null) ? trim($input['otp']) : '';
        if ($token === '') {
            return ApiResponse::error(lang('Auth.otpExpired'), 410);
        }
        if (! preg_match('/^\d{6}$/', $otp)) {
            return ApiResponse::error(lang('Auth.otpInvalidFormat'), 400);
        }

        $config     = config(AuthConfig::class);
        $challenges = model(AuthOtpChallengeModel::class);
        $challenge  = $challenges->findByToken($token);
        if ($challenge === null) {
            return ApiResponse::error(lang('Auth.otpExpired'), 410);
        }
        if ($challenge['expires_at'] <= time() || ! $challenges->claimAttempt((int) $challenge['id'], $config->otpMaxAttempts)) {
            $challenges->consume((int) $challenge['id']);

            return ApiResponse::error(lang('Auth.otpExpired'), 410);
        }

        $accounts = model(AuthAccountModel::class);
        $account  = $accounts->findForSessionById((int) $challenge['auth_id'], $challenge['account_type']);

        if (! password_verify($otp, $challenge['otp_hash'])) {
            if ($challenge['attempts'] + 1 >= $config->otpMaxAttempts) {
                $challenges->consume((int) $challenge['id']);
            }
            // wrong codes count towards the sign-in limit too, or signing in again would reset the guesses
            service('loginThrottle')->recordFailure($account['email_address'] ?? '', $this->request->getIPAddress());

            return ApiResponse::error(lang('Auth.otpIncorrect'), 401);
        }

        // single use: if another request got here first, this one is too late
        if (! $challenges->consume((int) $challenge['id']) || $account === null) {
            return ApiResponse::error(lang('Auth.otpExpired'), 410);
        }
        if ($account['account_status'] !== AuthAccountModel::STATUS_ACTIVE) {
            return ApiResponse::error(lang('Auth.accountNotActive'), 403);
        }
        if ($this->isPasswordExpired($account)) {
            return $this->startPasswordChange($account);
        }

        return $this->signedIn($account);
    }

    /**
     * POST /change-expired-password { password_change_token, new_password }
     * The only way to sign in while the password is expired: sets the new password and signs in.
     * A new password that breaks the rules gets a 422 and leaves the challenge open for another try.
     * @return ResponseInterface
     */
    public function changeExpiredPassword(): ResponseInterface
    {
        $input       = $this->jsonInput();
        $token       = is_string($input['password_change_token'] ?? null) ? $input['password_change_token'] : '';
        $newPassword = is_string($input['new_password'] ?? null) ? $input['new_password'] : '';
        if ($token === '') {
            return ApiResponse::error(lang('Auth.passwordChangeExpired'), 410);
        }

        $config     = config(AuthConfig::class);
        $challenges = model(AuthPasswordChangeModel::class);
        $challenge  = $challenges->findByToken($token);
        if ($challenge === null) {
            return ApiResponse::error(lang('Auth.passwordChangeExpired'), 410);
        }
        if ($challenge['expires_at'] <= time() || ! $challenges->claimAttempt((int) $challenge['id'], $config->passwordChangeMaxAttempts)) {
            $challenges->consume((int) $challenge['id']);

            return ApiResponse::error(lang('Auth.passwordChangeExpired'), 410);
        }

        $accounts = model(AuthAccountModel::class);
        $account  = $accounts->findForPasswordChange((int) $challenge['auth_id'], $challenge['account_type']);
        if ($account === null) {
            $challenges->consume((int) $challenge['id']);

            return ApiResponse::error(lang('Auth.passwordChangeExpired'), 410);
        }
        if ($account['account_status'] !== AuthAccountModel::STATUS_ACTIVE) {
            $challenges->consume((int) $challenge['id']);

            return ApiResponse::error(lang('Auth.accountNotActive'), 403);
        }

        $problem = $this->passwordProblem($newPassword, $account['password_hash']);
        if ($problem !== null) {
            if ($challenge['attempts'] + 1 >= $config->passwordChangeMaxAttempts) {
                $challenges->consume((int) $challenge['id']);
            }

            return ApiResponse::error($problem, 422);
        }

        // single use: if another request got here first, this one is too late
        $expiry = gmdate('Y-m-d', time() + $config->passwordLifetimeDays * DAY);
        if (! $challenges->consumeWithNewPassword((int) $challenge['id'], password_hash($newPassword, PASSWORD_DEFAULT), $expiry)) {
            return ApiResponse::error(lang('Auth.passwordChangeFailed'), 410);
        }

        $account = $accounts->findForSessionById((int) $account['id'], $account['account_type']);
        if ($account === null) {
            return ApiResponse::error(lang('Auth.passwordChangeExpired'), 410);
        }

        return $this->signedIn($account);
    }

    /**
     * POST /logout, behind the jwt filter: the token stops working now rather than at its exp
     * @return ResponseInterface
     */
    public function logout(): ResponseInterface
    {
        $claims = service('authContext')->claims();
        model(AuthTokenDenylistModel::class)->revoke((string) $claims->jti, (int) $claims->exp, (int) service('authContext')->account()['id']);

        return ApiResponse::success();
    }

    /**
     * GET /staff/me and /client/me, behind the jwt filter: the signed-in user, as in the sign-in response
     * @return ResponseInterface
     */
    public function me(): ResponseInterface
    {
        return ApiResponse::success(['user' => AuthAccountModel::toUserPayload(service('authContext')->account())]);
    }

    /**
     * Email a 6-digit code; the client gets an opaque otp_token to send back with it
     * @param array $account
     * @return ResponseInterface
     * @throws RandomException
     */
    private function startOtpChallenge(array $account): ResponseInterface
    {
        $config    = config(AuthConfig::class);
        $otp       = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expiresAt = time() + $config->otpTtl;
        $token     = model(AuthOtpChallengeModel::class)->createChallenge((int) $account['id'], $account['account_type'], $otp, $expiresAt);

        log_message('alert', '=== OTP: ' . $otp . ' ===');

        service('emailService')->send(EmailService::TYPE_LOGIN_OTP, $account['email_address'], [
            'full_name' => $account['full_name'],
            'otp'       => $otp,
            'minutes'   => intdiv($config->otpTtl, MINUTE),
        ]);

        return ApiResponse::success([
            'otp_required'   => true,
            'otp_token'      => $token,
            'otp_expires_at' => $expiresAt,
        ]);
    }

    /**
     * Expired when password_expiry is set and is today or earlier (UTC); null never expires
     * @param array $account
     * @return bool
     */
    private function isPasswordExpired(array $account): bool
    {
        $expiry = $account['password_expiry'] ?? null;

        return $expiry !== null && $expiry !== '' && substr((string) $expiry, 0, 10) <= gmdate('Y-m-d');
    }

    /**
     * No access token while the password is expired: the client gets an opaque password_change_token instead,
     * to send back with the new password
     * @param array $account
     * @return ResponseInterface
     */
    private function startPasswordChange(array $account): ResponseInterface
    {
        $expiresAt = time() + config(AuthConfig::class)->passwordChangeTtl;
        $token     = model(AuthPasswordChangeModel::class)->createChallenge((int) $account['id'], $account['account_type'], $expiresAt);

        return ApiResponse::success([
            'otp_required'               => false,
            'password_change_required'   => true,
            'password_change_token'      => $token,
            'password_change_expires_at' => $expiresAt,
        ]);
    }

    /**
     * What is wrong with a new password, as the message shown to the user
     * @param string $password
     * @param string $currentHash
     * @return string|null null when it is fine
     */
    private function passwordProblem(string $password, string $currentHash): ?string
    {
        if (mb_strlen($password, 'UTF-8') < self::PASSWORD_MIN_LENGTH) {
            return lang('Auth.passwordTooShort', [self::PASSWORD_MIN_LENGTH]);
        }
        if (strlen($password) > self::PASSWORD_MAX_BYTES) {
            return lang('Auth.passwordTooLong', [self::PASSWORD_MAX_BYTES]);
        }
        // invalid UTF-8 matches neither, so it counts as too weak
        if (preg_match('/\p{L}/u', $password) !== 1 || preg_match('/\p{Nd}/u', $password) !== 1) {
            return lang('Auth.passwordTooWeak');
        }
        if (password_verify($password, $currentHash)) {
            return lang('Auth.passwordSameAsOld');
        }

        return null;
    }

    /**
     * @param array $account
     * @return ResponseInterface
     */
    private function signedIn(array $account): ResponseInterface
    {
        $token = service('jwt')->issue($account);

        return ApiResponse::success([
            'otp_required'             => false,
            'password_change_required' => false,
            'access_token'             => $token['access_token'],
            'expires_at'               => $token['expires_at'],
            'user'                     => AuthAccountModel::toUserPayload($account),
        ]);
    }

    /**
     * The JSON body as an array; anything that is not a JSON object counts as empty
     * @return array
     */
    private function jsonInput(): array
    {
        try {
            $input = $this->request->getJSON(true);
        } catch (HTTPException) {
            return [];
        }

        return is_array($input) ? $input : [];
    }
}
