<?php

namespace App\Models;

use App\Libraries\ActivityLog;
use CodeIgniter\Model;

/**
 * Model for the auth_password_change table: pending changes of an expired password at sign-in.
 * The password_change_token is not stored, only its hash.
 */
class AuthPasswordChangeModel extends Model
{
    use TransactionTrait;

    /** The columns written to log_activity; the hash never is */
    private const LOG_FIELDS = 'id, auth_id, account_type, attempts, expires_at';

    protected $table            = 'auth_password_change';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'auth_id',
        'account_type',
        'token_hash',
        'attempts',
        'expires_at',
    ];

    // created_at is maintained by the database
    protected $useTimestamps = false;

    /**
     * Start a challenge for an account whose password has expired
     * @param int    $authId
     * @param string $accountType
     * @param int    $expiresAt Unix seconds
     * @return string The opaque password_change_token the client sends back with the new password
     */
    public function createChallenge(int $authId, string $accountType, int $expiresAt): string
    {
        $token = bin2hex(random_bytes(32));
        $log   = new ActivityLog();
        $this->inTransaction(function () use ($authId, $accountType, $expiresAt, $token, $log) {
            // a new sign-in replaces any challenge still open for the account; expired ones go too
            $old = $this->db->table($this->table)
                ->select(self::LOG_FIELDS)
                ->groupStart()->where('auth_id', $authId)->orWhere('expires_at <=', time())->groupEnd()
                ->get()
                ->getResultArray();
            foreach ($old as $row) {
                $this->db->table($this->table)->where('id', $row['id'])->delete();
                (int) $row['auth_id'] === $authId
                    ? $log->deleted($authId, 'Replaced password change challenge on sign-in', $this->table, (int) $row['id'], $row)
                    : $log->deleted(null, 'Deleted expired password change challenge', $this->table, (int) $row['id'], $row);
            }

            $row = [
                'auth_id'      => $authId,
                'account_type' => $accountType,
                'token_hash'   => self::hashToken($token),
                'attempts'     => 0,
                'expires_at'   => $expiresAt,
            ];
            $this->db->table($this->table)->insert($row);
            $log->inserted($authId, 'Started password change challenge on sign-in', $this->table, (int) $this->db->insertID(), $row);
        });

        return $token;
    }

    /**
     * Find a challenge by the password_change_token the client holds, expired or not
     * @param string $token
     * @return array|null
     */
    public function findByToken(string $token): ?array
    {
        return $this->where('token_hash', self::hashToken($token))->first();
    }

    /**
     * Use up one attempt, atomically, so parallel requests cannot go past the limit
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
            $log->updated((int) $row['auth_id'], 'Used a password change attempt', $this->table, $id, ['attempts' => $attempts - 1], ['attempts' => $attempts]);

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

        return $this->inTransaction(fn () => $this->deleteChallenge($id, $log));
    }

    /**
     * Delete a challenge and set the account's new password, in one transaction
     * @param int    $id             The challenge
     * @param string $passwordHash   password_hash() of the new password
     * @param string $passwordExpiry Y-m-d
     * @return bool false when the challenge was already gone; nothing is changed then
     */
    public function consumeWithNewPassword(int $id, string $passwordHash, string $passwordExpiry): bool
    {
        $log = new ActivityLog();

        return $this->inTransaction(function () use ($id, $passwordHash, $passwordExpiry, $log) {
            $authId = $this->db->table($this->table)->select('auth_id')->where('id', $id)->get()->getRowArray()['auth_id'] ?? null;
            if ($authId === null || ! $this->deleteChallenge($id, $log)) {
                return false;
            }

            $authId = (int) $authId;
            $old    = $this->db->table('auth_master')->select('password_hash, password_expiry')->where('id', $authId)->get()->getRowArray();
            $new    = ['password_hash' => $passwordHash, 'password_expiry' => $passwordExpiry];
            $this->db->table('auth_master')->where('id', $authId)->update($new);
            // password_hash shows only as changed, never its value
            $log->updated($authId, 'Changed expired password on sign-in', 'auth_master', $authId, $old ?? [], $new);

            return true;
        });
    }

    /**
     * Delete a challenge and log it; call inside a transaction
     * @param int         $id
     * @param ActivityLog $log
     * @return bool false when it was already gone
     */
    private function deleteChallenge(int $id, ActivityLog $log): bool
    {
        $row = $this->db->table($this->table)->select(self::LOG_FIELDS)->where('id', $id)->get()->getRowArray();
        $this->db->table($this->table)->where('id', $id)->delete();
        if ($row === null || $this->db->affectedRows() !== 1) {
            return false;
        }
        $log->deleted((int) $row['auth_id'], 'Ended password change challenge', $this->table, $id, $row);

        return true;
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
