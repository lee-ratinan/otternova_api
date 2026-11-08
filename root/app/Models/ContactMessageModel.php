<?php

namespace App\Models;

use App\Libraries\ActivityLog;
use CodeIgniter\Model;

/**
 * Model for the contact_message table: messages from the public contact form
 */
class ContactMessageModel extends Model
{
    use TransactionTrait;

    public const STATUS_NEW  = 'N';
    public const STATUS_READ = 'R';
    public const STATUS_DONE = 'D';
    public const STATUS_SPAM = 'S';

    /** What a status is called, for the activity log */
    public const STATUS_NAMES = [
        self::STATUS_NEW  => 'new',
        self::STATUS_READ => 'read',
        self::STATUS_DONE => 'done',
        self::STATUS_SPAM => 'spam',
    ];

    protected $table          = 'contact_message';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useSoftDeletes = false;
    protected $protectFields  = true;
    protected $allowedFields  = [
        'name',
        'email_address',
        'phone_number',
        'subject',
        'message',
        'locale',
        'status',
        'spam_reason',
        'ip_address',
        'user_agent',
        'handled_by',
        'handled_at',
    ];

    // created_at is maintained by the database
    protected $useTimestamps = false;

    /**
     * Store a message from the contact form, logged to log_activity in the same transaction
     * @param array $row Already validated and normalised by App\Controllers\Contact
     * @return int The new contact_message.id
     */
    public function receive(array $row): int
    {
        $log = new ActivityLog();

        return $this->inTransaction(function () use ($row, $log) {
            $this->db->table($this->table)->insert($row);
            $id = (int) $this->db->insertID();
            $log->inserted(null, 'Received contact message from ' . $row['email_address'], $this->table, $id, $row);

            return $id;
        });
    }

    /**
     * One page of the messages for the Admin app's inbox, newest first, without their text but a short excerpt.
     * search matches the sender's name and email address and the subject.
     * @param int         $start
     * @param int         $length
     * @param string      $search
     * @param string|null $status One of the STATUS_ constants; null for every message
     * @return array{records_total: int, records_filtered: int, rows: list<array>}
     */
    public function listForStaff(int $start, int $length, string $search, ?string $status): array
    {
        $builder = $this->db->table($this->table . ' m')
            ->select('m.id, m.name, m.email_address, m.phone_number, m.subject, m.message, m.locale, m.status, m.spam_reason')
            ->select('m.handled_at, m.created_at, a.full_name AS handled_by_name')
            ->join('auth_master a', 'a.id = m.handled_by', 'left');
        if ($status !== null) {
            $builder->where('m.status', $status);
        }
        $total = $builder->countAllResults(false);
        if ($search !== '') {
            $char = $this->db->likeEscapeChar;
            $term = str_replace([$char, '%', '_'], [$char . $char, $char . '%', $char . '_'], $search);
            $builder->groupStart()
                ->like('m.name', $term, 'both', null, true)
                ->orLike('m.email_address', $term, 'both', null, true)
                ->orLike('m.subject', $term, 'both', null, true)
                ->groupEnd();
        }
        $filtered = $search === '' ? $total : $builder->countAllResults(false);
        $rows     = $builder->orderBy('m.id', 'DESC')->limit($length, $start)->get()->getResultArray();

        return ['records_total' => $total, 'records_filtered' => $filtered, 'rows' => array_map(static function (array $row): array {
            $text           = trim((string) preg_replace('/\s+/u', ' ', (string) $row['message']));
            $row['excerpt'] = mb_strlen($text) > 120 ? mb_substr($text, 0, 120) . '…' : $text;
            unset($row['message']);

            return self::typed($row);
        }, $rows)];
    }

    /**
     * One message as the Admin app shows it, with who handled it; null when there is none
     * @param int $id
     * @return array|null
     */
    public function findForStaff(int $id): ?array
    {
        $row = $this->db->table($this->table . ' m')
            ->select('m.id, m.name, m.email_address, m.phone_number, m.subject, m.message, m.locale, m.status, m.spam_reason')
            ->select('m.ip_address, m.user_agent, m.handled_at, m.created_at, a.full_name AS handled_by_name')
            ->join('auth_master a', 'a.id = m.handled_by', 'left')
            ->where('m.id', $id)
            ->get()->getRowArray();

        return $row === null ? null : self::typed($row);
    }

    /**
     * Mark a message new, read, done or spam, as the staff member who handled it (logged to log_activity in the same
     * transaction). Marking it new again forgets who handled it.
     * @param int    $id
     * @param string $status  One of the STATUS_ constants
     * @param int    $actorId auth_master.id of the staff member
     * @return bool Whether it changed: false when it was already so, or there is no such message
     */
    public function setStatus(int $id, string $status, int $actorId): bool
    {
        $log = new ActivityLog();

        return $this->inTransaction(function () use ($id, $status, $actorId, $log) {
            $stored = $this->db->table($this->table)->select('id, status, handled_by, handled_at')->where('id', $id)->get()->getRowArray();
            if ($stored === null || $stored['status'] === $status) {
                return false;
            }
            $changes = $status === self::STATUS_NEW
                ? ['status' => $status, 'handled_by' => null, 'handled_at' => null]
                : ['status' => $status, 'handled_by' => $actorId, 'handled_at' => gmdate('Y-m-d H:i:s')];
            $this->db->table($this->table)->where('id', $id)->update($changes);
            $log->updated($actorId, 'Marked contact message ' . $id . ' ' . self::STATUS_NAMES[$status], $this->table, $id, $stored, $changes);

            return true;
        });
    }

    /**
     * @param array $row
     * @return array
     */
    private static function typed(array $row): array
    {
        $row['id'] = (int) $row['id'];

        return $row;
    }
}
