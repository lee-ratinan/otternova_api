<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Receipts of ledger entries (otternova_ledger_master.receipt_file_path), uploaded by /v1/staff/ledger/receipt-upload
 * and served by GET /v1/staff/ledger/receipt/{id} to the ledger's departments only; see App\Controllers\Ledger
 */
class LedgerReceipt extends BaseConfig
{
    /** Files are kept at {$uploadPath}{receipt_file_path}; never under public/ */
    public string $uploadPath = WRITEPATH . 'uploads/ledger-receipts/';

    /** Largest upload accepted, in bytes */
    public int $maxFileSize = 10 * 1024 * 1024;
}
