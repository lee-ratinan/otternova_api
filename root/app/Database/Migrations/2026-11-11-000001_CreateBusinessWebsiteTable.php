<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Creates the business_website table: how a business's web pages look, in every language: the theme colours and the
 * logo. At most one per business; the language-specific settings are in business_website_language.
 * The colours are #rrggbb CSS colours, the --*-color variables of the storefront's theme; the defaults are the
 * storefront's own, so a business that never changes them looks as before.
 * logo_path is the URL base_url('image/business-logo/{name}.webp'); logo_shape is the shape the upload was resized to,
 * from the original's proportions: S square, 1000x1000, or W wide, 2000x1000. Both NULL when there is no logo.
 * Depends on business_master.
 */
class CreateBusinessWebsiteTable extends Migration
{
    public function up()
    {
        $mysql = $this->db->DBDriver === 'MySQLi';
        // SQLite (the test database) has no ON UPDATE; there the model has to be relied on instead
        $onUpdate = $mysql ? ' ON UPDATE CURRENT_TIMESTAMP' : '';
        $fkName   = static fn (string $name): string => $mysql ? $name : ''; // nor named foreign keys
        $color    = static fn (string $default): array => ['type' => 'CHAR', 'constraint' => 7, 'default' => $default];

        $this->forge->addField([
            'id'               => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'business_id'      => ['type' => 'INT', 'unsigned' => true],
            'background_color' => $color('#ffffff'), // the whole page, sections included
            'default_color'    => $color('#5a6577'), // most of the text
            'heading_color'    => $color('#10243d'), // headings, subheadings and titles
            'accent_color'     => $color('#ff7533'), // the brand colour: buttons, links and what has to stand out
            'surface_color'    => $color('#ffffff'), // boxed elements within sections: cards, icon boxes
            'contrast_color'   => $color('#ffffff'), // text on the accent, heading or default colour
            'logo_path'        => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'logo_shape'       => ['type' => 'ENUM', 'constraint' => ['S', 'W'], 'null' => true], // square 1000x1000, wide 2000x1000
            // maintained by the database, not the model
            'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP' . $onUpdate,
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('business_id'); // one per business; also the target of the other business_website_* tables' foreign keys
        $this->forge->addForeignKey('business_id', 'business_master', 'id', 'CASCADE', 'CASCADE', $fkName('fk_business_website_business'));
        $this->forge->createTable('business_website', true); // InnoDB is MySQL's default engine; no ENGINE option so the SQLite test database can run this too
    }

    public function down()
    {
        $this->forge->dropTable('business_website', true);
    }
}
