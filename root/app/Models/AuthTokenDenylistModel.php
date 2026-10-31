<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for the auth_token_denylist table: access tokens revoked by /logout, by jti
 */
class AuthTokenDenylistModel extends Model
{
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
     * Revoke a token until its exp; rows of tokens that have expired anyway are cleared out on the way
     * @param string $jti
     * @param int    $expiresAt Unix seconds
     * @return void
     */
    public function revoke(string $jti, int $expiresAt): void
    {
        $this->where('expires_at <', time())->delete();
        if (! $this->isRevoked($jti)) {
            $this->insert(['jti' => $jti, 'expires_at' => $expiresAt]);
        }
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
