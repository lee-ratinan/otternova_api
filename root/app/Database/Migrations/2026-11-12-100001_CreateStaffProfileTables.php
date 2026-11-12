<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * What a business records about its people besides where they work and what they are paid: the languages they speak,
 * anything else the business wants to note about them, and the customers they do not serve.
 *
 * Creates business_client_language: the languages a person (business_client_link) speaks, one row per language.
 * - language_code VARCHAR(16): BCP 47 (ja, yue, nan-TW), any language, not only those of listLanguages()
 * - proficiency ENUM('N', 'F', 'C', 'B') DEFAULT 'F': native, fluent, conversational, basic
 *
 * Creates business_client_attribute: what a business records about its people, defined by the business itself
 * ("Certification", "Specialized in", "Height"); with business_client_attribute_translation, its name in other
 * languages of listLanguages().
 * - attribute_name VARCHAR(64): unique per business
 * - is_public TINYINT(1) DEFAULT 1: shown with the person's profile to customers, or kept for managers only
 * - sort_order SMALLINT DEFAULT 0: the order on the profile
 *
 * Creates business_client_attribute_value: a person's values of an attribute, free text ("JLPT N1", "Sport massage",
 * "168 cm"), several per attribute if need be; with business_client_attribute_value_translation, the text in other
 * languages. (business_id, client_id) must be a business_client_link and (business_id, attribute_id) an attribute of
 * that business. A value goes with its business_client_link; an attribute cannot be deleted while it has values, so
 * the model deletes them first, each logged to log_activity.
 *
 * Creates business_client_restriction: the customers a person does not serve, at any service. It is told to the
 * customer before booking that person, who must acknowledge it; it is never enforced, as customers declare who they
 * are themselves.
 * - restriction_type ENUM('G', 'A'): a gender they do not serve, an age range they serve
 * - excluded_gender ENUM('M', 'F', 'NB') NULL: for G, one row per gender
 * - age_min, age_max TINYINT NULL: for A, either NULL for an open end; at most one A row per person (the model)
 *
 * All of it goes with the business_client_link (CASCADE); log_activity keeps the history.
 * Depends on business_master and business_client_link.
 */
class CreateStaffProfileTables extends Migration
{
    public function up()
    {
        $mysql = $this->db->DBDriver === 'MySQLi';
        // SQLite (the test database) has no ON UPDATE; there the model has to be relied on instead
        $onUpdate = $mysql ? ' ON UPDATE CURRENT_TIMESTAMP' : '';
        $fkName   = static fn (string $name): string => $mysql ? $name : ''; // nor named foreign keys
        $publicId = static fn (): array => $mysql
            ? ['public_id CHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL']
            : ['public_id' => ['type' => 'CHAR', 'constraint' => 12]];
        $timestamps = [
            // maintained by the database, not the model
            'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP' . $onUpdate,
        ];

        $this->forge->addField([
            'id'            => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'business_id'   => ['type' => 'INT', 'unsigned' => true],
            'client_id'     => ['type' => 'INT', 'unsigned' => true],
            'language_code' => ['type' => 'VARCHAR', 'constraint' => 16], // BCP 47: ja, yue, nan-TW
            'proficiency'   => ['type' => 'ENUM', 'constraint' => ['N', 'F', 'C', 'B'], 'default' => 'F'], // native, fluent, conversational, basic
        ]);
        $this->forge->addField($timestamps);
        $this->forge->addPrimaryKey('id');
        // one row per language per person; it also serves the person's foreign key
        $this->forge->addUniqueKey(['business_id', 'client_id', 'language_code']);
        $this->forge->addKey(['business_id', 'language_code']); // "who in this business speaks Japanese"
        $this->forge->addForeignKey(['business_id', 'client_id'], 'business_client_link', ['business_id', 'client_id'], 'CASCADE', 'CASCADE', $fkName('fk_business_client_language_client'));
        $this->forge->createTable('business_client_language', true); // no ENGINE option so the SQLite test database can run this too

        $this->forge->addField(['id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true]]);
        $this->forge->addField($publicId());
        $this->forge->addField([
            'business_id'    => ['type' => 'INT', 'unsigned' => true],
            'attribute_name' => ['type' => 'VARCHAR', 'constraint' => 64],
            'is_public'      => ['type' => 'TINYINT', 'constraint' => 1, 'unsigned' => true, 'default' => 1],
            'sort_order'     => ['type' => 'SMALLINT', 'unsigned' => true, 'default' => 0],
        ]);
        $this->forge->addField($timestamps);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('public_id', 'uq_business_client_attribute_public_id'); // named after the column, see PublicIdTrait
        $this->forge->addUniqueKey(['business_id', 'attribute_name']);
        $this->forge->addUniqueKey(['business_id', 'id']); // target of business_client_attribute_value's foreign key
        $this->forge->addForeignKey('business_id', 'business_master', 'id', 'CASCADE', 'RESTRICT', $fkName('fk_business_client_attribute_business'));
        $this->forge->createTable('business_client_attribute', true);

        $this->forge->addField([
            'id'             => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'attribute_id'   => ['type' => 'INT', 'unsigned' => true],
            'language_code'  => ['type' => 'VARCHAR', 'constraint' => 16], // BCP 47: en-US, th
            'attribute_name' => ['type' => 'VARCHAR', 'constraint' => 64],
        ]);
        $this->forge->addField($timestamps);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['attribute_id', 'language_code']);
        $this->forge->addForeignKey('attribute_id', 'business_client_attribute', 'id', 'CASCADE', 'CASCADE', $fkName('fk_business_client_attribute_translation_attribute'));
        $this->forge->createTable('business_client_attribute_translation', true);

        $this->forge->addField(['id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true]]);
        $this->forge->addField($publicId());
        $this->forge->addField([
            'business_id'     => ['type' => 'INT', 'unsigned' => true],
            'client_id'       => ['type' => 'INT', 'unsigned' => true],
            'attribute_id'    => ['type' => 'INT', 'unsigned' => true],
            'attribute_value' => ['type' => 'VARCHAR', 'constraint' => 255],
            'sort_order'      => ['type' => 'SMALLINT', 'unsigned' => true, 'default' => 0],
        ]);
        $this->forge->addField($timestamps);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('public_id', 'uq_business_client_attribute_value_public_id');
        // no value twice for the same attribute of a person; it also serves the person's foreign key
        $this->forge->addUniqueKey(['business_id', 'client_id', 'attribute_id', 'attribute_value']);
        $this->forge->addKey(['business_id', 'attribute_id']); // serves the attribute's foreign key and "who has this attribute"
        $this->forge->addForeignKey(['business_id', 'client_id'], 'business_client_link', ['business_id', 'client_id'], 'CASCADE', 'CASCADE', $fkName('fk_business_client_attribute_value_client'));
        $this->forge->addForeignKey(['business_id', 'attribute_id'], 'business_client_attribute', ['business_id', 'id'], 'CASCADE', 'RESTRICT', $fkName('fk_business_client_attribute_value_attribute'));
        $this->forge->createTable('business_client_attribute_value', true);

        $this->forge->addField([
            'id'              => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'value_id'        => ['type' => 'INT', 'unsigned' => true],
            'language_code'   => ['type' => 'VARCHAR', 'constraint' => 16],
            'attribute_value' => ['type' => 'VARCHAR', 'constraint' => 255],
        ]);
        $this->forge->addField($timestamps);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['value_id', 'language_code']);
        $this->forge->addForeignKey('value_id', 'business_client_attribute_value', 'id', 'CASCADE', 'CASCADE', $fkName('fk_business_client_attribute_value_translation_value'));
        $this->forge->createTable('business_client_attribute_value_translation', true);

        $this->forge->addField([
            'id'               => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'business_id'      => ['type' => 'INT', 'unsigned' => true],
            'client_id'        => ['type' => 'INT', 'unsigned' => true],
            'restriction_type' => ['type' => 'ENUM', 'constraint' => ['G', 'A']], // gender not served, age range served
            'excluded_gender'  => ['type' => 'ENUM', 'constraint' => ['M', 'F', 'NB'], 'null' => true],
            'age_min'          => ['type' => 'TINYINT', 'unsigned' => true, 'null' => true],
            'age_max'          => ['type' => 'TINYINT', 'unsigned' => true, 'null' => true],
        ]);
        $this->forge->addField($timestamps);
        $this->forge->addPrimaryKey('id');
        // one row per gender per person; it also serves the person's foreign key
        $this->forge->addUniqueKey(['business_id', 'client_id', 'restriction_type', 'excluded_gender']);
        $this->forge->addForeignKey(['business_id', 'client_id'], 'business_client_link', ['business_id', 'client_id'], 'CASCADE', 'CASCADE', $fkName('fk_business_client_restriction_client'));
        $this->forge->createTable('business_client_restriction', true);
    }

    public function down()
    {
        $this->forge->dropTable('business_client_restriction', true);
        $this->forge->dropTable('business_client_attribute_value_translation', true);
        $this->forge->dropTable('business_client_attribute_value', true);
        $this->forge->dropTable('business_client_attribute_translation', true);
        $this->forge->dropTable('business_client_attribute', true);
        $this->forge->dropTable('business_client_language', true);
    }
}
