<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * What the business's public pages on the marketplace (otternova_mart) may show besides its catalog:
 * - business_store.google_maps_url: the store's place on Google Maps (https://maps.app.goo.gl/..., or a
 *   https://www.google.com/maps/... link), shown on the business's contact page; NULL for none, where the page
 *   searches Google Maps for the address instead. Only a physical store has one.
 * - business_client_link.show_profile: Y when the person's profile (display name, picture, bio, the languages they
 *   speak, the public attributes and the customers they do not serve) is shown on the business's staff page; N, the
 *   default, keeps them off it, as most people of a business (its owners, managers, those behind the scenes) do not
 *   serve the customers. It is per business, as one person may work for several.
 * Depends on business_store and business_client_link.
 */
class AddPublicProfiles extends Migration
{
    public function up()
    {
        $this->forge->addColumn('business_store', [
            'google_maps_url' => ['type' => 'VARCHAR', 'constraint' => 512, 'null' => true, 'after' => 'store_postal_code'],
        ]);
        $this->forge->addColumn('business_client_link', [
            'show_profile' => ['type' => 'ENUM', 'constraint' => ['Y', 'N'], 'default' => 'N', 'null' => false, 'after' => 'client_role'],
        ]);
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
            $this->forge->dropColumn('business_client_link', 'show_profile');
            $this->forge->dropColumn('business_store', 'google_maps_url');
        } finally {
            if ($sqlite) {
                $this->db->query('PRAGMA legacy_alter_table = OFF');
            }
        }
    }
}
