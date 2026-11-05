<?php

namespace App\Models;

use App\Libraries\ActivityLog;
use CodeIgniter\Model;
use Throwable;

/**
 * Model for the payment_method table: the ways an invoice can be paid, known by method_code everywhere
 */
class PaymentMethodModel extends Model
{
    use TransactionTrait;

    /** Columns /staff/plans/payment-method/create writes; .../edit writes them all but method_code */
    public const FORM_FIELDS   = ['method_code', 'country_code', 'currency_code', 'confirmation_mode', 'provider_code', 'is_active', 'sort_order'];
    public const FORM_NULLABLE = ['country_code', 'provider_code'];

    /** confirmation_mode */
    public const CONFIRMED_BY_PROVIDER = 'A';
    public const CONFIRMED_BY_STAFF    = 'M';

    protected $table            = 'payment_method';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = self::FORM_FIELDS;

    // created_at and updated_at are maintained by the database
    protected $useTimestamps = false;

    protected $validationRules = [
        'id'                => 'permit_empty|is_natural_no_zero',
        'method_code'       => 'required|regex_match[/^[a-z][a-z0-9_]{1,29}$/]|is_unique[payment_method.method_code,id,{id}]',
        'country_code'      => 'permit_empty|regex_match[/^[A-Z]{2}$/]',
        'currency_code'     => 'required|regex_match[/^[A-Z]{3}$/]',
        'confirmation_mode' => 'required|in_list[A,M]',
        'provider_code'     => 'permit_empty|regex_match[/^[a-z][a-z0-9_]{1,29}$/]',
        'is_active'         => 'required|in_list[Y,N]',
        'sort_order'        => 'required|is_natural|less_than_equal_to[255]',
    ];
    protected $skipValidation       = false;
    protected $cleanValidationRules = true;

    /**
     * @param mixed $code From the request
     * @return array|null
     */
    public function findByCode(mixed $code): ?array
    {
        if (! is_string($code) || $code === '') {
            return null;
        }

        return $this->db->table($this->table)->where('method_code', $code)->get()->getRowArray();
    }

    /**
     * Every method, in display order
     * @return list<array>
     */
    public function listMethods(): array
    {
        return array_map(self::typed(...), $this->ordered()->get()->getResultArray());
    }

    /**
     * The active methods a business in $countryCode can pay an invoice in $currencyCode with
     * @param string $countryCode
     * @param string $currencyCode
     * @return list<array>
     */
    public function listUsable(string $countryCode, string $currencyCode): array
    {
        $rows = $this->ordered()
            ->where('is_active', 'Y')
            ->where('currency_code', $currencyCode)
            ->groupStart()->where('country_code', $countryCode)->orWhere('country_code', null)->groupEnd()
            ->get()
            ->getResultArray();

        return array_map(static fn (array $row) => [
            'method_code'       => $row['method_code'],
            'currency_code'     => $row['currency_code'],
            'confirmation_mode' => $row['confirmation_mode'],
        ], $rows);
    }

    /**
     * Whether $method can pay an invoice in $currencyCode of a business in $countryCode
     * @param array  $method
     * @param string $countryCode
     * @param string $currencyCode
     * @return bool
     */
    public static function usable(array $method, string $countryCode, string $currencyCode): bool
    {
        return $method['is_active'] === 'Y'
            && $method['currency_code'] === $currencyCode
            && ($method['country_code'] === null || $method['country_code'] === $countryCode);
    }

    /**
     * @param array  $row     Checked columns
     * @param int    $actorId
     * @param string $summary
     * @return int
     * @throws Throwable
     */
    public function createLogged(array $row, int $actorId, string $summary): int
    {
        return $this->inTransaction(function () use ($row, $actorId, $summary) {
            $this->db->table($this->table)->insert($row);
            $id = (int) $this->db->insertID();
            (new ActivityLog())->inserted($actorId, $summary, $this->table, $id, $row);

            return $id;
        });
    }

    /**
     * Only the columns whose value changes are written and logged
     * @param array  $stored
     * @param array  $row
     * @param int    $actorId
     * @param string $summary
     * @return void
     * @throws Throwable
     */
    public function updateLogged(array $stored, array $row, int $actorId, string $summary): void
    {
        $changes = ActivityLog::changes($stored, $row);
        if ($changes === []) {
            return;
        }
        $this->inTransaction(function () use ($stored, $changes, $actorId, $summary) {
            $this->db->table($this->table)->where('id', $stored['id'])->update($changes);
            (new ActivityLog())->updated($actorId, $summary, $this->table, (int) $stored['id'], $stored, $changes);
        });
    }

    /**
     * @return \CodeIgniter\Database\BaseBuilder
     */
    private function ordered()
    {
        return $this->db->table($this->table)
            ->select('method_code, country_code, currency_code, confirmation_mode, provider_code, is_active, sort_order, created_at, updated_at')
            ->orderBy('sort_order', 'ASC')
            ->orderBy('method_code', 'ASC');
    }

    /**
     * @param array $row
     * @return array
     */
    private static function typed(array $row): array
    {
        $row['sort_order'] = (int) $row['sort_order'];

        return $row;
    }
}
