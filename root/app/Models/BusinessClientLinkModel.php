<?php

namespace App\Models;

use App\Libraries\ActivityLog;
use CodeIgniter\Model;
use InvalidArgumentException;
use Throwable;

/**
 * Model for the business_client_link table: who works in which business, and as what.
 * One row per client per business: a rehire reactivates it, and log_activity keeps the history.
 * A business always keeps an active owner; the writes here refuse to terminate or demote its last one.
 */
class BusinessClientLinkModel extends Model
{
    use FieldConfigurationTrait;
    use TransactionTrait;

    // client_status
    public const STATUS_ACTIVE     = 'A';
    public const STATUS_TERMINATED = 'T';

    // client_role
    public const ROLE_OWNER    = 'O';
    public const ROLE_MANAGER  = 'M';
    public const ROLE_EMPLOYEE = 'E';

    // is_default_business
    public const DEFAULT_YES = 'Y';
    public const DEFAULT_NO  = 'N';

    protected $table            = 'business_client_link';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'business_id',
        'client_id',
        'client_status',
        'client_role',
        'is_default_business',
        'start_date',
        'termination_date',
        'owner_notes',
    ];

    // created_at and updated_at are maintained by the database
    protected $useTimestamps = false;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $validationRules = [
        'id'                  => 'permit_empty|is_natural_no_zero',
        'business_id'         => 'required|is_natural_no_zero',
        'client_id'           => 'required|is_natural_no_zero',
        'client_status'       => 'permit_empty|in_list[A,T]',
        'client_role'         => 'permit_empty|in_list[O,M,E]',
        'is_default_business' => 'permit_empty|in_list[Y,N]',
        'start_date'          => 'required|valid_date[Y-m-d]',
        'termination_date'    => 'permit_empty|valid_date[Y-m-d]',
    ];
    protected $validationMessages   = [];
    protected $skipValidation       = false;
    protected $cleanValidationRules = true;

    /**
     * Field configuration shared by the Form Builder (as 'fields') and the Table Builder (via toColumns()),
     * keyed by field name so a controller can unset() what it does not need.
     * client_status and termination_date are set by terminate() and link(), not edited directly;
     * is_default_business by firstLinkDefault() when the link is inserted.
     * @return array
     */
    public function getConfiguration(): array
    {
        $fields = [
            'business_id'         => ['type' => 'number', 'readonly_in' => ['edit']],
            'client_id'           => ['type' => 'number', 'readonly_in' => ['edit']],
            'client_status'       => ['type' => 'select', 'readonly_in' => ['add', 'edit']],
            'client_role'         => ['type' => 'select'],
            'is_default_business' => ['type' => 'select', 'readonly_in' => ['add', 'edit']],
            'start_date'          => ['type' => 'date', 'searchable' => false],
            'termination_date'    => ['type' => 'date', 'readonly_in' => ['add', 'edit'], 'searchable' => false],
            'owner_notes'         => ['type' => 'textarea', 'rows' => 4, 'orderable' => false],
        ];
        $enums  = [
            'client_status'       => [self::STATUS_ACTIVE => 'success', self::STATUS_TERMINATED => 'danger'],
            'client_role'         => [self::ROLE_OWNER => 'primary', self::ROLE_MANAGER => 'info', self::ROLE_EMPLOYEE => 'secondary'],
            'is_default_business' => [self::DEFAULT_YES => 'primary', self::DEFAULT_NO => 'secondary'],
        ];

        return $this->buildConfiguration('BusinessClientLink', $fields, $enums);
    }

    /**
     * The link of a client to a business, whatever its status
     * @param int $businessId
     * @param int $clientId
     * @return array|null
     */
    public function findLink(int $businessId, int $clientId): ?array
    {
        return $this->where('business_id', $businessId)->where('client_id', $clientId)->first();
    }

    /**
     * The business a client's app opens on after sign-in: their default one (is_default_business), else the first
     * they were linked to, among those they are active in.
     * @param int $clientId
     * @return array|null sessionBusiness(); null when the client is active in no business
     */
    public function signInBusinessOf(int $clientId): ?array
    {
        return $this->sessionBusiness($clientId, null);
    }

    /**
     * A business a client is active in, as signInBusinessOf() gives it, for the client app to switch to
     * @param int $clientId
     * @param int $businessId
     * @return array|null sessionBusiness(); null when the client is not active in it
     */
    public function activeBusinessOf(int $clientId, int $businessId): ?array
    {
        return $this->sessionBusiness($clientId, $businessId);
    }

    /**
     * Every business a client is active in, for their list of businesses: the default one first, then by name.
     * Only the public key of the business goes out (business_slug); the link is the client's own one in it.
     * @param int $clientId
     * @return list<array{business_slug: string, business_name: string, country_code: string, client_role: string, is_default_business: string}>
     */
    public function businessesOf(int $clientId): array
    {
        return $this->db->table($this->table . ' l')
            ->select('b.business_slug, b.business_name, b.country_code, l.client_role, l.is_default_business')
            ->join('business_master b', 'b.id = l.business_id')
            ->where('l.client_id', $clientId)
            ->where('l.client_status', self::STATUS_ACTIVE)
            ->orderBy('l.is_default_business', 'DESC') // Y before N
            ->orderBy('b.business_name')
            ->get()
            ->getResultArray();
    }

    /**
     * Make an active link its client's default business (is_default_business Y) and every other link of that
     * client not (N), in one transaction, each change logged to log_activity in it
     * @param array $link    findLink()
     * @param int   $actorId auth_master.id of whoever makes the change
     * @throws InvalidArgumentException When the link is terminated
     * @throws Throwable When a write fails; nothing is written then
     */
    public function makeDefault(array $link, int $actorId): void
    {
        if ($link['client_status'] !== self::STATUS_ACTIVE) {
            throw new InvalidArgumentException('Only an active link can be the default business');
        }
        $log = new ActivityLog();

        $this->inTransaction(function () use ($link, $actorId, $log) {
            $rows = $this->db->table($this->table)->where('client_id', $link['client_id'])->get()->getResultArray();
            foreach ($rows as $row) {
                $changes = ActivityLog::changes($row, [
                    'is_default_business' => (int) $row['id'] === (int) $link['id'] ? self::DEFAULT_YES : self::DEFAULT_NO,
                ]);
                if ($changes === []) {
                    continue;
                }
                $this->db->table($this->table)->where('id', $row['id'])->update($changes);
                $log->updated($actorId, 'Changed default business of client ' . $row['client_id'] . ' in business ' . $row['business_id'], $this->table, (int) $row['id'], $row, $changes);
            }

            return true;
        });
    }

    /**
     * signInBusinessOf() and activeBusinessOf(): an active link of a client with its business.
     * Only public keys go out (business_slug, business_type_public_id), and tax_id stays in, encrypted as it is.
     * @param int      $clientId
     * @param int|null $businessId null: the default business, else the first linked
     * @return array|null business_client_link columns, and under "business" the business_master ones with its plan_code
     *                    and subscription_status
     */
    private function sessionBusiness(int $clientId, ?int $businessId): ?array
    {
        $businessFields = [
            'business_name', 'business_slug', 'business_description', 'business_mood_and_tone', 'lgbt_audience',
            'naturist_audience', 'age_restricted', 'contact_person_name',
            'contact_email_address', 'contact_phone_number', 'contact_website', 'contact_address_line_1',
            'contact_address_line_2', 'contact_city', 'contact_subdivision_code', 'contact_postal_code', 'country_code',
            'currency_code',
        ];
        $builder = $this->db->table($this->table . ' l')
            ->select('l.business_id, l.client_role, l.client_status, l.is_default_business, l.start_date, t.public_id AS business_type_public_id')
            ->select(implode(', ', array_map(static fn ($field) => 'b.' . $field, $businessFields)))
            ->join('business_master b', 'b.id = l.business_id')
            ->join('business_type t', 't.id = b.business_type_id')
            ->where('l.client_id', $clientId)
            ->where('l.client_status', self::STATUS_ACTIVE);
        if ($businessId !== null) {
            $builder->where('l.business_id', $businessId);
        }
        $row = $builder
            ->orderBy('l.is_default_business', 'DESC') // Y before N
            ->orderBy('l.id')
            ->limit(1)
            ->get()
            ->getRowArray();
        if ($row === null) {
            return null;
        }

        // the plan it is on now: free unless its subscription is active (see SubscriptionMasterModel::planOfBusiness())
        $businessId = (int) $row['business_id'];
        unset($row['business_id']);
        $row += model(SubscriptionMasterModel::class)->planOfBusiness($businessId);

        $linkFields = ['client_role' => true, 'client_status' => true, 'is_default_business' => true, 'start_date' => true];

        return array_intersect_key($row, $linkFields) + ['business' => array_diff_key($row, $linkFields)];
    }

    /**
     * The active role of a client in a business, for permission checks
     * @param int $businessId
     * @param int $clientId
     * @return string|null One of the ROLE_ constants; null when the client is not active there
     */
    public function activeRole(int $businessId, int $clientId): ?string
    {
        $link = $this->findLink($businessId, $clientId);

        return $link !== null && $link['client_status'] === self::STATUS_ACTIVE ? $link['client_role'] : null;
    }

    /**
     * Link a client to a business, or reactivate their terminated link (rehire) with the new role and start date,
     * in one transaction, logged to log_activity in it
     * @param int         $businessId
     * @param int         $clientId
     * @param string      $role       One of the ROLE_ constants
     * @param string      $startDate  Y-m-d
     * @param int         $actorId    auth_master.id of whoever links them
     * @param string|null $ownerNotes
     * @return bool False when the client is already active in the business; nothing is written then
     * @throws Throwable When a write fails; nothing is written then
     */
    public function link(int $businessId, int $clientId, string $role, string $startDate, int $actorId, ?string $ownerNotes = null): bool
    {
        $log = new ActivityLog();

        return $this->inTransaction(function () use ($businessId, $clientId, $role, $startDate, $actorId, $ownerNotes, $log) {
            $stored = $this->findLink($businessId, $clientId);
            $what   = 'client ' . $clientId . ' to business ' . $businessId;
            if ($stored === null) {
                $row = [
                    'business_id'         => $businessId,
                    'client_id'           => $clientId,
                    'client_status'       => self::STATUS_ACTIVE,
                    'client_role'         => $role,
                    'is_default_business' => $this->firstLinkDefault($clientId),
                    'start_date'          => $startDate,
                    'owner_notes'         => $ownerNotes,
                ];
                $this->db->table($this->table)->insert($row);
                $log->inserted($actorId, 'Linked ' . $what, $this->table, (int) $this->db->insertID(), $row);

                return true;
            }
            if ($stored['client_status'] === self::STATUS_ACTIVE) {
                return false;
            }

            $changes = ActivityLog::changes($stored, [
                'client_status'    => self::STATUS_ACTIVE,
                'client_role'      => $role,
                'start_date'       => $startDate,
                'termination_date' => null,
            ] + ($ownerNotes === null ? [] : ['owner_notes' => $ownerNotes]));
            $this->db->table($this->table)->where('id', $stored['id'])->update($changes);
            $log->updated($actorId, 'Relinked ' . $what, $this->table, (int) $stored['id'], $stored, $changes);

            return true;
        });
    }

    /**
     * is_default_business of a new link of a client: a client's first link, whatever became of it since, is always
     * their default; any later one is not. Call it inside the transaction that inserts the link.
     * @param int $clientId
     * @return string DEFAULT_YES or DEFAULT_NO
     */
    public function firstLinkDefault(int $clientId): string
    {
        return $this->db->table($this->table)->where('client_id', $clientId)->countAllResults() === 0 ? self::DEFAULT_YES : self::DEFAULT_NO;
    }

    /**
     * Change the role of an active link, logged to log_activity in the same transaction.
     * Refused when it would demote the last active owner, checked inside the transaction.
     * @param array  $link    findLink()
     * @param string $role    One of the ROLE_ constants
     * @param int    $actorId auth_master.id of whoever makes the change
     * @return bool False when refused; nothing is written then
     * @throws InvalidArgumentException When the link is terminated
     * @throws Throwable When a write fails; nothing is written then
     */
    public function changeRole(array $link, string $role, int $actorId): bool
    {
        if ($link['client_status'] !== self::STATUS_ACTIVE) {
            throw new InvalidArgumentException('Only an active link can change its role');
        }

        return $this->writeLeavingAnOwner($link, ['client_role' => $role], 'Changed role of', $actorId);
    }

    /**
     * Terminate an active link, logged to log_activity in the same transaction.
     * Refused when it is the last active owner, checked inside the transaction.
     * @param array  $link            findLink()
     * @param string $terminationDate Y-m-d, not before its start_date
     * @param int    $actorId         auth_master.id of whoever terminates it
     * @return bool False when refused; nothing is written then
     * @throws InvalidArgumentException When the link is terminated already, or the date is before its start
     * @throws Throwable When a write fails; nothing is written then
     */
    public function terminate(array $link, string $terminationDate, int $actorId): bool
    {
        if ($link['client_status'] !== self::STATUS_ACTIVE) {
            throw new InvalidArgumentException('The link is terminated already');
        }
        if ($terminationDate < $link['start_date']) {
            throw new InvalidArgumentException('termination_date is before start_date');
        }

        return $this->writeLeavingAnOwner($link, ['client_status' => self::STATUS_TERMINATED, 'termination_date' => $terminationDate], 'Terminated', $actorId);
    }

    /**
     * Write $changes to an active link unless that leaves its business without an active owner
     * @param array  $link
     * @param array  $changes
     * @param string $summary
     * @param int    $actorId
     * @return bool
     * @throws Throwable
     */
    private function writeLeavingAnOwner(array $link, array $changes, string $summary, int $actorId): bool
    {
        $log = new ActivityLog();

        return $this->inTransaction(function () use ($link, $changes, $summary, $actorId, $log) {
            $after = $changes + $link;
            if ($link['client_role'] === self::ROLE_OWNER && ($after['client_role'] !== self::ROLE_OWNER || $after['client_status'] !== self::STATUS_ACTIVE)) {
                $otherOwners = $this->db->table($this->table)
                    ->where('business_id', $link['business_id'])
                    ->where('client_role', self::ROLE_OWNER)
                    ->where('client_status', self::STATUS_ACTIVE)
                    ->where('id !=', $link['id'])
                    ->countAllResults();
                if ($otherOwners === 0) {
                    return false;
                }
            }
            $changes = ActivityLog::changes($link, $changes);
            if ($changes !== []) {
                $this->db->table($this->table)->where('id', $link['id'])->update($changes);
                $log->updated($actorId, $summary . ' client ' . $link['client_id'] . ' in business ' . $link['business_id'], $this->table, (int) $link['id'], $link, $changes);
            }

            return true;
        });
    }
}
