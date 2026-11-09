<?php

namespace App\Models;

use CodeIgniter\I18n\Time;
use CodeIgniter\Model;

/**
 * Model for the notification_master table: in-app notifications, one row per recipient (auth_id); written by
 * App\Libraries\Notifier. Every read and change here is of the recipient's own rows. Notifications are not logged to
 * log_activity: they only repeat changes that are.
 */
class NotificationMasterModel extends Model
{
    use PublicIdTrait;

    protected $table          = 'notification_master';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useSoftDeletes = false;
    protected $protectFields  = true;
    protected $allowedFields  = [
        'public_id',
        'auth_id',
        'business_id',
        'event_type',
        'message_params',
        'target_url',
        'subject_table',
        'subject_id',
        'read_at',
    ];

    // created_at is maintained by the database
    protected $useTimestamps = false;

    protected int $publicIdLength = 12;
    protected $beforeInsert       = ['fillPublicId'];

    /**
     * @param list<array> $rows Complete rows, each with its public_id
     * @return void
     */
    public function insertRows(array $rows): void
    {
        foreach ($rows as $row) {
            $this->db->table($this->table)->insert($row);
        }
    }

    /**
     * The recipient's unread count
     * @param int $authId
     * @return int
     */
    public function unreadCount(int $authId): int
    {
        return $this->db->table($this->table)->where('auth_id', $authId)->where('read_at', null)->countAllResults();
    }

    /**
     * One page of the recipient's notifications, newest first, with their business's slug and name
     * @param int       $authId
     * @param int       $start
     * @param int       $length
     * @param bool|null $read   true: read ones only, false: unread ones only, null: both
     * @return array{records_total: int, records_filtered: int, rows: list<array>}
     */
    public function listFor(int $authId, int $start, int $length, ?bool $read = null): array
    {
        $total   = $this->db->table($this->table)->where('auth_id', $authId)->countAllResults();
        $builder = $this->db->table($this->table . ' n')->where('n.auth_id', $authId);
        if ($read === true) {
            $builder->where('n.read_at IS NOT NULL', null, false);
        } elseif ($read === false) {
            $builder->where('n.read_at', null);
        }
        $filtered = $builder->countAllResults(false);
        $rows     = $builder
            ->select('n.*, b.business_slug, b.business_name')
            ->join('business_master b', 'b.id = n.business_id', 'left')
            ->orderBy('n.created_at', 'DESC')
            ->orderBy('n.id', 'DESC')
            ->limit($length, $start)
            ->get()->getResultArray();

        return ['records_total' => $total, 'records_filtered' => $filtered, 'rows' => $rows];
    }

    /**
     * The recipient's own notification by its public_id, with its business's slug and name
     * @param int   $authId
     * @param mixed $publicId From the request
     * @return array|null
     */
    public function findOwn(int $authId, mixed $publicId): ?array
    {
        if (! is_string($publicId) || ! preg_match('/^[0-9A-Za-z_-]{' . $this->publicIdLength . '}$/', $publicId)) {
            return null;
        }

        return $this->db->table($this->table . ' n')
            ->select('n.*, b.business_slug, b.business_name')
            ->join('business_master b', 'b.id = n.business_id', 'left')
            ->where('n.auth_id', $authId)
            ->where('n.public_id', $publicId)
            ->get()->getRowArray();
    }

    /**
     * Mark one of the recipient's notifications read, if it is not yet
     * @param int $authId
     * @param int $id
     * @return void
     */
    public function markRead(int $authId, int $id): void
    {
        $this->db->table($this->table)->where('auth_id', $authId)->where('id', $id)->where('read_at', null)
            ->update(['read_at' => Time::now('UTC')->toDateTimeString()]);
    }

    /**
     * Mark every notification of the recipient read
     * @param int $authId
     * @return int How many were unread
     */
    public function markAllRead(int $authId): int
    {
        $this->db->table($this->table)->where('auth_id', $authId)->where('read_at', null)
            ->update(['read_at' => Time::now('UTC')->toDateTimeString()]);

        return $this->db->affectedRows();
    }

    /**
     * Mark every recipient's unread notification of a subject read; see Notifier::markSubjectRead()
     * @param string      $table
     * @param int         $id
     * @param string|null $eventType
     * @return int
     */
    public function markSubjectRead(string $table, int $id, ?string $eventType = null): int
    {
        $builder = $this->db->table($this->table)->where('subject_table', $table)->where('subject_id', $id)->where('read_at', null);
        if ($eventType !== null) {
            $builder->where('event_type', $eventType);
        }
        $builder->update(['read_at' => Time::now('UTC')->toDateTimeString()]);

        return $this->db->affectedRows();
    }

    /**
     * Delete one of the recipient's notifications
     * @param int $authId
     * @param int $id
     * @return void
     */
    public function deleteOwn(int $authId, int $id): void
    {
        $this->db->table($this->table)->where('auth_id', $authId)->where('id', $id)->delete();
    }

    /**
     * Delete every read notification of the recipient
     * @param int $authId
     * @return int How many
     */
    public function deleteRead(int $authId): int
    {
        $this->db->table($this->table)->where('auth_id', $authId)->where('read_at IS NOT NULL', null, false)->delete();

        return $this->db->affectedRows();
    }

    /**
     * Delete everyone's notifications read before the cutoff; unread ones are kept however old
     * @param string $before UTC datetime
     * @return int How many
     */
    public function deleteReadBefore(string $before): int
    {
        $this->db->table($this->table)->where('read_at <', $before)->delete();

        return $this->db->affectedRows();
    }
}
