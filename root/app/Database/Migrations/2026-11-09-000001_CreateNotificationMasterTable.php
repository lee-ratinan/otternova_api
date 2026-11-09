<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Creates the notification_master table: one row per recipient of a notification, so each one reads (and deletes)
 * their own. A slip sent for EXE and FIN to check becomes one row for each of their staff.
 * - auth_id: the recipient (auth_master), staff or client; their account_type tells which app target_url is in
 * - business_id: the business a client's notification is about, if any, so the client app can filter or switch to it
 * - event_type VARCHAR(64): one of the notifier's event list, such as payment.slip_submitted; it is also the key of
 *   the message in the Notification language file, and the list gives its icon and tone. Nothing shown is stored,
 *   so the message is rendered in the reader's language when read (English for staff, preferred_language for clients)
 * - message_params JSON: the placeholders of that message, such as {"business": "...", "amount": "..."}
 * - target_url VARCHAR(255): a path inside the recipient's app, such as /subscriptions/payments/Ab3xK9..., never a
 *   full URL (each app adds its own origin); NULL when there is nothing to open
 * - subject_table, subject_id: the row the notification is about, as in log_activity; the table is needed because
 *   the same id is in many tables (invoice 1234 paid, business 1234 asking for WHT approval). Used to mark every
 *   recipient's notification of the same subject read once someone has handled it
 * - read_at: NULL while unread. Read rows are deleted by their recipient, or by the cleanup job after the number of
 *   days in the config; no updated_at, as read_at is the only change
 * public_id is a NanoID (see PublicIdTrait); on MySQL it is ascii_bin, as NanoIDs tell upper from lower case.
 * Depends on auth_master and business_master.
 */
class CreateNotificationMasterTable extends Migration
{
    public function up()
    {
        $mysql  = $this->db->DBDriver === 'MySQLi';
        $fkName = static fn (string $name): string => $mysql ? $name : ''; // SQLite has no named foreign keys

        $this->forge->addField(['id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true]]);
        $this->forge->addField($mysql
            ? ['public_id CHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL']
            : ['public_id' => ['type' => 'CHAR', 'constraint' => 12]]);
        $this->forge->addField([
            'auth_id'        => ['type' => 'INT', 'unsigned' => true],
            'business_id'    => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'event_type'     => ['type' => 'VARCHAR', 'constraint' => 64],
            'message_params' => ['type' => 'JSON', 'null' => true],
            'target_url'     => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'subject_table'  => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'subject_id'     => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'read_at'        => ['type' => 'DATETIME', 'null' => true],
            // maintained by the database, not the model
            'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('public_id', 'uq_notification_master_public_id'); // named after the column, see PublicIdTrait
        $this->forge->addKey(['auth_id', 'read_at', 'created_at']); // the recipient's list, unread first, and the badge count
        $this->forge->addKey('read_at'); // the cleanup of old read rows
        $this->forge->addKey(['subject_table', 'subject_id']); // every recipient's notification of one subject
        $this->forge->addKey('business_id');
        // a notification goes with its recipient or business
        $this->forge->addForeignKey('auth_id', 'auth_master', 'id', 'CASCADE', 'CASCADE', $fkName('fk_notification_master_auth'));
        $this->forge->addForeignKey('business_id', 'business_master', 'id', 'CASCADE', 'CASCADE', $fkName('fk_notification_master_business'));
        $this->forge->createTable('notification_master', true); // no ENGINE option so the SQLite test database can run this too
    }

    public function down()
    {
        $this->forge->dropTable('notification_master', true);
    }
}
