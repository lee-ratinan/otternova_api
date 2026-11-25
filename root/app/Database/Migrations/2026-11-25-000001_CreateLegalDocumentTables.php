<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Creates the tables that record which version of the Terms of Service and the Privacy Policy each person accepted
 * (App\Models\LegalDocumentModel):
 * - legal_document_version: one row per published version of a document, never changed once it is in effect.
 *   audience: B for the businesses on the Client app (auth_master), M for the marketplace's customers
 *   (customer_master; nothing writes these yet). document_type: T Terms of Service, P Privacy Policy.
 *   version_label is what people see ("2025-12-01"); effective_from (UTC) is when it becomes the current version, the
 *   latest effective_from not in the future being current. requires_reacceptance Y: a material change, so everyone
 *   who accepted an earlier version must accept again before going on; N: they are only told.
 * - legal_document_text: the full text of a version in each language, as shown, with its SHA-256, so what someone
 *   accepted can be shown exactly later. change_summary is what the "we have updated" page says changed.
 * - legal_acceptance: append-only, one row per person per version accepted. Exactly one of auth_id and customer_id
 *   is set (the model checks it; the test database cannot). locale is the language they were shown; context S at
 *   sign-up, I at sign-up from a business invitation, R accepting again after a change. ip_address and user_agent
 *   are kept as evidence. Deleting the person deletes their rows (retention is to be decided later).
 * Depends on auth_master and customer_master.
 */
class CreateLegalDocumentTables extends Migration
{
    public function up()
    {
        $mysql = $this->db->DBDriver === 'MySQLi';
        // SQLite (the test database) names its foreign keys itself
        $fk = static fn (string $name): string => $mysql ? $name : '';

        $this->forge->addField(['id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true]]);
        $this->forge->addField($mysql
            ? ['public_id CHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL']
            : ['public_id' => ['type' => 'CHAR', 'constraint' => 8]]);
        $this->forge->addField([
            'audience'              => ['type' => 'ENUM', 'constraint' => ['B', 'M'], 'default' => 'B'],
            'document_type'         => ['type' => 'ENUM', 'constraint' => ['T', 'P']],
            'version_label'         => ['type' => 'VARCHAR', 'constraint' => 32],
            'effective_from'        => ['type' => 'DATETIME'],
            'requires_reacceptance' => ['type' => 'ENUM', 'constraint' => ['Y', 'N'], 'default' => 'Y'],
            'created_by_acc'        => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            // maintained by the database; no updated_at, a version is never changed
            'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('public_id', 'uq_legal_document_version_public_id');
        $this->forge->addUniqueKey(['audience', 'document_type', 'version_label'], 'uq_legal_document_version_label');
        $this->forge->addKey(['audience', 'document_type', 'effective_from'], false, false, 'audience_document_type_effective_from');
        $this->forge->addForeignKey('created_by_acc', 'auth_master', 'id', 'CASCADE', 'SET NULL', $fk('fk_legal_document_version_created_by'));
        $this->forge->createTable('legal_document_version', true); // no ENGINE option so the SQLite test database can run this too

        $this->forge->addField([
            'id'             => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'version_id'     => ['type' => 'INT', 'unsigned' => true],
            'locale'         => ['type' => 'VARCHAR', 'constraint' => 16],
            'body'           => ['type' => 'MEDIUMTEXT'],
            'body_sha256'    => ['type' => 'CHAR', 'constraint' => 64],
            'change_summary' => ['type' => 'TEXT', 'null' => true],
            'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['version_id', 'locale'], 'uq_legal_document_text_version_locale');
        $this->forge->addForeignKey('version_id', 'legal_document_version', 'id', 'CASCADE', 'CASCADE', $fk('fk_legal_document_text_version'));
        $this->forge->createTable('legal_document_text', true);

        $this->forge->addField([
            'id'          => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'version_id'  => ['type' => 'INT', 'unsigned' => true],
            'auth_id'     => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'customer_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'locale'      => ['type' => 'VARCHAR', 'constraint' => 16],
            'context'     => ['type' => 'ENUM', 'constraint' => ['S', 'I', 'R']], // sign-up, invitation sign-up, re-acceptance
            'ip_address'  => ['type' => 'VARCHAR', 'constraint' => 45], // IPv6 at most
            'user_agent'  => ['type' => 'VARCHAR', 'constraint' => 512, 'null' => true],
            'accepted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        ]);
        $this->forge->addPrimaryKey('id');
        // accepting the same version twice adds nothing; NULLs do not clash, so each person type has its own key
        $this->forge->addUniqueKey(['auth_id', 'version_id'], 'uq_legal_acceptance_auth_version');
        $this->forge->addUniqueKey(['customer_id', 'version_id'], 'uq_legal_acceptance_customer_version');
        $this->forge->addKey(['version_id', 'accepted_at'], false, false, 'version_id_accepted_at'); // who accepted a version
        // versions are never deleted, so RESTRICT keeps the evidence from going with one
        $this->forge->addForeignKey('version_id', 'legal_document_version', 'id', 'CASCADE', 'RESTRICT', $fk('fk_legal_acceptance_version'));
        $this->forge->addForeignKey('auth_id', 'auth_master', 'id', 'CASCADE', 'CASCADE', $fk('fk_legal_acceptance_auth'));
        $this->forge->addForeignKey('customer_id', 'customer_master', 'id', 'CASCADE', 'CASCADE', $fk('fk_legal_acceptance_customer'));
        $this->forge->createTable('legal_acceptance', true);
    }

    public function down()
    {
        $this->forge->dropTable('legal_acceptance', true);
        $this->forge->dropTable('legal_document_text', true);
        $this->forge->dropTable('legal_document_version', true);
    }
}
