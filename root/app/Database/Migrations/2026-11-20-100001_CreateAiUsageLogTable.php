<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Creates ai_usage_log (App\Models\AiUsageLogModel): an append-only log of every call to the AI client, written by
 * App\Libraries\Ai\RecordingAiClient after each call, failed ones included, as a failed call can still be billed.
 *
 * - business_id: whose call it was; artifact_id or webpage_id: what it was for, at most one of them (the model makes
 *   sure); both NULL for a business-wide feature. Deleting the artifact or the page only blanks the reference, so the
 *   tokens stay with the business.
 * - feature VARCHAR(64): AiRequest::$feature, e.g. 'artifact_description'
 * - attempt_no SMALLINT: on a success, which successful attempt it is for its artifact or page (or the business, for
 *   a business-wide feature), feature and driver: 1, 2, 3... Failures are not attempts: they get NULL.
 * - driver ENUM('fake', 'gemini'): fake rows (local copies, load tests) never count as usage
 * - model VARCHAR(64): the model that answered, as the provider names it
 * - status ENUM('S', 'F'): succeeded, failed; error_kind VARCHAR(32): the AiException kind of a failure
 * - input_tokens, output_tokens: as the provider counts them (output includes thinking); 0 when unknown
 * - latency_ms: how long the call took
 * - requested_by: the account that asked (auth_master.id); NULL for jobs (php spark ...)
 * No cost column: prices change, so cost is worked out from the tokens and the model when reported.
 * Depends on business_master, artifact_master, webpage_master and auth_master.
 */
class CreateAiUsageLogTable extends Migration
{
    public function up()
    {
        $mysql = $this->db->DBDriver === 'MySQLi';
        // SQLite (the test database) names its foreign keys itself
        $fk = static fn (string $name): string => $mysql ? $name : '';
        $this->forge->addField([
            'id'            => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'business_id'   => ['type' => 'INT', 'unsigned' => true],
            'artifact_id'   => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'webpage_id'    => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'feature'       => ['type' => 'VARCHAR', 'constraint' => 64],
            'attempt_no'    => ['type' => 'SMALLINT', 'unsigned' => true, 'null' => true],
            'driver'        => ['type' => 'ENUM', 'constraint' => ['fake', 'gemini']],
            'model'         => ['type' => 'VARCHAR', 'constraint' => 64],
            'status'        => ['type' => 'ENUM', 'constraint' => ['S', 'F']], // succeeded, failed
            'error_kind'    => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
            'input_tokens'  => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'output_tokens' => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'latency_ms'    => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'requested_by'  => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            // maintained by the database, not the model; no updated_at, a log row is never changed
            'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        ]);
        $this->forge->addPrimaryKey('id');
        // a business's usage in a period (quotas, billing); also serves the business's foreign key
        $this->forge->addKey(['business_id', 'created_at'], false, false, 'business_id_created_at');
        // the attempts of one artifact's or page's feature; also serve their foreign keys
        $this->forge->addKey(['artifact_id', 'feature', 'status'], false, false, 'artifact_id_feature_status');
        $this->forge->addKey(['webpage_id', 'feature', 'status'], false, false, 'webpage_id_feature_status');
        $this->forge->addKey('requested_by', false, false, 'requested_by');
        // the log outlives what it was for: deleting the artifact, page or account only blanks the reference
        $this->forge->addForeignKey('business_id', 'business_master', 'id', 'CASCADE', 'CASCADE', $fk('fk_ai_usage_log_business'));
        $this->forge->addForeignKey('artifact_id', 'artifact_master', 'id', 'CASCADE', 'SET NULL', $fk('fk_ai_usage_log_artifact'));
        $this->forge->addForeignKey('webpage_id', 'webpage_master', 'id', 'CASCADE', 'SET NULL', $fk('fk_ai_usage_log_webpage'));
        $this->forge->addForeignKey('requested_by', 'auth_master', 'id', 'CASCADE', 'SET NULL', $fk('fk_ai_usage_log_requested_by'));
        $this->forge->createTable('ai_usage_log', true); // no ENGINE option so the SQLite test database can run this too
    }

    public function down()
    {
        $this->forge->dropTable('ai_usage_log', true);
    }
}
