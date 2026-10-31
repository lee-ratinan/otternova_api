<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for the auth_otp_challenge table: pending email OTPs.
 * Neither the otp_token nor the code is stored, only their hashes.
 */
class AuthOtpChallengeModel extends Model
{
    protected $table            = 'auth_otp_challenge';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'auth_id',
        'account_type',
        'token_hash',
        'otp_hash',
        'attempts',
        'expires_at',
    ];

    // created_at is maintained by the database
    protected $useTimestamps = false;

    /**
     * Start a challenge for an account
     * @param int    $authId
     * @param string $accountType
     * @param string $otp       The code sent to the user; only its hash is stored
     * @param int    $expiresAt Unix seconds
     * @return string The opaque otp_token the client sends back with the code
     */
    public function createChallenge(int $authId, string $accountType, string $otp, int $expiresAt): string
    {
        // a new sign-in replaces any challenge still open for the account; expired ones go too
        $this->where('auth_id', $authId)->orWhere('expires_at <=', time())->delete();

        $token = bin2hex(random_bytes(32));
        $this->insert([
            'auth_id'      => $authId,
            'account_type' => $accountType,
            'token_hash'   => self::hashToken($token),
            'otp_hash'     => password_hash($otp, PASSWORD_DEFAULT),
            'attempts'     => 0,
            'expires_at'   => $expiresAt,
        ]);

        return $token;
    }

    /**
     * Find a challenge by the otp_token the client holds, expired or not
     * @param string $token
     * @return array|null
     */
    public function findByToken(string $token): ?array
    {
        return $this->where('token_hash', self::hashToken($token))->first();
    }

    /**
     * Use up one attempt, atomically, so parallel guesses cannot go past the limit
     * @param int $id
     * @param int $maxAttempts
     * @return bool false when the attempts were already used up (or the challenge is gone)
     */
    public function claimAttempt(int $id, int $maxAttempts): bool
    {
        $this->builder()
            ->set('attempts', 'attempts + 1', false)
            ->where('id', $id)
            ->where('attempts <', $maxAttempts)
            ->update();

        return $this->db->affectedRows() === 1;
    }

    /**
     * Delete a challenge
     * @param int $id
     * @return bool false when it was already gone, e.g. another request used it first
     */
    public function consume(int $id): bool
    {
        $this->delete($id);

        return $this->db->affectedRows() === 1;
    }

    /**
     * @param string $token
     * @return string
     */
    private static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
