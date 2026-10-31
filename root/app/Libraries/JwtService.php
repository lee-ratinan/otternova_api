<?php

namespace App\Libraries;

use Config\Auth;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use RuntimeException;
use stdClass;
use UnexpectedValueException;

/**
 * Issues and checks the HS256 access tokens. There is no refresh: when a token expires the user signs in again.
 */
class JwtService
{
    private const ALGORITHM = 'HS256';

    public function __construct(private readonly Auth $config)
    {
    }

    /**
     * @param array $account auth_master row (uid and account_type are used)
     * @return array{access_token: string, expires_at: int}
     */
    public function issue(array $account): array
    {
        $now       = time();
        $expiresAt = $now + min($this->config->jwtTtl, Auth::MAX_TOKEN_TTL);
        $token     = JWT::encode([
            'iss' => $this->config->jwtIssuer,
            'sub' => $account['uid'],
            'typ' => $account['account_type'],
            'iat' => $now,
            'nbf' => $now,
            'exp' => $expiresAt,
            'jti' => bin2hex(random_bytes(16)),
        ], $this->secret(), self::ALGORITHM);

        return ['access_token' => $token, 'expires_at' => $expiresAt];
    }

    /**
     * Check the signature, exp, nbf and issuer and return the claims
     * @param string $token
     * @return stdClass
     * @throws UnexpectedValueException|\DomainException when the token is not valid
     */
    public function decode(string $token): stdClass
    {
        $claims = JWT::decode($token, new Key($this->secret(), self::ALGORITHM));
        foreach (['sub', 'typ', 'jti', 'exp'] as $claim) {
            if (! isset($claims->{$claim})) {
                throw new UnexpectedValueException('Missing claim: ' . $claim);
            }
        }
        if (($claims->iss ?? null) !== $this->config->jwtIssuer) {
            throw new UnexpectedValueException('Wrong issuer');
        }

        return $claims;
    }

    /**
     * @return string
     */
    private function secret(): string
    {
        if (strlen($this->config->jwtSecret) < 32) {
            throw new RuntimeException('JWT_SECRET is not set in .env or is shorter than 32 bytes');
        }

        return $this->config->jwtSecret;
    }
}
