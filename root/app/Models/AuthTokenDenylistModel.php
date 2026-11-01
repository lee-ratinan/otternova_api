<?php

namespace App\Models;

use App\Libraries\ActivityLog;
use CodeIgniter\Model;

/**
 * Model for the auth_token_denylist table: access tokens revoked by /logout, by jti
 */
class AuthTokenDenylistModel extends Model
{
    use TransactionTrait;

    protected $table            = 'auth_token_denylist';
    protected $primaryKey       = 'jti';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'jti',
        'expires_at',
    ];

    // created_at is maintained by the database
    protected $useTimestamps = false;

    /**
     * Revoke a token until its exp; rows of tokens that have expired anyway are cleared out on the way.
     * Both are logged to log_activity in the same transaction.
     * @param string $jti
     * @param int    $expiresAt Unix seconds
     * @param int    $authId    auth_master.id of the account signing out
     * @return void
     */
    public function revoke(string $jti, int $expiresAt, int $authId): void
    {
        $log = new ActivityLog();
        $this->inTransaction(function () use ($jti, $expiresAt, $authId, $log) {
            $expired = $this->db->table($this->table)->where('expires_at <', time())->get()->getResultArray();
            foreach ($expired as $row) {
                $this->db->table($this->table)->where('jti', $row['jti'])->delete();
                $log->deleted(null, 'Deleted expired token from the denylist', $this->table, null, $row);
            }

            if (! $this->isRevoked($jti)) {
                $row = ['jti' => $jti, 'expires_at' => $expiresAt];
                $this->db->table($this->table)->insert($row);
                $log->inserted($authId, 'Signed out', $this->table, null, $row);
            }
        });
    }

    /**
     * @param string $jti
     * @return bool
     */
    public function isRevoked(string $jti): bool
    {
        return $this->where('jti', $jti)->countAllResults() > 0;
    }
}
