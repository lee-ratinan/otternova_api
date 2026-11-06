<?php

namespace App\Models;

use App\Libraries\ActivityLog;
use CodeIgniter\Model;
use RuntimeException;
use Throwable;

/**
 * Model for the business_invitation table: an owner's invitation to someone with no account yet to work at their
 * business. Signing up with the invited email address, through the emailed link, links the new account to the
 * business with the invitation's role and owner's notes (accept()). The token in the link is not stored, only its hash.
 * One open invitation per business and email address; email addresses are kept lower-cased.
 */
class BusinessInvitationModel extends Model
{
    use TransactionTrait;

    /** The columns written to log_activity; the hash never is */
    private const LOG_FIELDS = 'id, business_id, email_address, client_role, owner_notes, expires_at, invited_by';

    protected $table            = 'business_invitation';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'business_id',
        'email_address',
        'client_role',
        'owner_notes',
        'token_hash',
        'expires_at',
        'invited_by',
    ];

    // created_at is maintained by the database
    protected $useTimestamps = false;

    /**
     * Invite an email address to a business, replacing the open invitation it may have there already (whose link stops
     * working); expired invitations of any business go too. Refused when the business's active people and its other
     * open invitations already fill $maxPeople, counted inside the transaction.
     * @param int         $businessId
     * @param string      $email      Lower-cased here
     * @param string      $role       BusinessClientLinkModel::ROLE_*
     * @param string|null $ownerNotes
     * @param int         $expiresAt  Unix seconds
     * @param int         $maxPeople  Config\PlanLimits::EMPLOYEES of the business's plan
     * @param int         $actorId    auth_master.id of the inviting owner
     * @return string|null The token for the link; null when refused by the cap (nothing is written then)
     * @throws Throwable When a write fails; nothing is written then
     */
    public function invite(int $businessId, string $email, string $role, ?string $ownerNotes, int $expiresAt, int $maxPeople, int $actorId): ?string
    {
        $email = mb_strtolower($email);
        $log   = new ActivityLog();

        return $this->inTransaction(function () use ($businessId, $email, $role, $ownerNotes, $expiresAt, $maxPeople, $actorId, $log) {
            $old = $this->db->table($this->table)
                ->select(self::LOG_FIELDS)
                ->groupStart()
                    ->groupStart()->where('business_id', $businessId)->where('email_address', $email)->groupEnd()
                    ->orWhere('expires_at <=', time())
                ->groupEnd()
                ->get()
                ->getResultArray();
            foreach ($old as $row) {
                $this->db->table($this->table)->where('id', $row['id'])->delete();
                $replaced = (int) $row['business_id'] === $businessId && $row['email_address'] === $email;
                $log->deleted($replaced ? $actorId : null, $replaced ? 'Replaced business invitation' : 'Deleted expired business invitation',
                    $this->table, (int) $row['id'], $row);
            }

            $taken = model(BusinessClientLinkModel::class)->activeCount($businessId) + $this->openCount($businessId);
            if ($taken >= $maxPeople) {
                return false; // rolls back the replaced row's deletion too
            }

            $token = bin2hex(random_bytes(32));
            $row   = [
                'business_id'   => $businessId,
                'email_address' => $email,
                'client_role'   => $role,
                'owner_notes'   => $ownerNotes,
                'token_hash'    => self::hashToken($token),
                'expires_at'    => $expiresAt,
                'invited_by'    => $actorId,
            ];
            $this->db->table($this->table)->insert($row);
            unset($row['token_hash']);
            $log->inserted($actorId, 'Invited ' . $email . ' to business ' . $businessId, $this->table, (int) $this->db->insertID(), $row);

            return $token;
        }) ?: null;
    }

    /**
     * How many invitations of a business are still open (not expired)
     * @param int $businessId
     * @return int
     */
    public function openCount(int $businessId): int
    {
        return $this->db->table($this->table)->where('business_id', $businessId)->where('expires_at >', time())->countAllResults();
    }

    /**
     * An open (not expired) invitation by the token in its link, with the name of its business
     * @param string $token
     * @return array|null The row and business_name; null when there is none or it has expired
     */
    public function findOpenByToken(string $token): ?array
    {
        if (! preg_match('/^[0-9a-f]{64}$/', $token)) {
            return null;
        }

        return $this->db->table($this->table . ' i')
            ->select('i.*, b.business_name')
            ->join('business_master b', 'b.id = i.business_id')
            ->where('i.token_hash', self::hashToken($token))
            ->where('i.expires_at >', time())
            ->get()
            ->getRowArray();
    }

    /**
     * Accept an invitation for the account with its email address: the invitation goes, and the client is linked to
     * the business with its role and owner's notes, starting today (or, with a terminated link there, taken back with
     * them). Run $createAccount first in the same transaction, when the account is being created now, so the account
     * is never left without the link it was invited to, nor linked without being created.
     * @param array         $invitation    findOpenByToken()
     * @param callable|null $createAccount Creates the account; its result is returned
     * @return mixed What $createAccount returned, or null
     * @throws Throwable When a write fails, or the account is not a client; nothing is written then
     */
    public function accept(array $invitation, ?callable $createAccount = null): mixed
    {
        $log = new ActivityLog();

        return $this->inTransaction(function () use ($invitation, $createAccount, $log) {
            $result  = $createAccount === null ? null : $createAccount();
            $account = $this->db->table('auth_master a')
                ->select('a.id AS auth_id, c.id AS client_id')
                ->join('client_master c', 'c.auth_id = a.id')
                ->where('LOWER(a.email_address) = ' . $this->db->escape($invitation['email_address']), null, false)
                ->where('a.account_type', AuthMasterModel::TYPE_CLIENT)
                ->get()
                ->getRowArray();
            if ($account === null) {
                throw new RuntimeException('No client account with the invited email address');
            }
            $authId = (int) $account['auth_id'];

            $row = $this->db->table($this->table)->select(self::LOG_FIELDS)->where('id', $invitation['id'])->get()->getRowArray();
            $this->db->table($this->table)->where('id', $invitation['id'])->delete();
            if ($row !== null) {
                $log->deleted($authId, 'Accepted business invitation', $this->table, (int) $row['id'], $row);
            }
            // already active there (e.g. added by an owner meanwhile): nothing more to do
            model(BusinessClientLinkModel::class)->link((int) $invitation['business_id'], (int) $account['client_id'],
                $invitation['client_role'], gmdate('Y-m-d'), $authId, $invitation['owner_notes']);

            return $result ?? true;
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
