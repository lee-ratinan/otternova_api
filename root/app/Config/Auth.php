<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Settings for the /login, /verify-otp and /logout endpoints and the jwt filter
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

    /** Failed sign-ins allowed per email address / per IP address within $throttleWindow seconds */
    public int $throttleMaxPerEmail = 5;
    public int $throttleMaxPerIp    = 20;
    public int $throttleWindow      = 15 * MINUTE;

    public function __construct()
    {
        parent::__construct();

        $this->jwtSecret = (string) env('JWT_SECRET', $this->jwtSecret);
        $this->jwtIssuer = (string) env('JWT_ISSUER', $this->jwtIssuer);
        $this->jwtTtl    = max(1, min((int) env('JWT_TTL', $this->jwtTtl), self::MAX_TOKEN_TTL));
    }
}
