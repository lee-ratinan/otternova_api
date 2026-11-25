<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Adds the first versions of the Terms of Service and the Privacy Policy for the Client app (audience B): the
 * texts of 1 December 2025 that otternova_www shows, copied into app/Database/Seeds/Legal/2025-12-01 as
 * {document_type}-{locale}.md. No account has accepted them, so every client is asked to on their next sign-in.
 * Depends on legal_document_version and legal_document_text.
 */
class SeedLegalDocumentVersions extends Migration
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
            if ($versions->where('audience', 'B')->where('document_type', $type)->where('version_label', self::LABEL)->countAllResults() > 0) {
                continue;
            }
            $this->db->transStart();
            $this->db->table('legal_document_version')->insert([
                'public_id'             => generateNanoId(8),
                'audience'              => 'B',
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
