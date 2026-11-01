<?php

namespace App\Database\Migrations;

use App\Libraries\ActivityLog;
use App\Libraries\FieldEncryption;
use CodeIgniter\Database\Migration;

/**
 * Encrypts the identity numbers (App\Libraries\FieldEncryption): staff_master.national_id_number and
 * tax_id_number, and client_master.national_id_number. Widens the columns to fit the ciphertext, then
 * encrypts the rows written before, logging each to log_activity as a change by the system.
 * Needs encryption.key in .env. Depends on staff_master and client_master.
 */
class EncryptIdentityNumbers extends Migration
{
    /** Ciphertext of the longest value the validation rules allow (255 characters), with the prefix */
    private const ENCRYPTED_LENGTH = 512;

    /** column => its length before encryption, per table */
    private const PLAINTEXT_LENGTHS = [
        'staff_master'  => ['national_id_number' => 255, 'tax_id_number' => 255],
        'client_master' => ['national_id_number' => 64],
    ];

    public function up()
    {
        foreach (FieldEncryption::FIELDS as $table => $fields) {
            $this->resize($table, array_fill_keys($fields, self::ENCRYPTED_LENGTH));
            $this->convert($table, $fields, true);
        }
    }

    public function down()
    {
        foreach (FieldEncryption::FIELDS as $table => $fields) {
            $this->convert($table, $fields, false);
            $this->resize($table, self::PLAINTEXT_LENGTHS[$table]);
        }
    }

    /**
     * @param string $table
     * @param array  $lengths column => VARCHAR length
     * @return void
     */
    private function resize(string $table, array $lengths): void
    {
        $columns = [];
        foreach ($lengths as $field => $length) {
            $columns[$field] = ['name' => $field, 'type' => 'VARCHAR', 'constraint' => $length, 'null' => true];
        }
        $this->forge->modifyColumn($table, $columns);
    }

    /**
     * Encrypt (or decrypt) every row that still needs it, in one transaction, logging each row
     * @param string $table
     * @param array  $fields
     * @param bool   $encrypt
     * @return void
     */
    private function convert(string $table, array $fields, bool $encrypt): void
    {
        $crypt = new FieldEncryption();
        $log   = new ActivityLog();

        $this->db->transException(true)->transStart();
        $rows = $this->db->table($table)->select(implode(', ', array_merge(['id'], $fields)))->get()->getResultArray();
        foreach ($rows as $row) {
            $new     = $encrypt ? $crypt->encryptRow($table, $row) : $crypt->decryptRow($table, $row);
            $changes = ActivityLog::changes($row, $new);
            if ($changes === []) {
                continue;
            }
            $this->db->table($table)->where('id', $row['id'])->update($changes);
            $log->updated(null, ($encrypt ? 'Encrypted' : 'Decrypted') . ' identity numbers', $table, (int) $row['id'], $row, $changes);
        }
        $this->db->transComplete();
        $this->db->transException(false);
    }
}
