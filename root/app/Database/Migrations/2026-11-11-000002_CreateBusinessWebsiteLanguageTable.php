<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Creates the business_website_language table: the languages of listLanguages() a business's web pages are in, one
 * row each, with what is set per language:
 * - heading_font, nav_font, default_font: typeface names of listSupportedTypefaces() for the language, the
 *   --heading-font, --nav-font and --default-font of the storefront's theme
 * - meta_title, meta_description, meta_keywords: the page's <title>/og:title, description/og:description and keywords;
 *   meta_title NULL is the business's name in that language
 * - hero_badge, hero_headline, hero_paragraph: the badge ("Curated Selection"), headline and paragraph at the top of
 *   the main page
 * Choosing the languages is adding and removing these rows. The lowest sort_order is the site's default language,
 * shown where the visitor's is not one of them, so changing the default needs no other column kept in step;
 * sort_order is also the order of the language switcher.
 * Depends on business_website.
 */
class CreateBusinessWebsiteLanguageTable extends Migration
{
    public function up()
    {
        $mysql = $this->db->DBDriver === 'MySQLi';
        // SQLite (the test database) has no ON UPDATE; there the model has to be relied on instead
        $onUpdate = $mysql ? ' ON UPDATE CURRENT_TIMESTAMP' : '';
        $fkName   = static fn (string $name): string => $mysql ? $name : ''; // nor named foreign keys

        $this->forge->addField([
            'id'               => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'business_id'      => ['type' => 'INT', 'unsigned' => true],
            'language_code'    => ['type' => 'VARCHAR', 'constraint' => 16], // BCP 47, a key of listLanguages()
            'sort_order'       => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 0], // the lowest is the default language
            'heading_font'     => ['type' => 'VARCHAR', 'constraint' => 64],
            'nav_font'         => ['type' => 'VARCHAR', 'constraint' => 64],
            'default_font'     => ['type' => 'VARCHAR', 'constraint' => 64],
            'meta_title'       => ['type' => 'VARCHAR', 'constraint' => 70, 'null' => true],
            'meta_description' => ['type' => 'VARCHAR', 'constraint' => 160, 'null' => true],
            'meta_keywords'    => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true], // comma-separated
            'hero_badge'       => ['type' => 'VARCHAR', 'constraint' => 48, 'null' => true],
            'hero_headline'    => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'hero_paragraph'   => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            // maintained by the database, not the model
            'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP' . $onUpdate,
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['business_id', 'language_code']);
        $this->forge->addKey(['business_id', 'sort_order']);
        $this->forge->addForeignKey('business_id', 'business_website', 'business_id', 'CASCADE', 'CASCADE', $fkName('fk_business_website_language_website'));
        $this->forge->createTable('business_website_language', true); // InnoDB is MySQL's default engine; no ENGINE option so the SQLite test database can run this too
    }

    public function down()
    {
        $this->forge->dropTable('business_website_language', true);
    }
}
