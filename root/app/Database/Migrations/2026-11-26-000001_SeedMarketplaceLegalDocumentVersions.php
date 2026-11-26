<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Adds the first versions of the marketplace's Terms of Service and Privacy Policy (audience M), which
 * otternova_mart's pages show: the same texts of 1 December 2025 as the Client app's first versions
 * (app/Database/Seeds/Legal/2025-12-01), as they cover the marketplace site too. Staff publish the marketplace's own
 * wording later as a newer version in the Admin app.
 * Depends on legal_document_version and legal_document_text.
 */
class SeedMarketplaceLegalDocumentVersions extends Migration
{
    private const LABEL = '2025-12-01';

    public function up()
    {
        // the test database starts empty; the tests create the rows they need
        if (ENVIRONMENT === 'testing') {
            return;
        }

        helper('id');
        $versions = $this->db->table('legal_document_version');
        foreach (['T', 'P'] as $type) {
            if ($versions->where('audience', 'M')->where('document_type', $type)->where('version_label', self::LABEL)->countAllResults() > 0) {
                continue;
            }
            $this->db->transStart();
            $this->db->table('legal_document_version')->insert([
                'public_id'             => generateNanoId(8),
                'audience'              => 'M',
                'document_type'         => $type,
                'version_label'         => self::LABEL,
                'effective_from'        => '2025-12-01 00:00:00',
                'requires_reacceptance' => 'Y',
            ]);
            $versionId = (int) $this->db->insertID();
            foreach (glob(APPPATH . 'Database/Seeds/Legal/' . self::LABEL . '/' . $type . '-*.md') as $file) {
                $body = (string) file_get_contents($file);
                $this->db->table('legal_document_text')->insert([
                    'version_id'  => $versionId,
                    'locale'      => substr(basename($file, '.md'), 2),
                    'body'        => $body,
                    'body_sha256' => hash('sha256', $body),
                ]);
            }
            $this->db->transComplete();
        }
    }

    public function down()
    {
        // nothing to do: rolling back further drops the tables
    }
}
