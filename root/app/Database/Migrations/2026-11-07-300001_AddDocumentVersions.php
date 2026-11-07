<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Adds the documents of an invoice to subscription_invoice: which template it is rendered with, and its receipt.
 * A template is a view under app/Views/documents/ named by its version, such as th-invoice-01.php;
 * a document is always rendered with the version stored on it, so a new template never changes one already issued.
 * - invoice_version_id VARCHAR(30): the invoice's template, set when the invoice is made (INVOICE_TH_VERSION in
 *   Config\Constants); NOT NULL on MySQL
 * - receipt_no VARCHAR(30) NULL, unique: RCT-YYYYMM-000123, the month it was paid (UTC) and the invoice's id; set when
 *   the invoice is paid, as only a paid invoice has a receipt
 * - receipt_version_id VARCHAR(30) NULL: the receipt's template, set with receipt_no (RECEIPT_TH_VERSION)
 * The invoices already there get th-invoice-01, and the paid ones their receipt with th-receipt-01: the versions in
 * effect when this was written, not whatever the constants hold later.
 * Depends on subscription_invoice.
 */
class AddDocumentVersions extends Migration
{
    private const COLUMNS       = ['invoice_version_id', 'receipt_no', 'receipt_version_id'];
    private const RECEIPT_KEY   = 'uq_subscription_invoice_receipt_no';
    private const FIRST_INVOICE = 'th-invoice-01';
    private const FIRST_RECEIPT = 'th-receipt-01';

    public function up()
    {
        $this->forge->addColumn('subscription_invoice', [
            'invoice_version_id' => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true, 'after' => 'invoice_no'],
            'receipt_no'         => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true, 'after' => 'invoice_version_id'],
            'receipt_version_id' => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true, 'after' => 'receipt_no'],
        ]);
        $table = $this->db->prefixTable('subscription_invoice');
        // CREATE UNIQUE INDEX works on both; SQLite's index names are not per table, hence the table in the name
        $this->db->query('CREATE UNIQUE INDEX ' . $this->db->escapeIdentifiers(self::RECEIPT_KEY) . ' ON '
            . $this->db->escapeIdentifiers($table) . ' (' . $this->db->escapeIdentifiers('receipt_no') . ')');

        $this->db->table('subscription_invoice')->update(['invoice_version_id' => self::FIRST_INVOICE]);
        $paid = $this->db->table('subscription_invoice')->select('id, paid_at')->where('invoice_status', 'D')->where('paid_at IS NOT NULL')->get()->getResultArray();
        foreach ($paid as $row) {
            $this->db->table('subscription_invoice')->where('id', $row['id'])->update([
                'receipt_no'         => 'RCT-' . gmdate('Ym', strtotime($row['paid_at'] . ' UTC')) . '-' . str_pad((string) $row['id'], 6, '0', STR_PAD_LEFT),
                'receipt_version_id' => self::FIRST_RECEIPT,
            ]);
        }

        // SQLite (the test database) cannot change a column in place; there the billing code is relied on instead
        if ($this->db->DBDriver === 'MySQLi') {
            $this->db->query('ALTER TABLE ' . $this->db->escapeIdentifiers($table) . ' MODIFY `invoice_version_id` VARCHAR(30) NOT NULL');
        }
    }

    public function down()
    {
        $table = $this->db->escapeIdentifiers($this->db->prefixTable('subscription_invoice'));
        $index = $this->db->escapeIdentifiers(self::RECEIPT_KEY);
        $this->db->query($this->db->DBDriver === 'MySQLi' ? "DROP INDEX {$index} ON {$table}" : "DROP INDEX {$index}");
        $this->rebuildingTables(fn () => $this->forge->dropColumn('subscription_invoice', self::COLUMNS));
    }

    /**
     * SQLite (the test database) drops columns by rebuilding the table; the legacy rename keeps the other tables'
     * foreign keys pointing at it (see AddBusinessAudience)
     * @param callable $work
     * @return void
     */
    private function rebuildingTables(callable $work): void
    {
        $sqlite = $this->db->DBDriver === 'SQLite3';
        if ($sqlite) {
            $this->db->query('PRAGMA legacy_alter_table = ON');
        }
        try {
            $work();
        } finally {
            if ($sqlite) {
                $this->db->query('PRAGMA legacy_alter_table = OFF');
            }
        }
    }
}
