<?php

namespace App\Models;

use App\Libraries\ActivityLog;
use CodeIgniter\Model;
use Throwable;

/**
 * Model for the business_customer_note table: what the people of a business note down about one of its customers, one
 * row per note, with who wrote it (created_by, auth_master.id) and when. A note is of one business only. Notes are
 * added and deleted, never edited; each write is logged to log_activity in its transaction.
 */
class BusinessCustomerNoteModel extends Model
{
    use PublicIdTrait;
    use TransactionTrait;

    public const TABLE = 'business_customer_note';

    /** The longest note_text taken, in characters */
    public const MAX_LENGTH = 2000;

    protected $table            = self::TABLE;
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [];

    /** public_id: CHAR(12), see PublicIdTrait */
    protected int $publicIdLength = 12;

    // created_at and updated_at are maintained by the database
    protected $useTimestamps = false;

    /**
     * The notes of the business about the customer, newest first
     * @param int $businessId
     * @param int $customerId
     * @return list<array{public_id: string, note_text: string, created_by: int|null, created_by_name: string|null, created_at: string}>
     */
    public function notesOf(int $businessId, int $customerId): array
    {
        $rows = $this->db->table($this->table . ' n')
            ->select('n.public_id, n.note_text, n.created_by, a.full_name AS created_by_name, n.created_at')
            ->join('auth_master a', 'a.id = n.created_by', 'left')
            ->where('n.business_id', $businessId)
            ->where('n.customer_id', $customerId)
            ->orderBy('n.created_at', 'DESC')
            ->orderBy('n.id', 'DESC')
            ->get()
            ->getResultArray();

        return array_map(static fn (array $row): array => ['created_by' => $row['created_by'] === null ? null : (int) $row['created_by']] + $row, $rows);
    }

    /**
     * Add a note, logged in the same transaction
     * @param int    $businessId
     * @param int    $customerId
     * @param string $text      Trimmed, 1 to MAX_LENGTH characters
     * @param int    $actorId   auth_master.id of whoever writes it
     * @return string Its public_id
     * @throws Throwable When a write fails; nothing is written then
     */
    public function add(int $businessId, int $customerId, string $text, int $actorId): string
    {
        return $this->inTransaction(function () use ($businessId, $customerId, $text, $actorId): string {
            $row = ['public_id' => $this->newPublicId(), 'business_id' => $businessId, 'customer_id' => $customerId,
                'note_text' => $text, 'created_by' => $actorId];
            $this->db->table($this->table)->insert($row);
            (new ActivityLog())->inserted($actorId, 'Added a note about customer ' . $customerId . ' of business ' . $businessId,
                $this->table, (int) $this->db->insertID(), $row);

            return $row['public_id'];
        });
    }

    /**
     * Delete a note, logged in the same transaction
     * @param array $note    The business_customer_note row
     * @param int   $actorId
     * @return void
     * @throws Throwable When a write fails; nothing is deleted then
     */
    public function remove(array $note, int $actorId): void
    {
        $this->inTransaction(function () use ($note, $actorId): void {
            $this->db->table($this->table)->where('id', $note['id'])->delete();
            (new ActivityLog())->deleted($actorId, 'Deleted note ' . $note['public_id'] . ' about customer ' . $note['customer_id'],
                $this->table, (int) $note['id'], $note);
        });
    }
}
