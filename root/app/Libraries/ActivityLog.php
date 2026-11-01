<?php

namespace App\Libraries;

use App\Models\LogActivityModel;
use CodeIgniter\Database\Exceptions\DatabaseException;

/**
 * Writes log_activity rows for the API's database changes: one row per row inserted, updated or deleted,
 * with the values in activity_detail. Write the log in the same transaction as the change where there is one,
 * so a change is never kept without its log.
 *
 * activity_detail is {"action": "insert", "new": {...}}, {"action": "update", "changes": {column: {"old", "new"}}}
 * or {"action": "delete", "old": {...}}. Secrets are never stored. The encrypted columns (FieldEncryption::FIELDS)
 * never show their values: an insert or delete shows "***" for a value that is set, and a change shows
 * {"old": "***", "new": "***"}, so the log records that it changed and nothing more.
 * Pass plaintext rows; ciphertext is masked the same way.
 */
class ActivityLog
{
    /** Columns left out of the log altogether */
    public const SECRET_FIELDS = ['password_hash', 'otp_hash', 'token_hash'];

    public const MASK = '***';

    /**
     * @param int|null $actorId auth_master.id of whoever made the change; null for the system
     * @param string   $summary
     * @param string   $table
     * @param int|null $rowId   Null when the row has no integer id
     * @param array    $row     The inserted values
     * @return void
     */
    public function inserted(?int $actorId, string $summary, string $table, ?int $rowId, array $row): void
    {
        $this->write($actorId, $summary, $table, $rowId, ['action' => 'insert', 'new' => $this->clean($table, $row)]);
    }

    /**
     * Log the columns of $new that differ from $old; nothing when none does
     * @param int|null $actorId
     * @param string   $summary
     * @param string   $table
     * @param int|null $rowId
     * @param array    $old     The stored row
     * @param array    $new     The columns written
     * @return void
     */
    public function updated(?int $actorId, string $summary, string $table, ?int $rowId, array $old, array $new): void
    {
        $changes = [];
        foreach (self::changes($old, $new) as $field => $value) {
            if (in_array($field, self::SECRET_FIELDS, true) || in_array($field, FieldEncryption::fieldsOf($table), true)) {
                $changes[$field] = ['old' => self::MASK, 'new' => self::MASK];
            } else {
                $changes[$field] = ['old' => $old[$field] ?? null, 'new' => $value];
            }
        }
        if ($changes !== []) {
            $this->write($actorId, $summary, $table, $rowId, ['action' => 'update', 'changes' => $changes]);
        }
    }

    /**
     * @param int|null $actorId
     * @param string   $summary
     * @param string   $table
     * @param int|null $rowId
     * @param array    $row     The row as it was
     * @return void
     */
    public function deleted(?int $actorId, string $summary, string $table, ?int $rowId, array $row): void
    {
        $this->write($actorId, $summary, $table, $rowId, ['action' => 'delete', 'old' => $this->clean($table, $row)]);
    }

    /**
     * The columns of $new whose value differs from $old (compared as strings, as the drivers type them differently)
     * @param array $old
     * @param array $new
     * @return array
     */
    public static function changes(array $old, array $new): array
    {
        return array_filter($new, static function ($value, $field) use ($old) {
            $before = $old[$field] ?? null;

            return ($before === null ? null : (string) $before) !== ($value === null ? null : (string) $value);
        }, ARRAY_FILTER_USE_BOTH);
    }

    /**
     * @param string $table
     * @param array  $row
     * @return array
     */
    private function clean(string $table, array $row): array
    {
        $row = array_diff_key($row, array_flip(self::SECRET_FIELDS));
        foreach (FieldEncryption::fieldsOf($table) as $field) {
            if (array_key_exists($field, $row)) {
                $row[$field] = self::mask($row[$field]);
            }
        }

        return $row;
    }

    /**
     * @param mixed $value
     * @return string|null Null stays null, so the log still shows a value being set or cleared
     */
    private static function mask(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : self::MASK;
    }

    /**
     * @param int|null $actorId
     * @param string   $summary
     * @param string   $table
     * @param int|null $rowId
     * @param array    $detail
     * @return void
     * @throws DatabaseException When the row cannot be written, so the change rolls back with it
     */
    private function write(?int $actorId, string $summary, string $table, ?int $rowId, array $detail): void
    {
        $logs = model(LogActivityModel::class);
        $id   = $actorId === null
            ? $logs->logBySystem($summary, $table, $rowId, $detail)
            : $logs->logByAccount($actorId, $summary, $table, $rowId, $detail);
        if ($id === false) {
            throw new DatabaseException('log_activity: ' . implode(' ', $logs->errors()));
        }
    }
}
