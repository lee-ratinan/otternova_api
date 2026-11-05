<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Creates the artifact_review table: a customer's rating (1-5, checked by the model) and review of an artifact,
 * one per customer per artifact; writing again replaces it. Reviews are never deleted.
 * A review starts pending (P) and is seen by other customers only once approved (A); hidden (H) takes it down
 * again. Any client of the business may approve or hide one, not only its owners and managers. Only approved
 * reviews count towards artifact_master.rating_sum, rating_count and rating_average, which the model updates in the
 * same transaction as every change of review_status or of an approved review's rating.
 * reply_message is the business's public answer, if any.
 * public_id is a NanoID (see PublicIdTrait); on MySQL it is ascii_bin, as NanoIDs tell upper from lower case.
 * Depends on artifact_master and customer_master.
 */
class CreateArtifactReviewTable extends Migration
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
            'artifact_id'    => ['type' => 'INT', 'unsigned' => true],
            'customer_id'    => ['type' => 'INT', 'unsigned' => true],
            'rating'         => ['type' => 'TINYINT', 'unsigned' => true], // 1-5
            'review_title'   => ['type' => 'VARCHAR', 'constraint' => 255],
            'review_message' => ['type' => 'TEXT'],
            'review_status'  => ['type' => 'ENUM', 'constraint' => ['P', 'A', 'H'], 'default' => 'P'], // pending, approved, hidden
            'reply_message'  => ['type' => 'TEXT', 'null' => true],
            'replied_at'     => ['type' => 'DATETIME', 'null' => true],
            // maintained by the database, not the model
            'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP' . $onUpdate,
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('public_id', 'uq_artifact_review_public_id'); // named after the column, see PublicIdTrait
        $this->forge->addUniqueKey(['artifact_id', 'customer_id']); // one review per customer per artifact
        $this->forge->addKey(['artifact_id', 'review_status', 'created_at']); // an artifact's approved reviews, newest first
        $this->forge->addKey('customer_id');
        $this->forge->addForeignKey('artifact_id', 'artifact_master', 'id', 'CASCADE', 'RESTRICT', $fkName('fk_artifact_review_artifact'));
        $this->forge->addForeignKey('customer_id', 'customer_master', 'id', 'CASCADE', 'RESTRICT', $fkName('fk_artifact_review_customer'));
        $this->forge->createTable('artifact_review', true); // InnoDB is MySQL's default engine; no ENGINE option so the SQLite test database can run this too
    }

    public function down()
    {
        $this->forge->dropTable('artifact_review', true);
    }
}
