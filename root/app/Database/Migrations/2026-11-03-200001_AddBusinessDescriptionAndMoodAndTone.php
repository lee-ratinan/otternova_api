<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Adds business_description and business_mood_and_tone to business_master: what the business does, and the voice
 * it speaks in to its customers. Both optional.
 * Depends on business_master.
 */
class AddBusinessDescriptionAndMoodAndTone extends Migration
{
    public function up()
    {
        $this->forge->addColumn('business_master', [
            'business_description'   => ['type' => 'TEXT', 'null' => true, 'after' => 'business_slug'],
            'business_mood_and_tone' => ['type' => 'TEXT', 'null' => true, 'after' => 'business_description'],
        ]);
    }

    public function down()
    {
        // SQLite (the test database) drops columns by rebuilding the table; the legacy rename keeps the other
        // tables' foreign keys pointing at business_master (see MoveAvatarAndTrimProfileLocale)
        $sqlite = $this->db->DBDriver === 'SQLite3';
        if ($sqlite) {
            $this->db->query('PRAGMA legacy_alter_table = ON');
        }
        try {
            $this->forge->dropColumn('business_master', ['business_description', 'business_mood_and_tone']);
        } finally {
            if ($sqlite) {
                $this->db->query('PRAGMA legacy_alter_table = OFF');
            }
        }
    }
}
