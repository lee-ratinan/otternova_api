<?php

namespace App\Models;

use App\Libraries\ActivityLog;
use CodeIgniter\Model;

/**
 * Model for the auth_activation table: pending activation links of signed-up clients (account_status P).
 * The token in the link is not stored, only its hash.
 */
class AuthActivationModel extends Model
{
    use TransactionTrait;

    /** The columns written to log_activity; the hash never is */
    private const LOG_FIELDS = 'id, auth_id, expires_at';

    protected $table            = 'auth_activation';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'auth_id',
        'token_hash',
        'expires_at',
    ];

    // created_at is maintained by the database
    protected $useTimestamps = false;

    /**
     * Start a new activation link for a pending account, replacing any it still has; expired ones of any account go too
     * @param int $authId
     * @param int $expiresAt Unix seconds
     * @return string The token for the activation link
     */
    public function createActivation(int $authId, int $expiresAt): string
    {
        $log = new ActivityLog();

        return $this->inTransaction(fn () => $this->insertActivation($authId, $expiresAt, $log));
    }

    /**
     * Insert an activation row, deleting the account's old ones and every expired one; call inside a transaction
     * @param int         $authId
     * @param int         $expiresAt Unix seconds
     * @param ActivityLog $log
     * @return string The token for the activation link
     */
    public function insertActivation(int $authId, int $expiresAt, ActivityLog $log): string
    {
        $old = $this->db->table($this->table)
            ->select(self::LOG_FIELDS)
            ->groupStart()->where('auth_id', $authId)->orWhere('expires_at <=', time())->groupEnd()
            ->get()
            ->getResultArray();
        foreach ($old as $row) {
            $this->db->table($this->table)->where('id', $row['id'])->delete();
            (int) $row['auth_id'] === $authId
                ? $log->deleted($authId, 'Replaced activation link', $this->table, (int) $row['id'], $row)
                : $log->deleted(null, 'Deleted expired activation link', $this->table, (int) $row['id'], $row);
        }

        $token = bin2hex(random_bytes(32));
        $row   = [
            'auth_id'    => $authId,
            'token_hash' => self::hashToken($token),
            'expires_at' => $expiresAt,
        ];
        $this->db->table($this->table)->insert($row);
        $log->inserted($authId, 'Started activation link', $this->table, (int) $this->db->insertID(), $row);

        return $token;
    }

    /**
     * Find an activation by the token in the link, expired or not
     * @param string $token
     * @return array|null
     */
    public function findByToken(string $token): ?array
    {
        return $this->where('token_hash', self::hashToken($token))->first();
    }

    /**
     * Delete an activation
     * @param int $id
     * @return bool false when it was already gone
     */
    public function consume(int $id): bool
    {
        $log = new ActivityLog();

        return $this->inTransaction(fn () => $this->deleteActivation($id, $log));
    }

    /**
     * Delete an activation and flip its account from pending (P) to active (A), in one transaction
     * @param int $id
     * @return bool false when the activation was already gone or the account is no longer pending; nothing is changed then
     */
    public function consumeAndActivate(int $id): bool
    {
        $log = new ActivityLog();

        return $this->inTransaction(function () use ($id, $log) {
            $authId = $this->db->table($this->table)->select('auth_id')->where('id', $id)->get()->getRowArray()['auth_id'] ?? null;
            if ($authId === null || ! $this->deleteActivation($id, $log)) {
                return false;
            }

            $authId = (int) $authId;
            $this->db->table('auth_master')
                ->where('id', $authId)
                ->where('account_status', AuthMasterModel::STATUS_PENDING)
                ->update(['account_status' => AuthMasterModel::STATUS_ACTIVE]);
            if ($this->db->affectedRows() !== 1) {
                return false;
            }
            $log->updated($authId, 'Activated account from the emailed link', 'auth_master', $authId,
                ['account_status' => AuthMasterModel::STATUS_PENDING], ['account_status' => AuthMasterModel::STATUS_ACTIVE]);

            return true;
        });
    }

    /**
     * Delete an activation and log it; call inside a transaction
     * @param int         $id
     * @param ActivityLog $log
     * @return bool false when it was already gone
     */
    private function deleteActivation(int $id, ActivityLog $log): bool
    {
        $row = $this->db->table($this->table)->select(self::LOG_FIELDS)->where('id', $id)->get()->getRowArray();
        $this->db->table($this->table)->where('id', $id)->delete();
        if ($row === null || $this->db->affectedRows() !== 1) {
            return false;
        }
        $log->deleted((int) $row['auth_id'], 'Ended activation link', $this->table, $id, $row);

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
