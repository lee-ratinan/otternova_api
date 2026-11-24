<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for the email_log table: one row per email App\Libraries\EmailService sends, written through record().
 * See the CreateEmailLogTable migration for the columns.
 */
class EmailLogModel extends Model
{
    public const STATUS_SENT       = 'S';
    public const STATUS_FAILED     = 'F';
    public const STATUS_SUPPRESSED = 'X';

    protected $table            = 'email_log';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'email_type',
        'recipient',
        'delivered_to',
        'locale',
        'subject',
        'status',
        'error_message',
    ];

    // created_at is maintained by the database; there is no updated_at
    protected $useTimestamps = false;

    protected $validationRules = [
        'email_type'    => 'required|max_length[64]',
        'recipient'     => 'required|max_length[254]',
        'delivered_to'  => 'permit_empty|max_length[254]',
        'locale'        => 'permit_empty|max_length[16]',
        'subject'       => 'required|max_length[255]',
        'status'        => 'required|in_list[S,F,X]',
        'error_message' => 'permit_empty|max_length[500]',
    ];
    protected $skipValidation       = false;
    protected $cleanValidationRules = true;

    /**
     * Log one email. Over-long text is cut to fit its column rather than losing the row.
     * @param string      $type        EmailService::TYPE_*
     * @param string      $recipient   The address it was for
     * @param string|null $deliveredTo Where it really went; null when it was not handed to the mail server
     * @param string|null $locale      The language it was written in; null for the request's locale
     * @param string      $subject
     * @param string      $status      One of the STATUS_* constants
     * @param string|null $error       Why it failed
     * @return int The new row's id
     */
    public function record(string $type, string $recipient, ?string $deliveredTo, ?string $locale, string $subject,
        string $status, ?string $error = null): int
    {
        $error = $error === null ? null : trim(preg_replace('/\s+/', ' ', strip_tags($error)));
        $row   = [
            'email_type'    => $type,
            'recipient'     => mb_substr($recipient, 0, 254),
            'delivered_to'  => $deliveredTo === null ? null : mb_substr($deliveredTo, 0, 254),
            'locale'        => $locale,
            'subject'       => mb_substr($subject, 0, 255),
            'status'        => $status,
            'error_message' => $error === null || $error === '' ? null : mb_substr($error, 0, 500),
        ];
        if ($this->insert($row) === false) {
            throw new \RuntimeException('email_log not written: ' . implode('; ', $this->errors()));
        }

        return (int) $this->getInsertID();
    }

    /**
     * One page of the log for the Admin app, newest first
     * @param int         $start
     * @param int         $length
     * @param string|null $type   Only this email_type
     * @param string|null $status Only this status (STATUS_*)
     * @param string      $search Matched within the recipient, where it went and the subject
     * @return array{records_total: int, records_filtered: int, rows: list<array>}
     */
    public function listForStaff(int $start, int $length, ?string $type, ?string $status, string $search): array
    {
        $builder = $this->db->table($this->table)
            ->select('id, email_type, recipient, delivered_to, locale, subject, status, error_message, created_at');
        if ($type !== null) {
            $builder->where('email_type', $type);
        }
        if ($status !== null) {
            $builder->where('status', $status);
        }
        $total = $builder->countAllResults(false);
        if ($search !== '') {
            $char = $this->db->likeEscapeChar;
            $term = str_replace([$char, '%', '_'], [$char . $char, $char . '%', $char . '_'], $search);
            $builder->groupStart()
                ->like('recipient', $term, 'both', null, true)
                ->orLike('delivered_to', $term, 'both', null, true)
                ->orLike('subject', $term, 'both', null, true)
                ->groupEnd();
        }
        $filtered = $search === '' ? $total : $builder->countAllResults(false);
        $rows     = $builder->orderBy('id', 'DESC')->limit($length, $start)->get()->getResultArray();

        return ['records_total' => $total, 'records_filtered' => $filtered, 'rows' => array_map(static fn (array $row): array => [
            'id'            => (int) $row['id'],
            'email_type'    => $row['email_type'],
            'recipient'     => $row['recipient'],
            'delivered_to'  => $row['delivered_to'],
            'locale'        => $row['locale'],
            'subject'       => $row['subject'],
            'status'        => $row['status'],
            'error_message' => $row['error_message'],
            'created_at'    => $row['created_at'],
        ], $rows)];
    }

    /**
     * Every email_type the log has rows of, by name
     * @return list<string>
     */
    public function loggedTypes(): array
    {
        $rows = $this->db->table($this->table)->distinct()->select('email_type')->orderBy('email_type', 'ASC')->get()->getResultArray();

        return array_column($rows, 'email_type');
    }
}
