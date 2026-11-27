<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * moderation_report: a mart visitor's report of a business (artifact_id null) or one of its products or services, for
 * the staff to review (App\Models\ModerationReportModel). A report never changes the business by itself: the staff act
 * on it through moderation_event (flag, block, remove).
 *
 * - reason_code: ModerationReportModel::REASONS. details: what the visitor wrote. reporter_email: given only if they
 *   want to be contacted, shown to the staff only; the business never sees who reported it, nor that it was reported.
 * - ip_hash: an HMAC of the visitor's IP address, for the staff to see reports from one sender; the address itself is
 *   not kept.
 * - report_status ENUM('N', 'S', 'D', 'A'): new (awaiting the staff), filtered (spam: a duplicate, a link-stuffed one,
 *   or one about what the business declared, such as being LGBT-friendly or naturist; kept for the staff to look at but
 *   never counted), dismissed, or actioned (the staff flagged, blocked or removed what it is about).
 *   filter_reason: why it was filtered (ModerationReportModel::FILTER_ constants and ContactFormGuard::spamReason()).
 * - reviewed_by, reviewed_at and staff_note: the staff member who last changed report_status.
 * Depends on business_master, artifact_master and auth_master.
 */
class AddModerationReport extends Migration
{
    public function up()
    {
        $mysql  = $this->db->DBDriver === 'MySQLi';
        $fkName = static fn (string $name): string => $mysql ? $name : ''; // SQLite has no named foreign keys
        $this->forge->addField(['id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true]]);
        // public_id is a NanoID (see PublicIdTrait); on MySQL it is ascii_bin, as NanoIDs tell upper from lower case
        $this->forge->addField($mysql
            ? ['public_id CHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL']
            : ['public_id' => ['type' => 'CHAR', 'constraint' => 12]]);
        $this->forge->addField([
            'business_id'    => ['type' => 'INT', 'unsigned' => true],
            'artifact_id'    => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'reason_code'    => ['type' => 'VARCHAR', 'constraint' => 30],
            'details'        => ['type' => 'TEXT', 'null' => true],
            'reporter_email' => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => true],
            'locale'         => ['type' => 'VARCHAR', 'constraint' => 10],
            'ip_hash'        => ['type' => 'CHAR', 'constraint' => 64],
            'report_status'  => ['type' => 'ENUM', 'constraint' => ['N', 'S', 'D', 'A'], 'default' => 'N'],
            'filter_reason'  => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true],
            'staff_note'     => ['type' => 'TEXT', 'null' => true],
            'reviewed_by'    => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'reviewed_at'    => ['type' => 'DATETIME', 'null' => true],
            // maintained by the database
            'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('public_id', 'uq_moderation_report_public_id');
        $this->forge->addKey(['business_id', 'report_status', 'id'], false, false, 'moderation_report_business_status');
        $this->forge->addKey(['artifact_id', 'id'], false, false, 'moderation_report_artifact_id');
        $this->forge->addKey('reviewed_by', false, false, 'moderation_report_reviewed_by');
        $this->forge->addForeignKey('business_id', 'business_master', 'id', 'CASCADE', 'RESTRICT', $fkName('fk_moderation_report_business'));
        $this->forge->addForeignKey('artifact_id', 'artifact_master', 'id', 'CASCADE', 'RESTRICT', $fkName('fk_moderation_report_artifact'));
        $this->forge->addForeignKey('reviewed_by', 'auth_master', 'id', 'CASCADE', 'SET NULL', $fkName('fk_moderation_report_reviewed_by'));
        $this->forge->createTable('moderation_report', true); // no ENGINE option so the SQLite test database can run this too
    }

    public function down()
    {
        $this->forge->dropTable('moderation_report', true);
    }
}
