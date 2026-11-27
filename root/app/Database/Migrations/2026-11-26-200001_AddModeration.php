<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Otternova's review and moderation of what the businesses put on otternova_mart (App\Models\ModerationEventModel):
 *
 * - business_master.review_status and artifact_master.review_status ENUM('U', 'A', 'F', 'P') DEFAULT 'U': unreviewed,
 *   approved, flagged (Otternova asked the business to fix it), or pending re-review (the business changed it after it
 *   was flagged or blocked). A business opens on the mart without a review; the staff review it, and its products and
 *   services, afterwards.
 * - business_master.moderation_status, artifact_master.moderation_status and artifact_variant.moderation_status
 *   ENUM('N', 'B', 'R') DEFAULT 'N': none, blocked (taken off the mart until Otternova unblocks it) or removed (for
 *   good). Blocking or removing also closes the business (is_business_open N), or takes the artifact or variant off
 *   sale (artifact_status I, or D when removed; variant_status I), so the mart and its orders need no other check; the
 *   business cannot put them back while the block stands. Orders placed already are honoured.
 * - moderation_event: every review and moderation action on a business (artifact_id and variant_id null), an artifact
 *   (variant_id null) or a variant, and every time the business changed something flagged (RS), with who and when.
 *   previous_status is is_business_open, artifact_status or variant_status before a block or removal, given back on
 *   unblocking. client_message is what the business is told; internal_note stays with the staff.
 * Depends on business_master, artifact_master, artifact_variant and auth_master.
 */
class AddModeration extends Migration
{
    private const REVIEW     = ['type' => 'ENUM', 'constraint' => ['U', 'A', 'F', 'P'], 'default' => 'U', 'null' => false];
    private const MODERATION = ['type' => 'ENUM', 'constraint' => ['N', 'B', 'R'], 'default' => 'N', 'null' => false];

    public function up()
    {
        $this->forge->addColumn('business_master', [
            'review_status'     => self::REVIEW + ['after' => 'is_business_open'],
            'moderation_status' => self::MODERATION + ['after' => 'review_status'],
        ]);
        $this->forge->addKey('review_status', false, false, 'review_status');
        $this->forge->addKey('moderation_status', false, false, 'moderation_status');
        $this->forge->processIndexes('business_master');

        $this->forge->addColumn('artifact_master', [
            'review_status'     => self::REVIEW + ['after' => 'artifact_label'],
            'moderation_status' => self::MODERATION + ['after' => 'review_status'],
        ]);
        $this->forge->addKey(['business_id', 'review_status'], false, false, 'business_id_review_status');
        $this->forge->processIndexes('artifact_master');

        $this->forge->addColumn('artifact_variant', [
            'moderation_status' => self::MODERATION + ['after' => 'variant_status'],
        ]);

        $mysql  = $this->db->DBDriver === 'MySQLi';
        $fkName = static fn (string $name): string => $mysql ? $name : ''; // SQLite has no named foreign keys
        $this->forge->addField(['id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true]]);
        // public_id is a NanoID (see PublicIdTrait); on MySQL it is ascii_bin, as NanoIDs tell upper from lower case
        $this->forge->addField($mysql
            ? ['public_id CHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL']
            : ['public_id' => ['type' => 'CHAR', 'constraint' => 12]]);
        $this->forge->addField([
            'business_id'     => ['type' => 'INT', 'unsigned' => true],
            'artifact_id'     => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'variant_id'      => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'subject_type'    => ['type' => 'ENUM', 'constraint' => ['B', 'A', 'V']], // business, artifact, variant
            // approve, flag, block, unblock, remove, resubmitted (the business changed it after a flag or block)
            'event_action'    => ['type' => 'ENUM', 'constraint' => ['AP', 'FL', 'BL', 'UB', 'RM', 'RS']],
            'reason_code'     => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true],
            'client_message'  => ['type' => 'TEXT', 'null' => true],
            'internal_note'   => ['type' => 'TEXT', 'null' => true],
            'previous_status' => ['type' => 'CHAR', 'constraint' => 1, 'null' => true],
            'created_by'      => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            // maintained by the database; an event is never changed
            'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('public_id', 'uq_moderation_event_public_id');
        $this->forge->addKey(['business_id', 'id'], false, false, 'business_id_id');
        $this->forge->addKey(['artifact_id', 'id'], false, false, 'artifact_id_id');
        $this->forge->addKey(['variant_id', 'id'], false, false, 'variant_id_id');
        $this->forge->addKey('created_by', false, false, 'created_by');
        $this->forge->addForeignKey('business_id', 'business_master', 'id', 'CASCADE', 'RESTRICT', $fkName('fk_moderation_event_business'));
        $this->forge->addForeignKey('artifact_id', 'artifact_master', 'id', 'CASCADE', 'RESTRICT', $fkName('fk_moderation_event_artifact'));
        $this->forge->addForeignKey('variant_id', 'artifact_variant', 'id', 'CASCADE', 'RESTRICT', $fkName('fk_moderation_event_variant'));
        $this->forge->addForeignKey('created_by', 'auth_master', 'id', 'CASCADE', 'SET NULL', $fkName('fk_moderation_event_created_by'));
        $this->forge->createTable('moderation_event', true); // no ENGINE option so the SQLite test database can run this too
    }

    public function down()
    {
        $this->forge->dropTable('moderation_event', true);
        $sqlite = $this->db->DBDriver === 'SQLite3';
        if ($sqlite) {
            $this->db->query('PRAGMA legacy_alter_table = ON'); // see AddIsBusinessOpen
        }
        try {
            foreach (['business_master' => ['review_status', 'moderation_status'], 'artifact_master' => ['business_id_review_status']] as $table => $indexes) {
                foreach ($indexes as $index) {
                    $this->db->query($this->db->DBDriver === 'MySQLi'
                        ? 'DROP INDEX ' . $index . ' ON ' . $this->db->prefixTable($table)
                        : 'DROP INDEX IF EXISTS ' . $index);
                }
            }
            $this->forge->dropColumn('business_master', ['review_status', 'moderation_status']);
            $this->forge->dropColumn('artifact_master', ['review_status', 'moderation_status']);
            $this->forge->dropColumn('artifact_variant', 'moderation_status');
        } finally {
            if ($sqlite) {
                $this->db->query('PRAGMA legacy_alter_table = OFF');
            }
        }
    }
}
