<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Closing the books, month by month (App\Libraries\LedgerPeriods):
 * - otternova_ledger_entity.closed_through: the last day of the last closed month. No entry dated on or before it
 *   can be created, changed or lose its receipt, and the accounts with such entries keep their class. Months close
 *   in order, so everything up to it is closed; NULL while none is.
 * - otternova_ledger_period: each close of a month, and its reopening ('R'), kept as history: a month closed again
 *   after being reopened gets a new row.
 * - otternova_ledger_report_snapshot: the reports as they were when the month closed (content_json, with its
 *   sha256), so what was filed never changes when entries do later. report_type: TB trial balance, BS balance sheet,
 *   PL profit and loss of the month, PY profit and loss of the fiscal year to the month's end, GL the month's entries.
 * public_id is a NanoID (see PublicIdTrait); on MySQL it is ascii_bin, as NanoIDs tell upper from lower case.
 * Depends on otternova_ledger_entity and auth_master.
 */
class CreateLedgerPeriodTables extends Migration
{
    public function up()
    {
        $mysql = $this->db->DBDriver === 'MySQLi';
        $fk    = static fn (string $name): string => $mysql ? $name : ''; // SQLite has no named foreign keys

        $this->forge->addColumn('otternova_ledger_entity', [
            'closed_through' => ['type' => 'DATE', 'null' => true] + ($mysql ? ['after' => 'closed_on'] : []),
        ]);

        $this->forge->addField(['id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true]]);
        $this->forge->addField($mysql
            ? ['public_id CHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL']
            : ['public_id' => ['type' => 'CHAR', 'constraint' => 8]]);
        $this->forge->addField([
            'entity_id'     => ['type' => 'INT', 'unsigned' => true],
            'period_start'  => ['type' => 'DATE'], // the first day of the month
            'period_end'    => ['type' => 'DATE'], // the last day of the month
            'period_status' => ['type' => 'ENUM', 'constraint' => ['C', 'R'], 'default' => 'C'], // closed, reopened
            'closed_by'     => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'closed_at'     => ['type' => 'DATETIME'],
            'reopened_by'   => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'reopened_at'   => ['type' => 'DATETIME', 'null' => true],
            'reopen_reason' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('public_id', 'uq_otternova_ledger_period_public_id'); // named after the column, see PublicIdTrait
        $this->forge->addKey(['entity_id', 'period_start']);
        $this->forge->addKey('closed_by');
        $this->forge->addKey('reopened_by');
        $this->forge->addForeignKey('entity_id', 'otternova_ledger_entity', 'id', 'CASCADE', 'RESTRICT', $fk('fk_ledger_period_entity'));
        $this->forge->addForeignKey('closed_by', 'auth_master', 'id', 'CASCADE', 'SET NULL', $fk('fk_ledger_period_closed_by'));
        $this->forge->addForeignKey('reopened_by', 'auth_master', 'id', 'CASCADE', 'SET NULL', $fk('fk_ledger_period_reopened_by'));
        $this->forge->createTable('otternova_ledger_period', true); // no ENGINE option so the SQLite test database can run this too

        $this->forge->addField(['id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true]]);
        $this->forge->addField([
            'period_id'      => ['type' => 'INT', 'unsigned' => true],
            'report_type'    => ['type' => 'ENUM', 'constraint' => ['TB', 'BS', 'PL', 'PY', 'GL']],
            'date_from'      => ['type' => 'DATE', 'null' => true], // NULL for the reports from the first entry
            'date_to'        => ['type' => 'DATE'],
            'content_json'   => ['type' => $mysql ? 'MEDIUMTEXT' : 'TEXT'],
            'content_sha256' => ['type' => 'CHAR', 'constraint' => 64],
            'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['period_id', 'report_type'], 'uq_otternova_ledger_report_snapshot_period_type');
        $this->forge->addForeignKey('period_id', 'otternova_ledger_period', 'id', 'CASCADE', 'RESTRICT', $fk('fk_ledger_report_snapshot_period'));
        $this->forge->createTable('otternova_ledger_report_snapshot', true);
    }

    public function down()
    {
        $this->forge->dropTable('otternova_ledger_report_snapshot', true);
        $this->forge->dropTable('otternova_ledger_period', true);
        // SQLite (3.35 and later) drops a column that no index uses in place, without rebuilding the table
        $this->db->query('ALTER TABLE ' . $this->db->escapeIdentifiers('otternova_ledger_entity') . ' DROP COLUMN ' . $this->db->escapeIdentifiers('closed_through'));
    }
}
