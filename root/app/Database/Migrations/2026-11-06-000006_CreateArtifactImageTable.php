<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Creates the artifact_image table: the pictures of an artifact, 1000x1000 WebP files kept under the artifact-image
 * folder of Config\ImageUpload::$uploadPath; image_path is the URL base_url('image/artifact-image/{name}.webp').
 * variant_id NULL is a picture of the whole artifact; otherwise of that variant only (the blue one), which the
 * foreign key on (artifact_id, variant_id) keeps within the artifact. sort_order 0 is the cover.
 * Unlike the rest of the artifact tables, a row here is deleted, together with its file, when the image is removed.
 * public_id is a NanoID (see PublicIdTrait); on MySQL it is ascii_bin, as NanoIDs tell upper from lower case.
 * Depends on artifact_master and artifact_variant.
 */
class CreateArtifactImageTable extends Migration
{
    public function up()
    {
        $mysql = $this->db->DBDriver === 'MySQLi';
        // SQLite (the test database) has no ON UPDATE; there the model has to be relied on instead
        $onUpdate = $mysql ? ' ON UPDATE CURRENT_TIMESTAMP' : '';
        $fkName   = static fn (string $name): string => $mysql ? $name : ''; // nor named foreign keys

        $this->forge->addField(['id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true]]);
        $this->forge->addField($mysql
            ? ['public_id CHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL']
            : ['public_id' => ['type' => 'CHAR', 'constraint' => 12]]);
        $this->forge->addField([
            'artifact_id' => ['type' => 'INT', 'unsigned' => true],
            'variant_id'  => ['type' => 'INT', 'unsigned' => true, 'null' => true], // NULL = the whole artifact
            'image_path'  => ['type' => 'VARCHAR', 'constraint' => 255],
            'sort_order'  => ['type' => 'SMALLINT', 'unsigned' => true, 'default' => 0], // 0 = the cover
            // maintained by the database, not the model
            'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP' . $onUpdate,
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('public_id', 'uq_artifact_image_public_id'); // named after the column, see PublicIdTrait
        $this->forge->addKey(['artifact_id', 'sort_order']);
        $this->forge->addKey(['artifact_id', 'variant_id']);
        $this->forge->addForeignKey('artifact_id', 'artifact_master', 'id', 'CASCADE', 'RESTRICT', $fkName('fk_artifact_image_artifact'));
        // not checked while variant_id is NULL
        $this->forge->addForeignKey(['artifact_id', 'variant_id'], 'artifact_variant', ['artifact_id', 'id'], 'CASCADE', 'RESTRICT', $fkName('fk_artifact_image_variant'));
        $this->forge->createTable('artifact_image', true); // InnoDB is MySQL's default engine; no ENGINE option so the SQLite test database can run this too
    }

    public function down()
    {
        $this->forge->dropTable('artifact_image', true);
    }
}
