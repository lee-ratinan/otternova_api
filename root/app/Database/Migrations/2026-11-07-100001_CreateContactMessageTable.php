<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Creates the contact_message table (App\Models\ContactMessageModel): one row per message sent through the public
 * contact form (POST /v1/contact). Messages the content checks flag as likely spam are kept with status S and the
 * reason, so they can be reviewed rather than lost; honeypot hits and repeats are never stored.
 * The sender's IP address and user agent are kept for spam review only.
 * Depends on auth_master (the staff member who handled the message).
 */
class CreateContactMessageTable extends Migration
{
    public function up()
    {
        $fkName = fn (string $name): string => $this->db->DBDriver === 'MySQLi' ? $name : ''; // SQLite has no named foreign keys

        $this->forge->addField([
            'id'            => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'name'          => ['type' => 'VARCHAR', 'constraint' => 100],
            'email_address' => ['type' => 'VARCHAR', 'constraint' => 128], // stored lower-cased
            'phone_number'  => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
            'subject'       => ['type' => 'VARCHAR', 'constraint' => 200],
            'message'       => ['type' => 'TEXT'],
            'locale'        => ['type' => 'VARCHAR', 'constraint' => 10], // one of Config\App::$supportedLocales
            'status'        => ['type' => 'ENUM', 'constraint' => ['N', 'R', 'D', 'S'], 'default' => 'N'], // new, read, done, spam
            'spam_reason'   => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true], // why the content checks flagged it
            'ip_address'    => ['type' => 'VARCHAR', 'constraint' => 45], // IPv6 at most
            'user_agent'    => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'handled_by'    => ['type' => 'INT', 'unsigned' => true, 'null' => true], // auth_master.id of the staff member
            'handled_at'    => ['type' => 'DATETIME', 'null' => true],
            // maintained by the database, not the model
            'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['status', 'created_at']);
        $this->forge->addKey('email_address');
        $this->forge->addKey('ip_address');
        $this->forge->addForeignKey('handled_by', 'auth_master', 'id', 'CASCADE', 'SET NULL', $fkName('fk_contact_message_handled_by'));
        $this->forge->createTable('contact_message', true); // no ENGINE option so the SQLite test database can run this too
    }

    public function down()
    {
        $this->forge->dropTable('contact_message', true);
    }
}
