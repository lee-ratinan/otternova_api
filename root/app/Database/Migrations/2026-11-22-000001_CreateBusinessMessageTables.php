<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Creates the tables of the messages customers send a business through its contact form on otternova_mart:
 * - business_message: one row per message. The customer gives a name, an email address or a telephone number (at
 *   least one of the two, checked by the database too, as the business answers off the platform) and the message.
 *   message_status is N (unread), R (read, still open), D (resolved) or S (spam). Unread is shared by the business:
 *   the first of its people to open a message marks it read for all of them (first_read_at/by, set once). Opening a
 *   message does not resolve it; resolved_by is the auth_master.id of whoever clicked Resolved, and resolved_at when,
 *   both cleared when it is reopened. response_seconds is worked out at the first resolution and kept.
 *   Messages the content checks flag are kept with status S and spam_reason; honeypot hits and repeats are never
 *   stored. ip_address and user_agent are kept for spam review only.
 * - business_message_event: the timeline of a message, oldest first. event_type C is a comment by one of the
 *   business's people (event_text; deleted by its author only, never edited); S is a change of status (from_status,
 *   to_status), auth_id being who made it. O (a reply sent from the platform) and I (the customer's answer to it) are
 *   for a paid feature to come: they use channel (E email, S SMS) and, for O, delivery_status (Q queued, S sent,
 *   F failed); unused until then.
 * public_id is a NanoID (see PublicIdTrait); on MySQL it is ascii_bin, as NanoIDs tell upper from lower case.
 * Depends on business_master and auth_master.
 */
class CreateBusinessMessageTables extends Migration
{
    private const STATUSES = ['N', 'R', 'D', 'S'];

    public function up()
    {
        $mysql = $this->db->DBDriver === 'MySQLi';
        // SQLite (the test database) has no ON UPDATE; there the model has to be relied on instead
        $onUpdate = $mysql ? ' ON UPDATE CURRENT_TIMESTAMP' : '';
        $fk       = static fn (string $name): string => $mysql ? $name : ''; // nor named foreign keys
        $publicId = $mysql
            ? ['public_id CHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL']
            : ['public_id' => ['type' => 'CHAR', 'constraint' => 12]];

        $this->forge->addField(['id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true]]);
        $this->forge->addField($publicId);
        $this->forge->addField([
            'business_id'      => ['type' => 'INT', 'unsigned' => true],
            'sender_name'      => ['type' => 'VARCHAR', 'constraint' => 100],
            'email_address'    => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => true], // stored lower-cased
            'phone_number'     => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
            'message_text'     => ['type' => 'TEXT'],
            'locale'           => ['type' => 'VARCHAR', 'constraint' => 10], // one of Config\App::$supportedLocales
            'message_status'   => ['type' => 'ENUM', 'constraint' => self::STATUSES, 'default' => 'N'],
            'spam_reason'      => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
            'first_read_by'    => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'first_read_at'    => ['type' => 'DATETIME', 'null' => true],
            'resolved_by'      => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'resolved_at'      => ['type' => 'DATETIME', 'null' => true],
            'response_seconds' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'ip_address'       => ['type' => 'VARCHAR', 'constraint' => 45], // IPv6 at most
            'user_agent'       => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            // maintained by the database, not the model
            'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP' . $onUpdate,
            // the business can only answer off the platform when it has one of the two
            'CONSTRAINT chk_business_message_contact CHECK (email_address IS NOT NULL OR phone_number IS NOT NULL)',
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('public_id', 'uq_business_message_public_id'); // named after the column, see PublicIdTrait
        // the inbox of a business by status, newest first, and the unread count of the header's badge
        $this->forge->addKey(['business_id', 'message_status', 'created_at'], false, false, 'idx_business_message_status_created_at');
        $this->forge->addKey(['business_id', 'created_at'], false, false, 'idx_business_message_created_at'); // the "All" tab
        $this->forge->addKey('ip_address', false, false, 'idx_business_message_ip_address'); // spam review
        $this->forge->addForeignKey('business_id', 'business_master', 'id', 'CASCADE', 'CASCADE', $fk('fk_business_message_business'));
        $this->forge->addForeignKey('first_read_by', 'auth_master', 'id', 'CASCADE', 'SET NULL', $fk('fk_business_message_first_read_by'));
        $this->forge->addForeignKey('resolved_by', 'auth_master', 'id', 'CASCADE', 'SET NULL', $fk('fk_business_message_resolved_by'));
        $this->forge->createTable('business_message', true); // no ENGINE option so the SQLite test database can run this too

        $this->forge->addField(['id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true]]);
        $this->forge->addField($publicId);
        $this->forge->addField([
            'message_id'      => ['type' => 'INT', 'unsigned' => true],
            'business_id'     => ['type' => 'INT', 'unsigned' => true], // the message's, so every query can stay within the business
            'event_type'      => ['type' => 'ENUM', 'constraint' => ['C', 'S', 'O', 'I']], // comment, status, outbound reply, inbound reply
            'event_text'      => ['type' => 'TEXT', 'null' => true],
            'from_status'     => ['type' => 'ENUM', 'constraint' => self::STATUSES, 'null' => true],
            'to_status'       => ['type' => 'ENUM', 'constraint' => self::STATUSES, 'null' => true],
            'auth_id'         => ['type' => 'INT', 'unsigned' => true, 'null' => true], // who; null for the customer (I)
            'channel'         => ['type' => 'ENUM', 'constraint' => ['E', 'S'], 'null' => true], // email, SMS: O and I only
            'delivery_status' => ['type' => 'ENUM', 'constraint' => ['Q', 'S', 'F'], 'null' => true], // queued, sent, failed: O only
            // maintained by the database, not the model
            'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('public_id', 'uq_business_message_event_public_id');
        $this->forge->addKey(['message_id', 'created_at'], false, false, 'idx_business_message_event_timeline'); // the timeline
        $this->forge->addKey('business_id', false, false, 'idx_business_message_event_business_id');
        $this->forge->addKey('auth_id', false, false, 'idx_business_message_event_auth_id');
        $this->forge->addForeignKey('message_id', 'business_message', 'id', 'CASCADE', 'CASCADE', $fk('fk_business_message_event_message'));
        $this->forge->addForeignKey('business_id', 'business_master', 'id', 'CASCADE', 'CASCADE', $fk('fk_business_message_event_business'));
        $this->forge->addForeignKey('auth_id', 'auth_master', 'id', 'CASCADE', 'SET NULL', $fk('fk_business_message_event_auth'));
        $this->forge->createTable('business_message_event', true);
    }

    public function down()
    {
        $this->forge->dropTable('business_message_event', true);
        $this->forge->dropTable('business_message', true);
    }
}
