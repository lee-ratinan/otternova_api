<?php

namespace App\Models;

use App\Libraries\ActivityLog;
use Throwable;

/**
 * Inserts and updates for the otternova_ledger_* models, each logged to log_activity in the same transaction.
 * Used by models that have $table and $publicIdLength (see PublicIdTrait); the rows must already be checked.
 */
trait LedgerWriteTrait
{
    use PublicIdTrait;
    use TransactionTrait;

    /**
     * @param array  $row     Checked columns; public_id is generated here
     * @param int    $actorId auth_master.id of the staff member creating it
     * @param string $summary For log_activity
     * @return int The new id
     * @throws Throwable When a write fails; nothing is written then
     */
    public function createLogged(array $row, int $actorId, string $summary): int
    {
        $log = new ActivityLog();

        return $this->withPublicId(fn (string $publicId) => $this->inTransaction(function () use ($row, $actorId, $summary, $log, $publicId) {
            $row = ['public_id' => $publicId] + $row;
            $this->db->table($this->table)->insert($row);
            $id = (int) $this->db->insertID();
            $log->inserted($actorId, $summary, $this->table, $id, $row);

            return $id;
        }));
    }

    /**
     * Only the columns whose value changes are written and logged; nothing at all when none does
     * @param array  $stored  The stored row
     * @param array  $row     Checked columns
     * @param int    $actorId auth_master.id of the staff member making the change
     * @param string $summary For log_activity
     * @return void
     * @throws Throwable When a write fails; nothing is written then
     */
    public function updateLogged(array $stored, array $row, int $actorId, string $summary): void
    {
        $changes = ActivityLog::changes($stored, $row);
        if ($changes === []) {
            return;
        }
        $log = new ActivityLog();
        $this->inTransaction(function () use ($stored, $changes, $actorId, $summary, $log) {
            $this->db->table($this->table)->where('id', $stored['id'])->update($changes);
            $log->updated($actorId, $summary, $this->table, (int) $stored['id'], $stored, $changes);
        });
    }
}
