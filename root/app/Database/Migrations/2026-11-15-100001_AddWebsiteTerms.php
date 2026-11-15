<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * What a business calls its people on its pages on the marketplace (otternova_mart), in each of its website languages
 * (business_website_language), as written in the middle of a sentence:
 * - staff_term_one, staff_term_other: its staff, one and many ("therapist", "therapists" at a spa; "teacher",
 *   "teachers" at a school)
 * - participant_term_one, participant_term_other: the people a booking is for ("student", "students"; "client",
 *   "clients")
 * NULL is the storefront's own word in that language ("staff member", "staff"; "participant", "participants").
 * Depends on business_website_language.
 */
class AddWebsiteTerms extends Migration
{
    public const COLUMNS = ['staff_term_one', 'staff_term_other', 'participant_term_one', 'participant_term_other'];

    public function up()
    {
        $fields = [];
        $after  = 'hero_paragraph';
        foreach (self::COLUMNS as $column) {
            $fields[$column] = ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true, 'after' => $after];
            $after           = $column;
        }
        $this->forge->addColumn('business_website_language', $fields);
    }

    public function down()
    {
        // SQLite (the test database) drops columns by rebuilding the table; the legacy rename keeps the other
        // tables' foreign keys pointing at it (see AddStoreAddressTranslations)
        $sqlite = $this->db->DBDriver === 'SQLite3';
        if ($sqlite) {
            $this->db->query('PRAGMA legacy_alter_table = ON');
        }
        try {
            $this->forge->dropColumn('business_website_language', self::COLUMNS);
        } finally {
            if ($sqlite) {
                $this->db->query('PRAGMA legacy_alter_table = OFF');
            }
        }
    }
}
