<?php

namespace App\Models;

use App\Libraries\ActivityLog;
use CodeIgniter\Model;

/**
 * Model for the auth_password_reset table: pending "forgot password" links.
 * The token in the link is not stored, only its hash.
 */
class AuthPasswordResetModel extends Model
{
    use TransactionTrait;

    /** The columns written to log_activity; the hash never is */
    private const LOG_FIELDS = 'id, auth_id, account_type, expires_at';

    protected $table            = 'auth_password_reset';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'auth_id',
        'account_type',
        'token_hash',
        'expires_at',
    ];

    // created_at is maintained by the database
    protected $useTimestamps = false;

    /**
     * Start a reset link for an account, replacing any it still has; expired ones of any account go too
     * @param int    $authId
     * @param string $accountType
     * @param int    $expiresAt Unix seconds
     * @return string The token for the reset link
     */
    public function createReset(int $authId, string $accountType, int $expiresAt): string
    {
        $token = bin2hex(random_bytes(32));
        $log   = new ActivityLog();
        $this->inTransaction(function () use ($authId, $accountType, $expiresAt, $token, $log) {
            $old = $this->db->table($this->table)
                ->select(self::LOG_FIELDS)
                ->groupStart()->where('auth_id', $authId)->orWhere('expires_at <=', time())->groupEnd()
                ->get()
                ->getResultArray();
            foreach ($old as $row) {
                $this->db->table($this->table)->where('id', $row['id'])->delete();
                (int) $row['auth_id'] === $authId
                    ? $log->deleted($authId, 'Replaced password reset link', $this->table, (int) $row['id'], $row)
                    : $log->deleted(null, 'Deleted expired password reset link', $this->table, (int) $row['id'], $row);
            }

            $row = [
                'auth_id'      => $authId,
                'account_type' => $accountType,
                'token_hash'   => self::hashToken($token),
                'expires_at'   => $expiresAt,
            ];
            $this->db->table($this->table)->insert($row);
            $log->inserted($authId, 'Started password reset link', $this->table, (int) $this->db->insertID(), $row);
        });

        return $token;
    }

    /**
     * Find a reset by the token in the link, expired or not
     * @param string $token
     * @return array|null
     */
    public function findByToken(string $token): ?array
    {
        return $this->where('token_hash', self::hashToken($token))->first();
    }

    /**
     * Delete a reset
     * @param int $id
     * @return bool false when it was already gone
     */
    public function consume(int $id): bool
    {
        $log = new ActivityLog();

        return $this->inTransaction(fn () => $this->deleteReset($id, $log));
    }

    /**
     * Delete a reset and set the account's new password, in one transaction. A pending (P) account becomes active (A)
     * too: opening the emailed link proves the address, just as the activation link does.
     * @param int    $id             The reset
     * @param string $passwordHash   password_hash() of the new password
     * @param string $passwordExpiry Y-m-d
     * @return bool false when the reset was already gone; nothing is changed then
     */
    public function consumeWithNewPassword(int $id, string $passwordHash, string $passwordExpiry): bool
    {
        $log = new ActivityLog();

        return $this->inTransaction(function () use ($id, $passwordHash, $passwordExpiry, $log) {
            $authId = $this->db->table($this->table)->select('auth_id')->where('id', $id)->get()->getRowArray()['auth_id'] ?? null;
            if ($authId === null || ! $this->deleteReset($id, $log)) {
                return false;
            }

            $authId = (int) $authId;
            $old    = $this->db->table('auth_master')->select('password_hash, password_expiry, account_status')->where('id', $authId)->get()->getRowArray();
            $new    = ['password_hash' => $passwordHash, 'password_expiry' => $passwordExpiry];
            if (($old['account_status'] ?? null) === AuthMasterModel::STATUS_PENDING) {
                $new['account_status'] = AuthMasterModel::STATUS_ACTIVE;
            }
            $this->db->table('auth_master')->where('id', $authId)->update($new);
            // password_hash shows only as changed, never its value
            $log->updated($authId, 'Reset forgotten password', 'auth_master', $authId, $old ?? [], $new);

            // a pending account has no use for its activation link any more
            $activations = $this->db->table('auth_activation')->select('id, auth_id, expires_at')->where('auth_id', $authId)->get()->getResultArray();
            foreach ($activations as $activation) {
                $this->db->table('auth_activation')->where('id', $activation['id'])->delete();
                $log->deleted($authId, 'Ended activation link on password reset', 'auth_activation', (int) $activation['id'], $activation);
            }

            return true;
        });
    }

    /**
     * Delete a reset and log it; call inside a transaction
     * @param int         $id
     * @param ActivityLog $log
     * @return bool false when it was already gone
     */
    private function deleteReset(int $id, ActivityLog $log): bool
    {
        $row = $this->db->table($this->table)->select(self::LOG_FIELDS)->where('id', $id)->get()->getRowArray();
        $this->db->table($this->table)->where('id', $id)->delete();
        if ($row === null || $this->db->affectedRows() !== 1) {
            return false;
        }
        $log->deleted((int) $row['auth_id'], 'Ended password reset link', $this->table, $id, $row);

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
