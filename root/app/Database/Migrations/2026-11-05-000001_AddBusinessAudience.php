<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Adds who a business is for to business_master, after business_mood_and_tone; the businesses already there get the
 * defaults, the general public:
 * - lgbt_audience ENUM('FNDLY', 'LGBT', 'GAY', 'LES', 'GEN') DEFAULT 'GEN': LGBT-friendly, LGBT only, gay only,
 *   lesbian only, general public
 * - naturist_audience ENUM('OPT', 'NUD', 'NAT', 'GEN') DEFAULT 'GEN': clothing-optional, nudity required in some
 *   areas (such as some spas), naturist only, general public
 * - age_restricted ENUM('Y', 'N') DEFAULT 'N': adults only, or not
 * Depends on business_master.
 */
class AddBusinessAudience extends Migration
{
    private const COLUMNS = ['lgbt_audience', 'naturist_audience', 'age_restricted'];

    public function up()
    {
        $this->forge->addColumn('business_master', [
            'lgbt_audience'     => ['type' => 'ENUM', 'constraint' => ['FNDLY', 'LGBT', 'GAY', 'LES', 'GEN'], 'default' => 'GEN', 'after' => 'business_mood_and_tone'],
            'naturist_audience' => ['type' => 'ENUM', 'constraint' => ['OPT', 'NUD', 'NAT', 'GEN'], 'default' => 'GEN', 'after' => 'lgbt_audience'],
            'age_restricted'    => ['type' => 'ENUM', 'constraint' => ['Y', 'N'], 'default' => 'N', 'after' => 'naturist_audience'],
        ]);
    }

    public function down()
    {
        $this->rebuildingTables(fn () => $this->forge->dropColumn('business_master', self::COLUMNS));
    }

    /**
     * SQLite (the test database) drops columns by rebuilding the table; the legacy rename keeps the other tables'
     * foreign keys pointing at it (see MoveAvatarAndTrimProfileLocale)
     * @param callable $work
     * @return void
     */
    private function rebuildingTables(callable $work): void
    {
        $sqlite = $this->db->DBDriver === 'SQLite3';
        if ($sqlite) {
            $this->db->query('PRAGMA legacy_alter_table = ON');
        }
        try {
            $work();
        } finally {
            if ($sqlite) {
                $this->db->query('PRAGMA legacy_alter_table = OFF');
            }
        }
    }
}
