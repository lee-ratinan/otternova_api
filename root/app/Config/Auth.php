<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Settings for the /login, /verify-otp, /change-expired-password and /logout endpoints and the jwt filter,
 * the client sign-up (/create-client-account, /activate-client-account) and the forgotten password
 * (/request-password-reset, /check-password-reset, /reset-password)
 */
class Auth extends BaseConfig
{
    /** No access token lives longer than this, whatever JWT_TTL says */
    public const MAX_TOKEN_TTL = 8 * HOUR;

    /** HS256 signing secret: JWT_SECRET in .env, at least 32 bytes */
    public string $jwtSecret = '';

    /** The iss claim: JWT_ISSUER in .env */
    public string $jwtIssuer = 'otternova-api';

    /** Access token lifetime in seconds: JWT_TTL in .env, capped at MAX_TOKEN_TTL */
    public int $jwtTtl = self::MAX_TOKEN_TTL;

    /** Email OTP lifetime in seconds */
    public int $otpTtl = 5 * MINUTE;

    /** Wrong codes allowed before an OTP challenge is thrown away */
    public int $otpMaxAttempts = 5;

    /** Lifetime in seconds of the challenge to change an expired password at sign-in */
    public int $passwordChangeTtl = 10 * MINUTE;

    /** Requests allowed before a password change challenge is thrown away */
    public int $passwordChangeMaxAttempts = 5;

    /** A new password expires this many days after today: PASSWORD_LIFETIME_DAYS in .env */
    public int $passwordLifetimeDays = 90;

    /** Failed sign-ins allowed per email address / per IP address within $throttleWindow seconds */
    public int $throttleMaxPerEmail = 5;
    public int $throttleMaxPerIp    = 20;
    public int $throttleWindow      = 15 * MINUTE;

    /** Sign-ups allowed per email address / per IP address within $throttleWindow seconds, successful or not */
    public int $signUpMaxPerEmail = 3;
    public int $signUpMaxPerIp    = 10;

    /** Lifetime in seconds of the activation link emailed after a client signs up */
    public int $activationTtl = 2 * DAY;

    /** Lifetime in seconds of the link emailed when a business owner invites someone with no account yet */
    public int $invitationTtl = 7 * DAY;

    /** Reset links requested per email address / per IP address within $throttleWindow seconds */
    public int $resetMaxPerEmail = 3;
    public int $resetMaxPerIp    = 10;

    /** Lifetime in seconds of the link emailed for a forgotten password */
    public int $passwordResetTtl = HOUR;

    public function __construct()
    {
        parent::__construct();

        $this->jwtSecret = (string) env('JWT_SECRET', $this->jwtSecret);
        $this->jwtIssuer = (string) env('JWT_ISSUER', $this->jwtIssuer);
        $this->jwtTtl    = max(1, min((int) env('JWT_TTL', $this->jwtTtl), self::MAX_TOKEN_TTL));

        $this->passwordLifetimeDays = max(1, (int) env('PASSWORD_LIFETIME_DAYS', $this->passwordLifetimeDays));
    }
}
