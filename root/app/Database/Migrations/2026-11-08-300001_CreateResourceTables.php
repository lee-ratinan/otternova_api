<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The resources of a business's stores that a service needs besides a person: a spa's aromatherapy massage needs a
 * massage room, so booking one must hold a free room as well as a free therapist (the reservations themselves come
 * with the booking tables).
 *
 * Creates resource_type: a kind of resource ("Massage room", "Hot-stone kit"), defined once for the whole business.
 * - buffer_minutes SMALLINT DEFAULT 0: the turnover time after each use (cleaning the room), during which the
 *   resource cannot be booked again
 * - type_status ENUM('A', 'I') DEFAULT 'A': an inactive type cannot be newly linked to a service, and its resources
 *   are not offered, so a service needing it cannot be booked
 * With resource_type_translation, its name and description in other languages of listLanguages().
 *
 * Creates resource_master: the real resources, each at one store ("101", "102" and "103", the massage rooms of the
 * Bangkok store). (business_id, store_id) must be a business_store and (business_id, resource_type_id) a
 * resource_type of that business, so the store and the type are in the same business; both are fixed once created.
 * The model checks that the store is a physical one (store_type P), as the database cannot.
 * - resource_name VARCHAR(255): not translated; unique per store and type
 * - resource_status ENUM('A', 'I') DEFAULT 'A': an inactive resource (out of service) is never offered
 * - sort_order SMALLINT DEFAULT 0: which free resource is picked first
 *
 * Creates business_service_resource_link: what a service (artifact_master with artifact_type S, which the model
 * checks) needs, one row per resource type. It is on the artifact, not the variant, like business_service_client_link:
 * the 60- and 90-minute sessions both need a room. A service with no rows needs no resource.
 * - quantity TINYINT DEFAULT 1: how many of that type one booking holds (a couples massage: 2 beds)
 *
 * Nothing here is deleted but a link: types and resources are made inactive, so the bookings that held them keep
 * pointing at them; log_activity keeps the history of the links.
 * Depends on business_master, business_store and artifact_master.
 */
class CreateResourceTables extends Migration
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

        $this->forge->addField(['id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true]]);
        $this->forge->addField($publicId());
        $this->forge->addField([
            'business_id'      => ['type' => 'INT', 'unsigned' => true],
            'type_name'        => ['type' => 'VARCHAR', 'constraint' => 255],
            'type_description' => ['type' => 'TEXT', 'null' => true],
            'buffer_minutes'   => ['type' => 'SMALLINT', 'unsigned' => true, 'default' => 0], // turnover after each use
            'type_status'      => ['type' => 'ENUM', 'constraint' => ['A', 'I'], 'default' => 'A'], // active, inactive
            // maintained by the database, not the model
            'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP' . $onUpdate,
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('public_id', 'uq_resource_type_public_id'); // named after the column, see PublicIdTrait
        $this->forge->addUniqueKey(['business_id', 'type_name']); // one name per type within a business; also the listing
        $this->forge->addUniqueKey(['business_id', 'id']); // target of the composite foreign keys below
        $this->forge->addForeignKey('business_id', 'business_master', 'id', 'CASCADE', 'RESTRICT', $fkName('fk_resource_type_business'));
        $this->forge->createTable('resource_type', true); // no ENGINE option so the SQLite test database can run this too

        // the type's name and description in other languages of listLanguages(); optional, resource_type's are shown where a language has none
        $this->forge->addField([
            'id'               => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'resource_type_id' => ['type' => 'INT', 'unsigned' => true],
            'language_code'    => ['type' => 'VARCHAR', 'constraint' => 5], // BCP 47: en-US, th
            'type_name'        => ['type' => 'VARCHAR', 'constraint' => 255],
            'type_description' => ['type' => 'TEXT', 'null' => true],
            'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP' . $onUpdate,
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['resource_type_id', 'language_code']);
        $this->forge->addForeignKey('resource_type_id', 'resource_type', 'id', 'CASCADE', 'CASCADE', $fkName('fk_resource_type_translation_type'));
        $this->forge->createTable('resource_type_translation', true);

        $this->forge->addField(['id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true]]);
        $this->forge->addField($publicId());
        $this->forge->addField([
            'business_id'      => ['type' => 'INT', 'unsigned' => true],
            'store_id'         => ['type' => 'INT', 'unsigned' => true],
            'resource_type_id' => ['type' => 'INT', 'unsigned' => true],
            'resource_name'    => ['type' => 'VARCHAR', 'constraint' => 255],
            'resource_status'  => ['type' => 'ENUM', 'constraint' => ['A', 'I'], 'default' => 'A'], // active, inactive (out of service)
            'sort_order'       => ['type' => 'SMALLINT', 'unsigned' => true, 'default' => 0],
            'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP' . $onUpdate,
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('public_id', 'uq_resource_master_public_id');
        $this->forge->addUniqueKey(['store_id', 'resource_type_id', 'resource_name']); // one name per type within a store
        // serves the store's foreign key and "the active massage rooms of this store"
        $this->forge->addKey(['business_id', 'store_id', 'resource_type_id', 'resource_status']);
        $this->forge->addKey(['business_id', 'resource_type_id']); // serves the type's foreign key
        $this->forge->addForeignKey(['business_id', 'store_id'], 'business_store', ['business_id', 'id'], 'CASCADE', 'RESTRICT', $fkName('fk_resource_master_store'));
        $this->forge->addForeignKey(['business_id', 'resource_type_id'], 'resource_type', ['business_id', 'id'], 'CASCADE', 'RESTRICT', $fkName('fk_resource_master_type'));
        $this->forge->createTable('resource_master', true);

        $this->forge->addField([
            'id'               => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'business_id'      => ['type' => 'INT', 'unsigned' => true],
            'artifact_id'      => ['type' => 'INT', 'unsigned' => true],
            'resource_type_id' => ['type' => 'INT', 'unsigned' => true],
            'quantity'         => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 1],
            'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP' . $onUpdate,
        ]);
        $this->forge->addPrimaryKey('id');
        // one row per type per service; it also serves the service's foreign key and "what does this service need"
        $this->forge->addUniqueKey(['business_id', 'artifact_id', 'resource_type_id']);
        $this->forge->addKey(['business_id', 'resource_type_id']); // serves the type's foreign key and "which services need this type"
        $this->forge->addForeignKey(['business_id', 'artifact_id'], 'artifact_master', ['business_id', 'id'], 'CASCADE', 'RESTRICT', $fkName('fk_business_service_resource_link_artifact'));
        $this->forge->addForeignKey(['business_id', 'resource_type_id'], 'resource_type', ['business_id', 'id'], 'CASCADE', 'RESTRICT', $fkName('fk_business_service_resource_link_type'));
        $this->forge->createTable('business_service_resource_link', true);
    }

    public function down()
    {
        $this->forge->dropTable('business_service_resource_link', true);
        $this->forge->dropTable('resource_master', true);
        $this->forge->dropTable('resource_type_translation', true);
        $this->forge->dropTable('resource_type', true);
    }
}
