<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * How fast the contact form's messages are answered, and what they turned out to be (App\Models\ContactMessageModel):
 * - first_read_at DATETIME: when a staff member first opened or marked it; never changed afterwards, not even when
 *   it is marked new again
 * - resolved_at DATETIME: when it was first marked done; never changed afterwards
 * - response_seconds INT: from created_at to resolved_at, worked out once when it is resolved. Clock time: working
 *   hours and holidays are not taken out (yet).
 * - message_category ENUM: what the message turned out to be, set by staff: I (issue: a real problem on our side),
 *   C (complaint), U (user problem: the customer's own doing), M (misunderstanding), X (irrelevant); NULL until set
 *
 * Messages already there: a read one (R) takes handled_at as first_read_at, and a done one (D) takes it as
 * resolved_at, since handled_at is the time of the last change of status. A done one's first read is not known.
 */
class AddContactMessageResolution extends Migration
{
    public const COLUMNS = ['first_read_at', 'resolved_at', 'response_seconds', 'message_category'];

    public function up()
    {
        $this->forge->addColumn('contact_message', [
            'first_read_at'    => ['type' => 'DATETIME', 'null' => true, 'after' => 'handled_at'],
            'resolved_at'      => ['type' => 'DATETIME', 'null' => true, 'after' => 'first_read_at'],
            'response_seconds' => ['type' => 'INT', 'unsigned' => true, 'null' => true, 'after' => 'resolved_at'],
            'message_category' => ['type' => 'ENUM', 'constraint' => ['I', 'C', 'U', 'M', 'X'], 'null' => true, 'after' => 'response_seconds'],
        ]);
        $this->forge->addKey('resolved_at', false, false, 'idx_contact_message_resolved_at');
        $this->forge->processIndexes('contact_message');

        $table = $this->db->table('contact_message');
        $table->set('first_read_at', 'handled_at', false)->where('status', 'R')->where('handled_at IS NOT NULL', null, false)->update();
        $done = $this->db->table('contact_message')->select('id, handled_at, created_at')
            ->where('status', 'D')->where('handled_at IS NOT NULL', null, false)->get()->getResultArray();
        foreach ($done as $row) {
            $this->db->table('contact_message')->where('id', $row['id'])->update([
                'resolved_at'      => $row['handled_at'],
                'response_seconds' => max(0, strtotime($row['handled_at'] . ' UTC') - strtotime($row['created_at'] . ' UTC')),
            ]);
        }
    }

    public function down()
    {
        $this->db->query($this->db->DBDriver === 'MySQLi'
            ? 'DROP INDEX idx_contact_message_resolved_at ON ' . $this->db->prefixTable('contact_message')
            : 'DROP INDEX IF EXISTS idx_contact_message_resolved_at');
        // SQLite (the test database) drops columns by rebuilding the table; the legacy rename keeps the other
        // tables' foreign keys pointing at it (see AddStoreAddressTranslations)
        $sqlite = $this->db->DBDriver === 'SQLite3';
        if ($sqlite) {
            $this->db->query('PRAGMA legacy_alter_table = ON');
        }
        try {
            $this->forge->dropColumn('contact_message', self::COLUMNS);
        } finally {
            if ($sqlite) {
                $this->db->query('PRAGMA legacy_alter_table = OFF');
            }
        }
    }
}
