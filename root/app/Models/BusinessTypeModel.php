<?php

namespace App\Models;

use App\Libraries\ActivityLog;
use CodeIgniter\Model;
use InvalidArgumentException;
use Throwable;

/**
 * Model for the business_type table. Its names are in business_type_translation, one for every language of
 * listSupportedLanguages(); the writes here take the names along so a type is never left without one.
 */
class BusinessTypeModel extends Model
{
    use FieldConfigurationTrait;
    use TransactionTrait;

    // type_status
    public const STATUS_ACTIVE   = 'A';
    public const STATUS_INACTIVE = 'I';

    /** Shown where a name is asked for in a language that is not supported */
    public const DEFAULT_LANGUAGE = 'en-US';

    protected $table            = 'business_type';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'type_code',
        'type_status',
    ];

    // created_at and updated_at are maintained by the database
    protected $useTimestamps = false;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $validationRules = [
        'id'          => 'permit_empty|is_natural_no_zero',
        'type_code'   => 'required|max_length[64]|regex_match[/^[a-z0-9]+(_[a-z0-9]+)*$/]|is_unique[business_type.type_code,id,{id}]', // e.g. coffee_shop
        'type_status' => 'permit_empty|in_list[A,I]',
    ];
    protected $validationMessages   = [];
    protected $skipValidation       = false;
    protected $cleanValidationRules = true;

    /**
     * Field configuration shared by the Form Builder (as 'fields') and the Table Builder (via toColumns()),
     * keyed by field name so a controller can unset() what it does not need.
     * The names are not fields of this table; the form adds one per language of listSupportedLanguages().
     * @return array
     */
    public function getConfiguration(): array
    {
        $fields = [
            'type_code'   => ['type' => 'text', 'readonly_in' => ['edit']], // the stable key, fixed once created
            'type_status' => ['type' => 'select'],
        ];
        $enums  = [
            'type_status' => [self::STATUS_ACTIVE => 'success', self::STATUS_INACTIVE => 'secondary'],
        ];

        return $this->buildConfiguration('BusinessType', $fields, $enums);
    }

    /**
     * The languages of listSupportedLanguages() that $names has no name for
     * @param array $names language_code => type_name
     * @return list<string>
     */
    public static function missingLanguages(array $names): array
    {
        helper('list');
        $missing = [];
        foreach (array_keys(listSupportedLanguages()) as $language) {
            if (trim((string) ($names[$language] ?? '')) === '') {
                $missing[] = $language;
            }
        }

        return $missing;
    }

    /**
     * The business types with their names in $language, for a Business Type dropdown
     * @param string $language   A key of listSupportedLanguages(); any other falls back to DEFAULT_LANGUAGE
     * @param bool   $activeOnly Leave out the inactive types, as for a new business
     * @return list<array{id: int, type_code: string, type_status: string, type_name: string}>
     */
    public function listForLookup(string $language, bool $activeOnly = true): array
    {
        helper('list');
        $language = isset(listSupportedLanguages()[$language]) ? $language : self::DEFAULT_LANGUAGE;
        $builder  = $this->db->table($this->table . ' t')
            ->select('t.id, t.type_code, t.type_status, n.type_name')
            ->join('business_type_translation n', 'n.business_type_id = t.id AND n.language_code = ' . $this->db->escape($language), 'left');
        if ($activeOnly) {
            $builder->where('t.type_status', self::STATUS_ACTIVE);
        }
        $rows = $builder->orderBy('n.type_name', 'ASC')->orderBy('t.id', 'ASC')->get()->getResultArray();

        // a name missing for the language (from a language added later) shows the code until it is filled in
        return array_map(static fn (array $row) => ['id' => (int) $row['id'], 'type_name' => $row['type_name'] ?? $row['type_code']] + $row, $rows);
    }

    /**
     * The names of a business type
     * @param int $id
     * @return array language_code => type_name
     */
    public function namesOf(int $id): array
    {
        $rows = $this->db->table('business_type_translation')
            ->select('language_code, type_name')
            ->where('business_type_id', $id)
            ->get()
            ->getResultArray();

        return array_column($rows, 'type_name', 'language_code');
    }

    /**
     * Create a business type with its names in one transaction, logged to log_activity in it
     * @param array $type    Checked business_type columns
     * @param array $names   language_code => type_name, for every language of listSupportedLanguages()
     * @param int   $actorId auth_master.id of the staff member creating it
     * @return int The new business_type.id
     * @throws InvalidArgumentException When a supported language has no name
     * @throws Throwable When a write fails (such as a name being taken meanwhile); nothing is written then
     */
    public function createWithNames(array $type, array $names, int $actorId): int
    {
        $this->checkNames($names);
        $log = new ActivityLog();

        return $this->inTransaction(function () use ($type, $names, $actorId, $log) {
            $this->db->table($this->table)->insert($type);
            $id = (int) $this->db->insertID();
            $log->inserted($actorId, 'Created business type ' . $type['type_code'], $this->table, $id, $type);
            $this->writeNames($id, $type['type_code'], [], $names, $actorId, $log);

            return $id;
        });
    }

    /**
     * Update a business type and its names in one transaction, logged to log_activity in it.
     * Only the columns and names whose value changes are written and logged.
     * @param array $stored  The business_type row
     * @param array $type    Checked business_type columns; type_code is fixed and left out
     * @param array $names   language_code => type_name, for every language of listSupportedLanguages()
     * @param int   $actorId auth_master.id of the staff member making the change
     * @return void
     * @throws InvalidArgumentException When a supported language has no name
     * @throws Throwable When a write fails; nothing is written then
     */
    public function updateWithNames(array $stored, array $type, array $names, int $actorId): void
    {
        $this->checkNames($names);
        unset($type['type_code']);
        $id  = (int) $stored['id'];
        $log = new ActivityLog();
        $this->inTransaction(function () use ($stored, $id, $type, $names, $actorId, $log) {
            $changes = ActivityLog::changes($stored, $type);
            if ($changes !== []) {
                $this->db->table($this->table)->where('id', $id)->update($changes);
                $log->updated($actorId, 'Updated business type ' . $stored['type_code'], $this->table, $id, $stored, $changes);
            }
            $this->writeNames($id, $stored['type_code'], $this->namesOf($id), $names, $actorId, $log);
        });
    }

    /**
     * @param array $names
     * @return void
     * @throws InvalidArgumentException
     */
    private function checkNames(array $names): void
    {
        $missing = self::missingLanguages($names);
        if ($missing !== []) {
            throw new InvalidArgumentException('Business type name missing in ' . implode(', ', $missing));
        }
    }

    /**
     * Insert or update the names that differ from $stored; names of languages no longer supported are kept
     * @param int         $id
     * @param string      $code
     * @param array       $stored language_code => type_name
     * @param array       $names  language_code => type_name
     * @param int         $actorId
     * @param ActivityLog $log
     * @return void
     */
    private function writeNames(int $id, string $code, array $stored, array $names, int $actorId, ActivityLog $log): void
    {
        $table = 'business_type_translation';
        foreach (array_keys(listSupportedLanguages()) as $language) {
            $name = trim((string) $names[$language]);
            $key  = ['business_type_id' => $id, 'language_code' => $language];
            if (! isset($stored[$language])) {
                $row = $key + ['type_name' => $name];
                $this->db->table($table)->insert($row);
                $log->inserted($actorId, 'Named business type ' . $code . ' in ' . $language, $table, (int) $this->db->insertID(), $row);
            } elseif ($stored[$language] !== $name) {
                $old = $this->db->table($table)->select('id, type_name')->where($key)->get()->getRowArray();
                $this->db->table($table)->where('id', $old['id'])->update(['type_name' => $name]);
                $log->updated($actorId, 'Renamed business type ' . $code . ' in ' . $language, $table, (int) $old['id'], $old, ['type_name' => $name]);
            }
        }
    }
}
