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
        'id'               => 'permit_empty|is_natural_no_zero',
        'business_id'      => 'required|is_natural_no_zero',
        'client_id'        => 'required|is_natural_no_zero',
        'client_status'    => 'permit_empty|in_list[A,T]',
        'client_role'      => 'permit_empty|in_list[O,M,E]',
        'start_date'       => 'required|valid_date[Y-m-d]',
        'termination_date' => 'permit_empty|valid_date[Y-m-d]',
    ];
    protected $validationMessages   = [];
    protected $skipValidation       = false;
    protected $cleanValidationRules = true;

    /**
     * Field configuration shared by the Form Builder (as 'fields') and the Table Builder (via toColumns()),
     * keyed by field name so a controller can unset() what it does not need.
     * client_status and termination_date are set by terminate() and link(), not edited directly.
     * @return array
     */
    public function getConfiguration(): array
    {
        $fields = [
            'business_id'      => ['type' => 'number', 'readonly_in' => ['edit']],
            'client_id'        => ['type' => 'number', 'readonly_in' => ['edit']],
            'client_status'    => ['type' => 'select', 'readonly_in' => ['add', 'edit']],
            'client_role'      => ['type' => 'select'],
            'start_date'       => ['type' => 'date', 'searchable' => false],
            'termination_date' => ['type' => 'date', 'readonly_in' => ['add', 'edit'], 'searchable' => false],
            'owner_notes'      => ['type' => 'textarea', 'rows' => 4, 'orderable' => false],
        ];
        $enums  = [
            'client_status' => [self::STATUS_ACTIVE => 'success', self::STATUS_TERMINATED => 'danger'],
            'client_role'   => [self::ROLE_OWNER => 'primary', self::ROLE_MANAGER => 'info', self::ROLE_EMPLOYEE => 'secondary'],
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
                    'business_id'   => $businessId,
                    'client_id'     => $clientId,
                    'client_status' => self::STATUS_ACTIVE,
                    'client_role'   => $role,
                    'start_date'    => $startDate,
                    'owner_notes'   => $ownerNotes,
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
