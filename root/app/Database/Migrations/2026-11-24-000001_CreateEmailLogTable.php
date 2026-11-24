<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Creates email_log (App\Models\EmailLogModel): an append-only log of every email App\Libraries\EmailService sends,
 * one row per send(), written after the attempt whatever came of it.
 *
 * - email_type VARCHAR(64): the EmailService::TYPE_* constant, e.g. 'order_placed'
 * - recipient VARCHAR(254): the address the email was for (254: the longest address SMTP allows)
 * - delivered_to VARCHAR(254): where it really went; differs from recipient when beta redirects it to a tester,
 *   NULL when it was not handed to the mail server (status X)
 * - locale VARCHAR(16): the language it was written in; NULL for the request's locale
 * - subject VARCHAR(255): as sent, placeholders filled in
 * - status ENUM('S', 'F', 'X'): sent (the mail server took it), failed (the mail server refused it), suppressed (not
 *   sent at all: development, testing, or a beta address that is not a tester's; the log line stands in for it)
 * - error_message VARCHAR(500): why a failure failed, as the mail library reports it
 * No body, no placeholders: they carry sign-in codes and password-reset and activation links, which must not outlive
 * the email. The type and subject say what it was; the template and language file say the rest.
 */
class CreateEmailLogTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'            => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'email_type'    => ['type' => 'VARCHAR', 'constraint' => 64],
            'recipient'     => ['type' => 'VARCHAR', 'constraint' => 254],
            'delivered_to'  => ['type' => 'VARCHAR', 'constraint' => 254, 'null' => true],
            'locale'        => ['type' => 'VARCHAR', 'constraint' => 16, 'null' => true],
            'subject'       => ['type' => 'VARCHAR', 'constraint' => 255],
            'status'        => ['type' => 'ENUM', 'constraint' => ['S', 'F', 'X']], // sent, failed, suppressed
            'error_message' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            // maintained by the database, not the model; no updated_at, a log row is never changed
            'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        ]);
        $this->forge->addPrimaryKey('id');
        // what one address was sent ("did the customer get it?")
        $this->forge->addKey(['recipient', 'created_at'], false, false, 'recipient_created_at');
        // volumes and failures of one kind of email over time
        $this->forge->addKey(['email_type', 'created_at'], false, false, 'email_type_created_at');
        $this->forge->addKey(['status', 'created_at'], false, false, 'status_created_at');
        $this->forge->createTable('email_log', true); // no ENGINE option so the SQLite test database can run this too
    }

    public function down()
    {
        $this->forge->dropTable('email_log', true);
    }
}
