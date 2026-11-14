<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * A customer's way into their order history at one business on the mart (CreateOrderHistoryAccessTable): a link
 * emailed to their address, used once, which becomes a session. The tokens are never stored, only their SHA-256.
 * Every method takes the business, and a token of another business finds nothing.
 */
class OrderHistoryAccessModel extends Model
{
    protected $table      = 'order_history_access';
    protected $primaryKey = 'id';
    protected $returnType = 'array';

    // created_at is maintained by the database
    protected $useTimestamps = false;

    /**
     * A new link for the email address, replacing the unused ones it still has at the business; the rows of every
     * address that are no use any more (link expired unused, session over) go too
     * @param int $expiresAt Unix seconds
     * @return string the token for the link
     */
    public function createLink(int $businessId, string $email, int $expiresAt, int $now): string
    {
        $token = self::newToken();
        $this->db->transStart();
        $this->db->table($this->table)->where('business_id', $businessId)->where('email_address', $email)->where('link_used_at', null)->delete();
        $this->purge($now);
        $this->db->table($this->table)->insert([
            'business_id'     => $businessId,
            'email_address'   => $email,
            'link_hash'       => self::hash($token),
            'link_expires_at' => $expiresAt,
        ]);
        $this->db->transComplete();

        return $token;
    }

    /**
     * Open the emailed link: once, before it expires. It becomes a session.
     * @param int $sessionExpiresAt Unix seconds
     * @return string|null the session's token; null for a link that is wrong, used or expired
     */
    public function openLink(int $businessId, string $token, int $sessionExpiresAt, int $now): ?string
    {
        if ($token === '' || strlen($token) > 128) {
            return null;
        }
        $session = self::newToken();
        // the condition and the change in one statement, so a link opened twice at once opens once
        $this->db->table($this->table)
            ->where('business_id', $businessId)->where('link_hash', self::hash($token))
            ->where('link_used_at', null)->where('link_expires_at >', $now)
            ->update(['link_used_at' => $now, 'session_hash' => self::hash($session), 'session_expires_at' => $sessionExpiresAt]);

        return $this->db->affectedRows() === 1 ? $session : null;
    }

    /**
     * The email address of a session that has not ended
     * @return string|null
     */
    public function emailOf(int $businessId, string $session, int $now): ?string
    {
        if ($session === '' || strlen($session) > 128) {
            return null;
        }
        $row = $this->db->table($this->table)->select('email_address')
            ->where('business_id', $businessId)->where('session_hash', self::hash($session))->where('session_expires_at >', $now)
            ->get()->getRowArray();

        return $row['email_address'] ?? null;
    }

    /** End a session (the customer signs out) */
    public function endSession(int $businessId, string $session): void
    {
        if ($session !== '' && strlen($session) <= 128) {
            $this->db->table($this->table)->where('business_id', $businessId)->where('session_hash', self::hash($session))->delete();
        }
    }

    /** Delete the rows of any address that are no use any more */
    private function purge(int $now): void
    {
        $this->db->table($this->table)
            ->groupStart()->where('link_used_at', null)->where('link_expires_at <=', $now)->groupEnd()
            ->orGroupStart()->where('session_expires_at IS NOT NULL', null, false)->where('session_expires_at <=', $now)->groupEnd()
            ->delete();
    }

    /** 32 random bytes, URL-safe */
    private static function newToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
