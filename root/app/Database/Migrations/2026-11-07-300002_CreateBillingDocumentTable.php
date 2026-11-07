<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Creates the billing_document table: the invoice (document_type I) or the receipt (R) of a subscription_invoice,
 * frozen, so that it reads the same every time it is opened, whatever changes afterwards (the business's address,
 * the invoice's status, the template). An invoice's document is frozen when the invoice is made, a receipt's when
 * the invoice is paid; an invoice is never changed after that: a discount or a withholding change voids it and makes
 * a new one (see App\Libraries\SubscriptionBilling).
 *
 * - content_json: everything the template prints, worked out when frozen (App\Libraries\DocumentRenderer)
 * - html, html_sha256, rendered_at: the page, rendered once from content_json with the template version on the
 *   invoice (invoice_version_id, receipt_version_id) and, for an invoice, the PromptPay QR embedded; NULL until it is
 *   first opened, and never changed once set. A QR that cannot be fetched leaves it NULL, to be tried again.
 * Never deleted.
 * Depends on subscription_invoice.
 */
class CreateBillingDocumentTable extends Migration
{
    public function up()
    {
        $fkName = fn (string $name): string => $this->db->DBDriver === 'MySQLi' ? $name : ''; // SQLite has no named foreign keys

        $this->forge->addField([
            'id'            => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'invoice_id'    => ['type' => 'INT', 'unsigned' => true],
            'document_type' => ['type' => 'ENUM', 'constraint' => ['I', 'R']], // invoice, receipt
            'content_json'  => ['type' => 'MEDIUMTEXT'],
            'html'          => ['type' => 'MEDIUMTEXT', 'null' => true],
            'html_sha256'   => ['type' => 'CHAR', 'constraint' => 64, 'null' => true],
            'rendered_at'   => ['type' => 'DATETIME', 'null' => true],
            // maintained by the database, not the model
            'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['invoice_id', 'document_type'], 'uq_billing_document_invoice_type');
        $this->forge->addForeignKey('invoice_id', 'subscription_invoice', 'id', 'CASCADE', 'RESTRICT', $fkName('fk_billing_document_invoice'));
        $this->forge->createTable('billing_document', true);
    }

    public function down()
    {
        $this->forge->dropTable('billing_document', true);
    }
}
