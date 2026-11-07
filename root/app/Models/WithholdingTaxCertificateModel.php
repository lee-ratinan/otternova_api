<?php

namespace App\Models;

use App\Libraries\Money;
use CodeIgniter\Model;

/**
 * Model for the withholding_tax_certificate table: a certificate (50 ทวิ) a business uploads for the tax withheld from
 * an invoice. Its writes go through App\Libraries\SubscriptionBilling.
 */
class WithholdingTaxCertificateModel extends Model
{
    use PublicIdTrait;

    /** cert_status */
    public const STATUS_PENDING  = 'P';
    public const STATUS_ACCEPTED = 'A';
    public const STATUS_REJECTED = 'R';

    protected $table            = 'withholding_tax_certificate';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [];

    // created_at and updated_at are maintained by the database
    protected $useTimestamps = false;

    /** public_id: CHAR(12), see PublicIdTrait */
    protected int $publicIdLength = 12;
    protected $beforeInsert       = ['fillPublicId'];

    /**
     * The certificates of the invoices as the API sends them, oldest first
     * @param list<int> $invoiceIds
     * @param bool      $forStaff   Adds who reviewed each one
     * @return array<int, list<array>> invoice id => certificates
     */
    public function payloadsOfInvoices(array $invoiceIds, bool $forStaff): array
    {
        if ($invoiceIds === []) {
            return [];
        }
        $rows = $this->joinedBuilder($forStaff)->select('c.invoice_id AS _invoice_id')->whereIn('c.invoice_id', $invoiceIds)->orderBy('c.id', 'ASC')->get()->getResultArray();

        $byInvoice = [];
        foreach ($rows as $row) {
            $invoiceId = (int) $row['_invoice_id'];
            unset($row['_invoice_id']);
            $byInvoice[$invoiceId][] = self::toPayload($row);
        }

        return $byInvoice;
    }

    /**
     * One page of certificates for the staff, oldest first, so that the ones waiting longest come up first
     * @param int                   $start
     * @param int                   $length
     * @param array<string, string> $filters cert_status
     * @return array{records_total: int, records_filtered: int, rows: list<array>}
     */
    public function listCertificates(int $start, int $length, array $filters): array
    {
        $total   = $this->db->table($this->table)->countAllResults();
        $builder = $this->joinedBuilder(true)
            ->select('i.public_id AS invoice_public_id, i.invoice_no, i.amount_due AS invoice_amount_due, i.wht_rate AS invoice_wht_rate')
            ->select('i.wht_amount AS invoice_wht_amount, i.wht_cert_status, i.wht_cert_due_at, b.business_slug, b.business_name, b.legal_name')
            ->join('subscription_invoice i', 'i.id = c.invoice_id', 'inner')
            ->join('subscription_master s', 's.id = i.subscription_id', 'inner')
            ->join('business_master b', 'b.id = s.business_id', 'inner');
        if (isset($filters['cert_status'])) {
            $builder->where('c.cert_status', $filters['cert_status']);
        }
        $filtered = $builder->countAllResults(false);
        $rows     = $builder->orderBy('c.id', 'ASC')->limit($length, $start)->get()->getResultArray();
        foreach ($rows as &$row) {
            foreach (['invoice_amount_due', 'invoice_wht_rate', 'invoice_wht_amount'] as $field) {
                $row[$field] = Money::format($row[$field]);
            }
            $row = self::toPayload($row);
        }

        return ['records_total' => $total, 'records_filtered' => $filtered, 'rows' => $rows];
    }

    /**
     * @param bool $forStaff
     * @return \CodeIgniter\Database\BaseBuilder
     */
    private function joinedBuilder(bool $forStaff)
    {
        $builder = $this->db->table($this->table . ' c')
            ->select('c.public_id, c.certificate_no, c.issued_on, c.base_amount, c.wht_amount, c.file_path, c.cert_status, c.rejection_reason')
            ->select('c.reviewed_at, c.created_at');
        if ($forStaff) {
            $builder->select('r.full_name AS reviewed_by_name')->join('auth_master r', 'r.id = c.reviewed_by', 'left');
        }

        return $builder;
    }

    /**
     * The file's name stays inside; has_file says whether there is one
     * @param array $row
     * @return array
     */
    private static function toPayload(array $row): array
    {
        $row['has_file']    = $row['file_path'] !== null && $row['file_path'] !== '';
        $row['base_amount'] = Money::format($row['base_amount']);
        $row['wht_amount']  = Money::format($row['wht_amount']);
        unset($row['file_path']);

        return $row;
    }
}
