<?php

namespace App\Models;

use App\Libraries\ActivityLog;
use CodeIgniter\Model;

/**
 * Model for the auth_otp_challenge table: pending email OTPs.
 * Neither the otp_token nor the code is stored, only their hashes.
 */
class AuthOtpChallengeModel extends Model
{
    use TransactionTrait;

    /** The columns written to log_activity; the hashes never are */
    private const LOG_FIELDS = 'id, auth_id, account_type, attempts, expires_at';

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
        $token = bin2hex(random_bytes(32));
        $log   = new ActivityLog();
        $this->inTransaction(function () use ($authId, $accountType, $otp, $expiresAt, $token, $log) {
            // a new sign-in replaces any challenge still open for the account; expired ones go too
            $old = $this->db->table($this->table)
                ->select(self::LOG_FIELDS)
                ->groupStart()->where('auth_id', $authId)->orWhere('expires_at <=', time())->groupEnd()
                ->get()
                ->getResultArray();
            foreach ($old as $row) {
                $this->db->table($this->table)->where('id', $row['id'])->delete();
                (int) $row['auth_id'] === $authId
                    ? $log->deleted($authId, 'Replaced OTP challenge on sign-in', $this->table, (int) $row['id'], $row)
                    : $log->deleted(null, 'Deleted expired OTP challenge', $this->table, (int) $row['id'], $row);
            }

            $row = [
                'auth_id'      => $authId,
                'account_type' => $accountType,
                'token_hash'   => self::hashToken($token),
                'otp_hash'     => password_hash($otp, PASSWORD_DEFAULT),
                'attempts'     => 0,
                'expires_at'   => $expiresAt,
            ];
            $this->db->table($this->table)->insert($row);
            $log->inserted($authId, 'Started OTP challenge on sign-in', $this->table, (int) $this->db->insertID(), $row);
        });

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
        $log = new ActivityLog();

        return $this->inTransaction(function () use ($id, $maxAttempts, $log) {
            $this->db->table($this->table)
                ->set('attempts', 'attempts + 1', false)
                ->where('id', $id)
                ->where('attempts <', $maxAttempts)
                ->update();
            if ($this->db->affectedRows() !== 1) {
                return false;
            }

            $row      = $this->db->table($this->table)->select(self::LOG_FIELDS)->where('id', $id)->get()->getRowArray();
            $attempts = (int) $row['attempts'];
            $log->updated((int) $row['auth_id'], 'Used an OTP attempt', $this->table, $id, ['attempts' => $attempts - 1], ['attempts' => $attempts]);

            return true;
        });
    }

    /**
     * Delete a challenge
     * @param int $id
     * @return bool false when it was already gone, e.g. another request used it first
     */
    public function consume(int $id): bool
    {
        $log = new ActivityLog();

        return $this->inTransaction(function () use ($id, $log) {
            $row = $this->db->table($this->table)->select(self::LOG_FIELDS)->where('id', $id)->get()->getRowArray();
            $this->db->table($this->table)->where('id', $id)->delete();
            if ($row === null || $this->db->affectedRows() !== 1) {
                return false;
            }
            $log->deleted((int) $row['auth_id'], 'Ended OTP challenge', $this->table, $id, $row);

            return true;
        });
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
