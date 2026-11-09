<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The sessions of a group service that customers book (a Thai cooking lesson on 14 Nov 2026; a Japanese course of
 * ten weekly classes with Jiro Sensei), when they meet, and the people and resources each meeting holds. Customers
 * book a whole session, every slot of it; the bookings themselves come with the booking tables.
 * All the datetimes are in UTC, shown in the store's store_timezone.
 *
 * Creates service_session: a session of a service variant, at one physical store; a manager creates those of group
 * variants (booking_type G).
 * (business_id, store_id) must be a business_store and (business_id, artifact_id) an artifact_master of the business,
 * and (artifact_id, variant_id) a variant of that artifact. The model checks that the store is physical (store_type
 * P), the artifact a service and the variant a group one, as the database cannot. The store, artifact and variant are
 * fixed once created.
 * - session_name VARCHAR(255), session_description TEXT NULL: anything ("Japanese Basic Course 1 starting
 *   10 Nov 2026 with Jiro Sensei"); with service_session_translation, in other languages of listLanguages()
 * - capacity SMALLINT: the most customers the session takes; the variant's when created, and changed for one
 *   session if need be. No more than the resources of each needed type hold together, which the model checks.
 * - seats_booked SMALLINT DEFAULT 0: kept up to date by the booking model, never more than capacity
 * - starts_at, ends_at DATETIME NULL: the start of its first and the end of its last scheduled slot, kept by the
 *   model; NULL while it has none
 * - booking_closes_at DATETIME NULL: the last moment to book; NULL for until starts_at
 * - session_status ENUM('D', 'O', 'C', 'X') DEFAULT 'D': draft (not offered; its slots hold what they have so far,
 *   which need not be all they need yet), open for booking, closed (no new bookings; it still runs), cancelled (its
 *   holds are released, and it cannot change any more). A session whose ends_at has passed is over; there is no
 *   status for it. A session made for one customer's appointment (booking_type A) will be one of these too, with a
 *   single slot.
 *
 * Creates service_session_slot: when a session meets, one row per meeting (one for the cooking lesson, ten for the
 * course). business_id is the session's, repeated so the holds below stay in the session's business.
 * - slot_number SMALLINT: 1, 2, 3 ... unique per session, given in the order they meet when the session is created; a
 *   slot added later takes the next number, and a number never changes, so slots are listed by starts_at
 * - starts_at, ends_at DATETIME: ends_at after starts_at, and the slots of a session do not overlap (the model)
 * - slot_status ENUM('S', 'X') DEFAULT 'S': scheduled, cancelled (its holds are released). A slot moved to another
 *   time keeps its row, with new times.
 *
 * Creates service_slot_staff and service_slot_resource: whom and what a slot holds, so a substitute teacher or
 * another room for one week can be recorded. (business_id, slot_id) is a slot and (business_id, client_id) a
 * business_client_link, (business_id, resource_id) a resource_master of the same business. The slot's times are
 * copied in, and kept up to date by the model when the slot moves, so whether a person or a resource is free is one
 * range query on (client_id, starts_at, ends_at) or (resource_id, starts_at, held_until). held_until is the slot's
 * ends_at plus the resource type's buffer_minutes, the turnover after each use.
 * The model checks what the database cannot, in the transaction that writes the holds, having locked the person's
 * and the resource's rows (SELECT ... FOR UPDATE) so that two managers cannot both take the same time:
 * - a slot holds at least the variant's staff_required people, each allowed to perform the service
 *   (business_service_client_link) and active at the session's store (business_store_client_link)
 * - for each type in business_service_resource_link, quantity active resources of it at the session's store
 * - no person or resource has two holds that overlap
 * A hold is deleted when it is released (its slot or session cancelled, or another person or resource put in its
 * place); log_activity keeps the history.
 *
 * Adds the unique key (business_id, id) to resource_master, the target of service_slot_resource's foreign key.
 * Depends on business_master, business_store, business_client_link, artifact_master, artifact_variant and
 * resource_master.
 */
class CreateServiceSessionTables extends Migration
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

        // the target of service_slot_resource's foreign key; id alone is unique already, so this never rejects a row
        $this->forge->addUniqueKey(['business_id', 'id'], 'uq_resource_master_business_id_id');
        $this->forge->processIndexes('resource_master');

        $this->forge->addField(['id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true]]);
        $this->forge->addField($publicId());
        $this->forge->addField([
            'business_id'         => ['type' => 'INT', 'unsigned' => true],
            'store_id'            => ['type' => 'INT', 'unsigned' => true],
            'artifact_id'         => ['type' => 'INT', 'unsigned' => true],
            'variant_id'          => ['type' => 'INT', 'unsigned' => true],
            'session_name'        => ['type' => 'VARCHAR', 'constraint' => 255],
            'session_description' => ['type' => 'TEXT', 'null' => true],
            'capacity'            => ['type' => 'SMALLINT', 'unsigned' => true],
            'seats_booked'        => ['type' => 'SMALLINT', 'unsigned' => true, 'default' => 0],
            'starts_at'           => ['type' => 'DATETIME', 'null' => true], // UTC; the first scheduled slot's
            'ends_at'             => ['type' => 'DATETIME', 'null' => true], // UTC; the last scheduled slot's
            'booking_closes_at'   => ['type' => 'DATETIME', 'null' => true], // UTC; NULL for until starts_at
            'session_status'      => ['type' => 'ENUM', 'constraint' => ['D', 'O', 'C', 'X'], 'default' => 'D'], // draft, open, closed, cancelled
            ...$timestamps,
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('public_id', 'uq_service_session_public_id'); // named after the column, see PublicIdTrait
        $this->forge->addUniqueKey(['business_id', 'id']); // target of service_session_slot's foreign key
        // serves the store's foreign key and "the sessions of this store, by date"
        $this->forge->addKey(['business_id', 'store_id', 'starts_at']);
        // serves the artifact's foreign key and "the sessions of this service, by date"
        $this->forge->addKey(['business_id', 'artifact_id', 'starts_at']);
        $this->forge->addKey(['artifact_id', 'variant_id']); // serves the variant's foreign key
        $this->forge->addForeignKey(['business_id', 'store_id'], 'business_store', ['business_id', 'id'], 'CASCADE', 'RESTRICT', $fkName('fk_service_session_store'));
        $this->forge->addForeignKey(['business_id', 'artifact_id'], 'artifact_master', ['business_id', 'id'], 'CASCADE', 'RESTRICT', $fkName('fk_service_session_artifact'));
        $this->forge->addForeignKey(['artifact_id', 'variant_id'], 'artifact_variant', ['artifact_id', 'id'], 'CASCADE', 'RESTRICT', $fkName('fk_service_session_variant'));
        $this->forge->createTable('service_session', true); // no ENGINE option so the SQLite test database can run this too

        // the session's name and description in other languages of listLanguages(); optional, service_session's are shown where a language has none
        $this->forge->addField([
            'id'                  => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'session_id'          => ['type' => 'INT', 'unsigned' => true],
            'language_code'       => ['type' => 'VARCHAR', 'constraint' => 16], // BCP 47: en-US, th
            'session_name'        => ['type' => 'VARCHAR', 'constraint' => 255],
            'session_description' => ['type' => 'TEXT', 'null' => true],
            ...$timestamps,
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['session_id', 'language_code']);
        $this->forge->addForeignKey('session_id', 'service_session', 'id', 'CASCADE', 'CASCADE', $fkName('fk_service_session_translation_session'));
        $this->forge->createTable('service_session_translation', true);

        $this->forge->addField([
            'id'          => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'business_id' => ['type' => 'INT', 'unsigned' => true], // service_session.business_id
            'session_id'  => ['type' => 'INT', 'unsigned' => true],
            'slot_number' => ['type' => 'SMALLINT', 'unsigned' => true],
            'starts_at'   => ['type' => 'DATETIME'], // UTC
            'ends_at'     => ['type' => 'DATETIME'], // UTC
            'slot_status' => ['type' => 'ENUM', 'constraint' => ['S', 'X'], 'default' => 'S'], // scheduled, cancelled
            ...$timestamps,
        ]);
        $this->forge->addPrimaryKey('id');
        // one number per slot of a session; it also serves the session's foreign key and "the slots of this session"
        $this->forge->addUniqueKey(['business_id', 'session_id', 'slot_number']);
        $this->forge->addUniqueKey(['business_id', 'id']); // target of the holds' foreign keys
        $this->forge->addKey(['business_id', 'starts_at']); // "what is on today"
        $this->forge->addForeignKey(['business_id', 'session_id'], 'service_session', ['business_id', 'id'], 'CASCADE', 'RESTRICT', $fkName('fk_service_session_slot_session'));
        $this->forge->createTable('service_session_slot', true);

        $this->forge->addField([
            'id'          => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'business_id' => ['type' => 'INT', 'unsigned' => true],
            'slot_id'     => ['type' => 'INT', 'unsigned' => true],
            'client_id'   => ['type' => 'INT', 'unsigned' => true],
            'starts_at'   => ['type' => 'DATETIME'], // UTC; the slot's
            'ends_at'     => ['type' => 'DATETIME'], // UTC; the slot's
            ...$timestamps,
        ]);
        $this->forge->addPrimaryKey('id');
        // a person once per slot; it also serves the slot's foreign key and "who does this slot hold"
        $this->forge->addUniqueKey(['business_id', 'slot_id', 'client_id']);
        // serves the person's foreign key and "is this person free then"
        $this->forge->addKey(['business_id', 'client_id', 'starts_at', 'ends_at']);
        $this->forge->addForeignKey(['business_id', 'slot_id'], 'service_session_slot', ['business_id', 'id'], 'CASCADE', 'CASCADE', $fkName('fk_service_slot_staff_slot'));
        $this->forge->addForeignKey(['business_id', 'client_id'], 'business_client_link', ['business_id', 'client_id'], 'CASCADE', 'RESTRICT', $fkName('fk_service_slot_staff_client'));
        $this->forge->createTable('service_slot_staff', true);

        $this->forge->addField([
            'id'          => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'business_id' => ['type' => 'INT', 'unsigned' => true],
            'slot_id'     => ['type' => 'INT', 'unsigned' => true],
            'resource_id' => ['type' => 'INT', 'unsigned' => true],
            'starts_at'   => ['type' => 'DATETIME'], // UTC; the slot's
            'held_until'  => ['type' => 'DATETIME'], // UTC; the slot's ends_at plus the type's buffer_minutes
            ...$timestamps,
        ]);
        $this->forge->addPrimaryKey('id');
        // a resource once per slot; it also serves the slot's foreign key and "what does this slot hold"
        $this->forge->addUniqueKey(['business_id', 'slot_id', 'resource_id']);
        // serves the resource's foreign key and "is this resource free then"
        $this->forge->addKey(['business_id', 'resource_id', 'starts_at', 'held_until']);
        $this->forge->addForeignKey(['business_id', 'slot_id'], 'service_session_slot', ['business_id', 'id'], 'CASCADE', 'CASCADE', $fkName('fk_service_slot_resource_slot'));
        $this->forge->addForeignKey(['business_id', 'resource_id'], 'resource_master', ['business_id', 'id'], 'CASCADE', 'RESTRICT', $fkName('fk_service_slot_resource_resource'));
        $this->forge->createTable('service_slot_resource', true);
    }

    public function down()
    {
        $this->forge->dropTable('service_slot_resource', true);
        $this->forge->dropTable('service_slot_staff', true);
        $this->forge->dropTable('service_session_slot', true);
        $this->forge->dropTable('service_session_translation', true);
        $this->forge->dropTable('service_session', true);
        $this->forge->dropKey('resource_master', 'uq_resource_master_business_id_id', false);
    }
}
