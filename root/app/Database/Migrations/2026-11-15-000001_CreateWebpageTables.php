<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The web pages a business designs itself, on a plan whose Config\PlanLimits::WEBPAGES is above 0, from the templates
 * of its sections; the colours and typefaces are the business_website's and business_website_language's already.
 * Only owners and managers edit them.
 *
 * Creates webpage_master: one row per page, at most PlanLimits::WEBPAGES of the business's plan, the home page included.
 * - page_slug VARCHAR(64): unique per business. 'home' is the home page and is reserved for it, so the unique key keeps
 *   a business at one home page; the model creates it first, before any other page, and never deletes it. The home
 *   page is /{business_slug}, every other page /{business_slug}/pages/{page_slug}. The slug is made from the page's
 *   name in the default language when the page is created and is not changed by renaming it; the owner may change it,
 *   after a warning that links to the old one stop working.
 * - page_status ENUM('D', 'P') DEFAULT 'D': D, a draft, is never shown: the home page's place shows the system home
 *   (/{business_slug}/home) instead, any other page is a 404. P is live; edits to a live page are live at once.
 * - sort_order TINYINT DEFAULT 0: the order of the menu; the model keeps the home page first
 * Pages are deleted for real, with their sections, texts and images (and the images' files).
 *
 * Creates webpage_master_translation: a page's name, and meta tags, per language of business_website_language.
 * - page_name VARCHAR(64): unique per business and language (on MySQL, with the _ci collation, regardless of case)
 * - meta_title VARCHAR(70), meta_description VARCHAR(160): optional
 * The home page has no rows: its name is the language file's ("Home", "หน้าแรก", "ホーム") and its meta tags are
 * business_website_language's; the model refuses that name for any other page. A page with no row in a language
 * shows the default language's.
 *
 * Creates webpage_section: the sections of a page, top to bottom by sort_order, unique per page.
 * - section_type VARCHAR(16): 'hero' for now, always at sort_order 0 and once per page, which the model checks
 * - design_code VARCHAR(32): which design of the section type, a key of the designs' config, which also says what
 *   texts and images each design takes
 *
 * Creates webpage_section_text: the texts a section's design asks for, one row per language and slot_code ('badge',
 * 'headline', 'paragraph', 'button_label'), at most the design's length. Changing the design keeps the texts whose
 * slot_code the new one has too. A hero's button always leads to the system home, so no link is kept.
 *
 * Creates webpage_image: the pictures of a section, WebP files kept under the webpage-image folder of
 * Config\ImageUpload::$uploadPath; image_path is the URL base_url('image/webpage-image/{name}.webp'). slot_code is
 * the design's place for it ('background', 'slide'), sort_order the order within that place. webpage_id is kept so
 * PlanLimits::IMAGE_PER_WEBPAGE, a cap on the whole page, is one count; the foreign key on (webpage_id, section_id)
 * keeps it the section's page. Like artifact_image, a row is deleted together with its file.
 *
 * public_id is a NanoID (see PublicIdTrait); on MySQL it is ascii_bin, as NanoIDs tell upper from lower case.
 * Depends on business_website.
 */
class CreateWebpageTables extends Migration
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

        $this->forge->addField(['id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true]]);
        $this->forge->addField($publicId());
        $this->forge->addField([
            'business_id' => ['type' => 'INT', 'unsigned' => true],
            'page_slug'   => ['type' => 'VARCHAR', 'constraint' => 64], // 'home' is the home page
            'page_status' => ['type' => 'ENUM', 'constraint' => ['D', 'P'], 'default' => 'D'], // draft, published
            'sort_order'  => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 0], // the menu; home first
        ] + $timestamps);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('public_id', 'uq_webpage_master_public_id'); // named after the column, see PublicIdTrait
        $this->forge->addUniqueKey(['business_id', 'page_slug']); // one home page; also finding a page by its URL
        $this->forge->addUniqueKey(['business_id', 'id']); // target of webpage_master_translation's foreign key
        $this->forge->addKey(['business_id', 'sort_order']);
        $this->forge->addForeignKey('business_id', 'business_website', 'business_id', 'CASCADE', 'CASCADE', $fkName('fk_webpage_master_website'));
        $this->forge->createTable('webpage_master', true); // InnoDB is MySQL's default engine; no ENGINE option so the SQLite test database can run this too

        $this->forge->addField([
            'id'               => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'business_id'      => ['type' => 'INT', 'unsigned' => true], // the page's, for the unique name
            'webpage_id'       => ['type' => 'INT', 'unsigned' => true],
            'language_code'    => ['type' => 'VARCHAR', 'constraint' => 16], // BCP 47, a key of listLanguages()
            'page_name'        => ['type' => 'VARCHAR', 'constraint' => 64],
            'meta_title'       => ['type' => 'VARCHAR', 'constraint' => 70, 'null' => true],
            'meta_description' => ['type' => 'VARCHAR', 'constraint' => 160, 'null' => true],
        ] + $timestamps);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['webpage_id', 'language_code']);
        $this->forge->addUniqueKey(['business_id', 'language_code', 'page_name']); // a name once per business
        $this->forge->addKey(['business_id', 'webpage_id']); // serves the foreign key
        $this->forge->addForeignKey(['business_id', 'webpage_id'], 'webpage_master', ['business_id', 'id'], 'CASCADE', 'CASCADE', $fkName('fk_webpage_master_translation_page'));
        $this->forge->createTable('webpage_master_translation', true);

        $this->forge->addField(['id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true]]);
        $this->forge->addField($publicId());
        $this->forge->addField([
            'webpage_id'   => ['type' => 'INT', 'unsigned' => true],
            'section_type' => ['type' => 'VARCHAR', 'constraint' => 16], // 'hero'
            'design_code'  => ['type' => 'VARCHAR', 'constraint' => 32], // a design of the section type
            'sort_order'   => ['type' => 'SMALLINT', 'unsigned' => true, 'default' => 0], // the hero is 0
        ] + $timestamps);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('public_id', 'uq_webpage_section_public_id');
        $this->forge->addUniqueKey(['webpage_id', 'sort_order']); // also the page's sections in order
        $this->forge->addUniqueKey(['webpage_id', 'id']); // target of webpage_image's foreign key
        $this->forge->addForeignKey('webpage_id', 'webpage_master', 'id', 'CASCADE', 'CASCADE', $fkName('fk_webpage_section_page'));
        $this->forge->createTable('webpage_section', true);

        $this->forge->addField([
            'id'            => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'section_id'    => ['type' => 'INT', 'unsigned' => true],
            'language_code' => ['type' => 'VARCHAR', 'constraint' => 16], // BCP 47, a key of listLanguages()
            'slot_code'     => ['type' => 'VARCHAR', 'constraint' => 32], // 'headline'
            'slot_text'     => ['type' => 'VARCHAR', 'constraint' => 1000], // at most the design's length for the slot
        ] + $timestamps);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['section_id', 'language_code', 'slot_code']);
        $this->forge->addForeignKey('section_id', 'webpage_section', 'id', 'CASCADE', 'CASCADE', $fkName('fk_webpage_section_text_section'));
        $this->forge->createTable('webpage_section_text', true);

        $this->forge->addField(['id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true]]);
        $this->forge->addField($publicId());
        $this->forge->addField([
            'webpage_id' => ['type' => 'INT', 'unsigned' => true], // the section's, for the plan's cap per page
            'section_id' => ['type' => 'INT', 'unsigned' => true],
            'slot_code'  => ['type' => 'VARCHAR', 'constraint' => 32], // 'background', 'slide'
            'image_path' => ['type' => 'VARCHAR', 'constraint' => 255],
            'sort_order' => ['type' => 'SMALLINT', 'unsigned' => true, 'default' => 0], // within the slot
        ] + $timestamps);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('public_id', 'uq_webpage_image_public_id');
        $this->forge->addKey(['webpage_id', 'section_id', 'slot_code', 'sort_order']); // serves the foreign key and the count
        $this->forge->addForeignKey(['webpage_id', 'section_id'], 'webpage_section', ['webpage_id', 'id'], 'CASCADE', 'CASCADE', $fkName('fk_webpage_image_section'));
        $this->forge->createTable('webpage_image', true);
    }

    public function down()
    {
        foreach (['webpage_image', 'webpage_section_text', 'webpage_section', 'webpage_master_translation', 'webpage_master'] as $table) {
            $this->forge->dropTable($table, true);
        }
    }
}
