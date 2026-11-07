<?php

namespace App\Models;

use App\Libraries\Money;
use CodeIgniter\Model;

/**
 * Model for the withholding_tax_rule table: the tax a business withholds from what it pays us, by country and rule,
 * over time. See the CreateWithholdingTaxRuleTable migration for the ranges and the columns.
 */
class WithholdingTaxRuleModel extends Model
{
    use PublicIdTrait;

    protected $table            = 'withholding_tax_rule';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [];

    // created_at and updated_at are maintained by the database
    protected $useTimestamps = false;

    /** public_id: CHAR(8), see PublicIdTrait */
    protected int $publicIdLength = 8;
    protected $beforeInsert       = ['fillPublicId'];

    /**
     * The row of the series in effect at $at
     * @param string $countryCode
     * @param string $ruleCode
     * @param string $at UTC
     * @return array|null
     */
    public function inEffect(string $countryCode, string $ruleCode, string $at): ?array
    {
        return $this->inEffectBuilder($at)->where('country_code', $countryCode)->where('rule_code', $ruleCode)->get()->getRowArray();
    }

    /**
     * The rules of the country in effect at $at, by rule_code
     * @param string $countryCode
     * @param string $at UTC
     * @return array<string, array>
     */
    public function inEffectOfCountry(string $countryCode, string $at): array
    {
        $rows = $this->inEffectBuilder($at)->where('country_code', $countryCode)->orderBy('rule_code', 'ASC')->get()->getResultArray();

        return array_column($rows, null, 'rule_code');
    }

    /**
     * What is withheld from $baseCents at $rate percent, rounded half up to the satang or cent
     * @param int    $baseCents
     * @param string $rate      Such as 3.00
     * @return int
     */
    public static function withheldCents(int $baseCents, string $rate): int
    {
        $basisPoints = Money::toCents(Money::format($rate)); // 3.00% is 300

        return intdiv($baseCents * $basisPoints + 5000, 10000);
    }

    /**
     * The rule as the API sends it
     * @param array $row
     * @return array
     */
    public static function toPayload(array $row): array
    {
        return [
            'rule_code'            => $row['rule_code'],
            'rate'                 => Money::format($row['rate']),
            'min_base_amount'      => Money::format($row['min_base_amount']),
            'currency_code'        => $row['currency_code'],
            'certificate_required' => $row['certificate_required'],
            'certificate_name'     => $row['certificate_name'],
            'certificate_due_days' => (int) $row['certificate_due_days'],
        ];
    }

    /**
     * @param string $at
     * @return \CodeIgniter\Database\BaseBuilder
     */
    private function inEffectBuilder(string $at)
    {
        return $this->db->table($this->table)
            ->where('effective_from <=', $at)
            ->groupStart()->where('effective_to', null)->orWhere('effective_to >', $at)->groupEnd();
    }
}
