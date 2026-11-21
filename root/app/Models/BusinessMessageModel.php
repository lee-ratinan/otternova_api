<?php

namespace App\Models;

use App\Libraries\ActivityLog;
use CodeIgniter\I18n\Time;
use CodeIgniter\Model;
use Throwable;

/**
 * Model for business_message and its timeline, business_message_event: the messages customers send a business through
 * its contact form on otternova_mart, read and handled by any of the business's active people (owners, managers and
 * employees alike).
 *
 * Unread is shared by the business: the first of its people to open a message marks it read for all of them, so one
 * message does not take several people's attention. Reading never resolves a message; resolving it records who
 * clicked Resolved (resolved_by), and reopening it clears that again. Every change of status is a business_message_event
 * of type S with who made it, and is logged to log_activity in the same transaction. Comments (type C) are added, and
 * deleted by their author only, never edited.
 *
 * Every lookup takes the business, so a message of one business can never be reached through another. Rows are by
 * their public_id outside the API.
 */
class BusinessMessageModel extends Model
{
    use PublicIdTrait;
    use TransactionTrait;

    public const TABLE       = 'business_message';
    public const EVENT_TABLE = 'business_message_event';

    public const STATUS_UNREAD   = 'N';
    public const STATUS_READ     = 'R';
    public const STATUS_RESOLVED = 'D';
    public const STATUS_SPAM     = 'S';

    /** What a status is called, for the activity log and the API */
    public const STATUS_NAMES = [
        self::STATUS_UNREAD   => 'unread',
        self::STATUS_READ     => 'read',
        self::STATUS_RESOLVED => 'resolved',
        self::STATUS_SPAM     => 'spam',
    ];

    public const EVENT_COMMENT  = 'C';
    public const EVENT_STATUS   = 'S';
    public const EVENT_REPLY    = 'O'; // a reply sent from the platform: a paid feature to come
    public const EVENT_INBOUND  = 'I'; // the customer's answer to such a reply: likewise

    /** The longest comment taken, in characters */
    public const COMMENT_MAX_LENGTH = 2000;

    /** The length of a list row's excerpt of the message, in characters */
    private const EXCERPT_LENGTH = 120;

    /** The columns the Client app shows of every message */
    private const COLUMNS = 'm.public_id, m.sender_name, m.email_address, m.phone_number, m.message_text, m.locale, m.message_status, '
        . 'm.spam_reason, m.first_read_at, m.resolved_at, m.response_seconds, m.created_at, '
        . 'r.full_name AS first_read_by_name, d.full_name AS resolved_by_name';

    protected $table            = self::TABLE;
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [];

    /** public_id: CHAR(12), see PublicIdTrait */
    protected int $publicIdLength = 12;

    // created_at and updated_at are maintained by the database (set here too, as SQLite has no ON UPDATE)
    protected $useTimestamps = false;

    /**
     * Store a message from the contact form of a business, logged to log_activity in the same transaction
     * @param int   $businessId
     * @param array $row Already validated and normalised: sender_name, email_address and/or phone_number,
     *                   message_text, locale, message_status (unread or spam), spam_reason, ip_address, user_agent
     * @return string Its public_id
     * @throws Throwable When a write fails; nothing is written then
     */
    public function receive(int $businessId, array $row): string
    {
        return $this->inTransaction(function () use ($businessId, $row): string {
            $row = ['public_id' => $this->newPublicId(), 'business_id' => $businessId] + $row;
            $this->db->table($this->table)->insert($row);
            (new ActivityLog())->inserted(null, 'Received message ' . $row['public_id'] . ' for business ' . $businessId,
                $this->table, (int) $this->db->insertID(), $row);

            return $row['public_id'];
        });
    }

    /**
     * How many messages of the business nobody has opened yet, spam left out: the count of the header's badge
     * @param int $businessId
     * @return int
     */
    public function unreadCount(int $businessId): int
    {
        return $this->db->table($this->table)
            ->where('business_id', $businessId)
            ->where('message_status', self::STATUS_UNREAD)
            ->countAllResults();
    }

    /**
     * One page of the messages of the business, newest first, with a short excerpt instead of their text.
     * search matches the sender's name, email address and telephone number, and the text.
     * @param int         $businessId
     * @param int         $start
     * @param int         $length
     * @param string      $search
     * @param string|null $status One of the STATUS_ constants; null for every message but spam
     * @return array{records_total: int, records_filtered: int, rows: list<array>}
     */
    public function listForBusiness(int $businessId, int $start, int $length, string $search, ?string $status): array
    {
        $builder = $this->selectMessages()->where('m.business_id', $businessId);
        if ($status === null) {
            $builder->where('m.message_status !=', self::STATUS_SPAM);
        } else {
            $builder->where('m.message_status', $status);
        }
        $total = $builder->countAllResults(false);
        if ($search !== '') {
            $char = $this->db->likeEscapeChar;
            $term = str_replace([$char, '%', '_'], [$char . $char, $char . '%', $char . '_'], $search);
            $builder->groupStart()
                ->like('m.sender_name', $term, 'both', null, true)
                ->orLike('m.email_address', $term, 'both', null, true)
                ->orLike('m.phone_number', $term, 'both', null, true)
                ->orLike('m.message_text', $term, 'both', null, true)
                ->groupEnd();
        }
        $filtered = $search === '' ? $total : $builder->countAllResults(false);
        $rows     = $builder->orderBy('m.created_at', 'DESC')->orderBy('m.id', 'DESC')->limit($length, $start)->get()->getResultArray();

        return ['records_total' => $total, 'records_filtered' => $filtered, 'rows' => array_map(static function (array $row): array {
            $text           = trim((string) preg_replace('/\s+/u', ' ', (string) $row['message_text']));
            $row['excerpt'] = mb_strlen($text, 'UTF-8') > self::EXCERPT_LENGTH ? mb_substr($text, 0, self::EXCERPT_LENGTH, 'UTF-8') . '…' : $text;
            unset($row['message_text'], $row['id']);

            return self::typed($row);
        }, $rows)];
    }

    /**
     * One message of the business, with its text and who read and resolved it; null when the business has no such
     * message. id is its business_message.id, for the other methods; the API does not send it.
     * @param int   $businessId
     * @param mixed $publicId From the request; anything but a well-formed public_id finds nothing
     * @return array|null
     */
    public function findForBusiness(int $businessId, mixed $publicId): ?array
    {
        if (! is_string($publicId) || ! preg_match('/^[0-9A-Za-z_-]{' . $this->publicIdLength . '}$/', $publicId)) {
            return null;
        }
        $row = $this->selectMessages()
            ->select('m.business_id')
            ->where('m.business_id', $businessId)
            ->where('m.public_id', $publicId)
            ->get()->getRowArray();

        return $row === null ? null : self::typed($row);
    }

    /**
     * The timeline of a message, oldest first: its comments and changes of status, with who made them
     * @param array $message  From findForBusiness()
     * @param int   $viewerId auth_master.id of who is looking, for is_own (which comments they may delete)
     * @return list<array{public_id: string, event_type: string, event_text: string|null, from_status: string|null,
     *     to_status: string|null, channel: string|null, delivery_status: string|null, author_name: string|null,
     *     is_own: bool, created_at: string}>
     */
    public function timeline(array $message, int $viewerId): array
    {
        $rows = $this->db->table(self::EVENT_TABLE . ' e')
            ->select('e.public_id, e.event_type, e.event_text, e.from_status, e.to_status, e.channel, e.delivery_status, '
                . 'e.auth_id, a.full_name AS author_name, e.created_at')
            ->join('auth_master a', 'a.id = e.auth_id', 'left')
            ->where('e.message_id', $message['id'])
            ->orderBy('e.created_at', 'ASC')
            ->orderBy('e.id', 'ASC')
            ->get()->getResultArray();

        return array_map(static function (array $row) use ($viewerId): array {
            $row['is_own'] = $row['auth_id'] !== null && (int) $row['auth_id'] === $viewerId;
            unset($row['auth_id']);

            return $row;
        }, $rows);
    }

    /**
     * Open a message: an unread one becomes read, for everyone at the business, by whoever opened it first. Any other
     * status stays as it is.
     * @param array $message From findForBusiness()
     * @param int   $actorId auth_master.id of who opened it
     * @return bool Whether it was unread
     * @throws Throwable When a write fails; nothing is written then
     */
    public function open(array $message, int $actorId): bool
    {
        return $message['message_status'] === self::STATUS_UNREAD && $this->setStatus($message, self::STATUS_READ, $actorId);
    }

    /**
     * Change the status of a message, with a timeline event and the activity log in the same transaction.
     * - Leaving unread for the first time sets first_read_by and first_read_at; they are never changed afterwards,
     *   not even when it is marked unread again.
     * - Resolving it sets resolved_by (who clicked Resolved) and resolved_at; the first resolution also sets
     *   response_seconds (clock time since it was received), kept from then on.
     * - Any other status clears resolved_by and resolved_at: it is no longer resolved.
     * The status is read again inside the transaction, so two people clicking at once do not both change it.
     * @param array  $message From findForBusiness()
     * @param string $status  One of the STATUS_ constants
     * @param int    $actorId auth_master.id of who changed it
     * @return bool Whether it changed: false when it was already so, or it is gone
     * @throws Throwable When a write fails; nothing is written then
     */
    public function setStatus(array $message, string $status, int $actorId): bool
    {
        return $this->inTransaction(function () use ($message, $status, $actorId): bool {
            $stored = $this->db->table($this->table)
                ->select('id, message_status, first_read_by, first_read_at, resolved_by, resolved_at, response_seconds, created_at')
                ->where('id', $message['id'])->get()->getRowArray();
            if ($stored === null || $stored['message_status'] === $status) {
                return false;
            }
            $now     = Time::now('UTC')->format('Y-m-d H:i:s');
            $changes = ['message_status' => $status, 'updated_at' => $now];
            if ($status !== self::STATUS_UNREAD && $stored['first_read_at'] === null) {
                $changes['first_read_by'] = $actorId;
                $changes['first_read_at'] = $now;
            }
            if ($status === self::STATUS_RESOLVED) {
                $changes['resolved_by'] = $actorId;
                $changes['resolved_at'] = $now;
                if ($stored['response_seconds'] === null) {
                    $changes['response_seconds'] = max(0, strtotime($now . ' UTC') - strtotime($stored['created_at'] . ' UTC'));
                }
            } elseif ($stored['resolved_at'] !== null) {
                $changes['resolved_by'] = null;
                $changes['resolved_at'] = null;
            }
            $this->db->table($this->table)->where('id', $stored['id'])->update($changes);
            $this->insertEvent((int) $stored['id'], (int) $message['business_id'], [
                'event_type'  => self::EVENT_STATUS,
                'from_status' => $stored['message_status'],
                'to_status'   => $status,
                'auth_id'     => $actorId,
            ]);
            unset($stored['created_at'], $changes['updated_at']);
            (new ActivityLog())->updated($actorId, 'Marked message ' . $message['public_id'] . ' ' . self::STATUS_NAMES[$status],
                $this->table, (int) $stored['id'], $stored, $changes);

            return true;
        });
    }

    /**
     * Add a comment to a message, logged in the same transaction. Comments are for the business's people only; the
     * customer never sees them.
     * @param array  $message From findForBusiness()
     * @param string $text    Trimmed, 1 to COMMENT_MAX_LENGTH characters
     * @param int    $actorId auth_master.id of who wrote it
     * @return string Its public_id
     * @throws Throwable When a write fails; nothing is written then
     */
    public function addComment(array $message, string $text, int $actorId): string
    {
        return $this->inTransaction(function () use ($message, $text, $actorId): string {
            $row = ['event_type' => self::EVENT_COMMENT, 'event_text' => $text, 'auth_id' => $actorId];
            [$id, $publicId] = $this->insertEvent((int) $message['id'], (int) $message['business_id'], $row);
            (new ActivityLog())->inserted($actorId, 'Commented on message ' . $message['public_id'], self::EVENT_TABLE, $id,
                ['public_id' => $publicId] + $row);

            return $publicId;
        });
    }

    /**
     * Delete a comment, when whoever asks wrote it (logged in the same transaction)
     * @param array $message  From findForBusiness()
     * @param mixed $publicId The comment's, from the request
     * @param int   $actorId  auth_master.id of who asks
     * @return string|null null when deleted; 'not_found' when the message has no such comment, 'not_author' when
     *                     someone else wrote it
     * @throws Throwable When a write fails; nothing is deleted then
     */
    public function deleteComment(array $message, mixed $publicId, int $actorId): ?string
    {
        if (! is_string($publicId) || ! preg_match('/^[0-9A-Za-z_-]{' . $this->publicIdLength . '}$/', $publicId)) {
            return 'not_found';
        }

        return $this->inTransaction(function () use ($message, $publicId, $actorId): ?string {
            $comment = $this->db->table(self::EVENT_TABLE)
                ->where('message_id', $message['id'])
                ->where('public_id', $publicId)
                ->where('event_type', self::EVENT_COMMENT)
                ->get()->getRowArray();
            if ($comment === null) {
                return 'not_found';
            }
            if ($comment['auth_id'] === null || (int) $comment['auth_id'] !== $actorId) {
                return 'not_author';
            }
            $this->db->table(self::EVENT_TABLE)->where('id', $comment['id'])->delete();
            (new ActivityLog())->deleted($actorId, 'Deleted comment ' . $publicId . ' on message ' . $message['public_id'],
                self::EVENT_TABLE, (int) $comment['id'], $comment);

            return null;
        });
    }

    /**
     * @param int   $messageId
     * @param int   $businessId
     * @param array $row The event's own columns
     * @return array{0: int, 1: string} Its id and public_id
     */
    private function insertEvent(int $messageId, int $businessId, array $row): array
    {
        $publicId = $this->newPublicIdIn(self::EVENT_TABLE);
        $this->db->table(self::EVENT_TABLE)->insert(['public_id' => $publicId, 'message_id' => $messageId, 'business_id' => $businessId] + $row);

        return [(int) $this->db->insertID(), $publicId];
    }

    /**
     * The messages with who first read and who resolved them
     * @return \CodeIgniter\Database\BaseBuilder
     */
    private function selectMessages()
    {
        return $this->db->table($this->table . ' m')
            ->select('m.id, ' . self::COLUMNS)
            ->join('auth_master r', 'r.id = m.first_read_by', 'left')
            ->join('auth_master d', 'd.id = m.resolved_by', 'left');
    }

    /**
     * @param array $row
     * @return array
     */
    private static function typed(array $row): array
    {
        foreach (['id', 'business_id'] as $key) {
            if (isset($row[$key])) {
                $row[$key] = (int) $row[$key];
            }
        }
        $row['response_seconds'] = $row['response_seconds'] === null ? null : (int) $row['response_seconds'];

        return $row;
    }
}
