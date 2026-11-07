<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Creates the withholding_tax_certificate table: a certificate (50 ทวิ) a business uploads for the tax it withheld
 * from an invoice. An invoice can have several, such as one that staff reject and the right one after it; uploading
 * one sets subscription_invoice.wht_cert_status to U, and staff accepting it to A. A certificate that comes after its
 * invoice got a top-up (T) is still accepted: the top-up is voided if unpaid, or refunded.
 * certificate_no is the book and number on it (เล่มที่ / เลขที่), and issued_on, base_amount and wht_amount are as
 * written on it, for staff to check against the invoice's amount_due and wht_amount.
 * cert_status: P pending (waiting for staff), A accepted, R rejected (rejection_reason).
 * file_path is under Config\Billing::$whtCertUploadPath; never under public/.
 * public_id is a NanoID (see PublicIdTrait); on MySQL it is ascii_bin, as NanoIDs tell upper from lower case.
 * Depends on subscription_invoice and auth_master.
 */
class CreateWithholdingTaxCertificateTable extends Migration
{
    public function up()
    {
        $mysql = $this->db->DBDriver === 'MySQLi';
        // SQLite (the test database) has no ON UPDATE; there the model has to be relied on instead
        $onUpdate = $mysql ? ' ON UPDATE CURRENT_TIMESTAMP' : '';
        $fkName   = static fn (string $name): string => $mysql ? $name : ''; // nor named foreign keys

        $this->forge->addField(['id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true]]);
        $this->forge->addField($mysql
            ? ['public_id CHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL']
            : ['public_id' => ['type' => 'CHAR', 'constraint' => 12]]);
        $this->forge->addField([
            'invoice_id'       => ['type' => 'INT', 'unsigned' => true],
            'certificate_no'   => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => true],
            'issued_on'        => ['type' => 'DATE'], // local date written on it
            'base_amount'      => ['type' => 'DECIMAL', 'constraint' => '19,2'],
            'wht_amount'       => ['type' => 'DECIMAL', 'constraint' => '19,2'],
            'file_path'        => ['type' => 'VARCHAR', 'constraint' => 255],
            'cert_status'      => ['type' => 'ENUM', 'constraint' => ['P', 'A', 'R'], 'default' => 'P'], // pending, accepted, rejected
            'rejection_reason' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'uploaded_by'      => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'reviewed_by'      => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'reviewed_at'      => ['type' => 'DATETIME', 'null' => true],
            // maintained by the database, not the model
            'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP' . $onUpdate,
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('public_id', 'uq_withholding_tax_certificate_public_id'); // named after the column, see PublicIdTrait
        $this->forge->addKey('invoice_id');
        $this->forge->addKey(['cert_status', 'created_at']); // the certificates waiting for staff
        $this->forge->addKey('uploaded_by');
        $this->forge->addKey('reviewed_by');
        $this->forge->addForeignKey('invoice_id', 'subscription_invoice', 'id', 'CASCADE', 'RESTRICT', $fkName('fk_withholding_tax_certificate_invoice'));
        $this->forge->addForeignKey('uploaded_by', 'auth_master', 'id', 'CASCADE', 'SET NULL', $fkName('fk_withholding_tax_certificate_uploaded_by'));
        $this->forge->addForeignKey('reviewed_by', 'auth_master', 'id', 'CASCADE', 'SET NULL', $fkName('fk_withholding_tax_certificate_reviewed_by'));
        $this->forge->createTable('withholding_tax_certificate', true);
    }

    public function down()
    {
        $this->forge->dropTable('withholding_tax_certificate', true);
    }
}
