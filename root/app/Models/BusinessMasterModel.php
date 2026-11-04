<?php

namespace App\Models;

use App\Libraries\ActivityLog;
use App\Libraries\FieldEncryption;
use CodeIgniter\Model;
use InvalidArgumentException;
use Throwable;

/**
 * Model for the business_master table: a client's business entity, whose contact details are also its billing
 * details. One entity has one country and one currency, fixed once created; going international means another
 * entity. tax_id is encrypted (App\Libraries\FieldEncryption): the writes here encrypt it, findDecrypted() decrypts it.
 */
class BusinessMasterModel extends Model
{
    use FieldConfigurationTrait;
    use TransactionTrait;

    /** Columns fixed once the business is created */
    public const FIXED_FIELDS = ['country_code', 'currency_code'];

    // lgbt_audience
    public const LGBT_FRIENDLY = 'FNDLY';
    public const LGBT_ONLY     = 'LGBT';
    public const LGBT_GAY      = 'GAY';
    public const LGBT_LESBIAN  = 'LES';
    public const LGBT_GENERAL  = 'GEN';

    // naturist_audience
    public const NATURIST_OPTIONAL = 'OPT';
    public const NATURIST_REQUIRED = 'NUD'; // nudity required in some areas, such as some spas
    public const NATURIST_ONLY     = 'NAT';
    public const NATURIST_GENERAL  = 'GEN';

    // age_restricted
    public const AGE_ADULTS_ONLY = 'Y';
    public const AGE_GENERAL     = 'N';

    /** Who the business is for, and what a business left without a value gets: the general public */
    public const AUDIENCE_DEFAULTS = [
        'lgbt_audience'     => self::LGBT_GENERAL,
        'naturist_audience' => self::NATURIST_GENERAL,
        'age_restricted'    => self::AGE_GENERAL,
    ];

    protected $table            = 'business_master';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'business_type_id',
        'business_name',
        'business_slug',
        'business_description',
        'business_mood_and_tone',
        'lgbt_audience',
        'naturist_audience',
        'age_restricted',
        'contact_person_name',
        'contact_email_address',
        'contact_phone_number',
        'contact_website',
        'contact_address_line_1',
        'contact_address_line_2',
        'contact_city',
        'contact_subdivision_code',
        'contact_postal_code',
        'country_code',
        'currency_code',
        'tax_id',
    ];

    // created_at and updated_at are maintained by the database
    protected $useTimestamps = false;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    // country_code is completed in initialize(), from listSupportedCountries(); whether the currency and the
    // subdivision belong to the country is checked by localeErrors(), as a rule cannot see the other field
    protected $validationRules = [
        'id'                       => 'permit_empty|is_natural_no_zero',
        'business_type_id'         => 'required|is_natural_no_zero|is_not_unique[business_type.id]',
        'business_name'            => 'required|max_length[255]',
        'business_slug'            => 'required|max_length[128]|regex_match[/^[a-z0-9]+(-[a-z0-9]+)*$/]|is_unique[business_master.business_slug,id,{id}]', // e.g. otter-cafe-sg
        'business_description'     => 'permit_empty|max_length[65535]',
        'business_mood_and_tone'   => 'permit_empty|max_length[65535]',
        'lgbt_audience'            => 'required|in_list[FNDLY,LGBT,GAY,LES,GEN]',
        'naturist_audience'        => 'required|in_list[OPT,NUD,NAT,GEN]',
        'age_restricted'           => 'required|in_list[Y,N]',
        'contact_person_name'      => 'permit_empty|max_length[128]',
        'contact_email_address'    => 'required|valid_email|max_length[128]',
        'contact_phone_number'     => 'permit_empty|max_length[32]',
        'contact_website'          => 'permit_empty|valid_url_strict[https,http]|max_length[255]',
        'contact_address_line_1'   => 'permit_empty|max_length[255]',
        'contact_address_line_2'   => 'permit_empty|max_length[255]',
        'contact_city'             => 'permit_empty|max_length[128]',
        'contact_subdivision_code' => 'permit_empty|max_length[8]',
        'contact_postal_code'      => 'permit_empty|max_length[16]',
        'country_code'             => 'required|alpha|exact_length[2]',
        'currency_code'            => 'required|alpha|exact_length[3]',
        'tax_id'                   => 'permit_empty|max_length[255]',
    ];
    protected $validationMessages   = [];
    protected $skipValidation       = false;
    protected $cleanValidationRules = true;

    protected function initialize()
    {
        helper('list');
        $this->validationRules['country_code'] .= '|in_list[' . implode(',', array_keys(listSupportedCountries())) . ']';
    }

    /**
     * Field configuration shared by the Form Builder (as 'fields') and the Table Builder (via toColumns()),
     * keyed by field name so a controller can unset() what it does not need.
     * business_type_id is a plain ID here; the controller builds its options from BusinessTypeModel::listForLookup().
     * @return array
     */
    public function getConfiguration(): array
    {
        $currencies = [];
        foreach (array_unique(array_merge(...array_values(listSupportedCurrencies()))) as $code) {
            $currencies[$code] = $code . ' - ' . mapCurrencyCode($code);
        }
        $fields = [
            'business_type_id'         => ['type' => 'number'],
            'business_name'            => ['type' => 'text'],
            'business_slug'            => ['type' => 'text'],
            'business_description'     => ['type' => 'textarea', 'rows' => 4, 'orderable' => false],
            'business_mood_and_tone'   => ['type' => 'textarea', 'rows' => 4, 'orderable' => false],
            'lgbt_audience'            => ['type' => 'select', 'default' => self::LGBT_GENERAL],
            'naturist_audience'        => ['type' => 'select', 'default' => self::NATURIST_GENERAL],
            'age_restricted'           => ['type' => 'select', 'default' => self::AGE_GENERAL],
            'contact_person_name'      => ['type' => 'text'],
            'contact_email_address'    => ['type' => 'email'],
            'contact_phone_number'     => ['type' => 'tel'],
            'contact_website'          => ['type' => 'url'],
            'contact_address_line_1'   => ['type' => 'text'],
            'contact_address_line_2'   => ['type' => 'text'],
            'contact_city'             => ['type' => 'text'],
            'contact_subdivision_code' => ['type' => 'text', 'searchable' => false], // depends on country_code; no dependent select yet
            'contact_postal_code'      => ['type' => 'text'],
            'country_code'             => ['type' => 'select', 'options' => listSupportedCountries(), 'readonly_in' => ['edit'], 'searchable' => false],
            'currency_code'            => ['type' => 'select', 'options' => $currencies, 'readonly_in' => ['edit'], 'searchable' => false],
            'tax_id'                   => ['type' => 'text', 'orderable' => false, 'searchable' => false], // encrypted
        ];

        // the general public last: the select shows the others first, as the ones a business picks
        $enums = [
            'lgbt_audience'     => [self::LGBT_FRIENDLY, self::LGBT_ONLY, self::LGBT_GAY, self::LGBT_LESBIAN, self::LGBT_GENERAL],
            'naturist_audience' => [self::NATURIST_OPTIONAL, self::NATURIST_REQUIRED, self::NATURIST_ONLY, self::NATURIST_GENERAL],
            'age_restricted'    => [self::AGE_ADULTS_ONLY, self::AGE_GENERAL],
        ];

        return $this->buildConfiguration('BusinessMaster', $fields, $enums);
    }

    /**
     * Errors for the columns of $business that do not fit its country: a currency not in
     * listSupportedCurrencies(country_code), or a subdivision not in listSupportedSubdivisions(country_code)
     * @param array $business Includes country_code
     * @return array field => error
     */
    public static function localeErrors(array $business): array
    {
        helper('list');
        $country = strtoupper((string) ($business['country_code'] ?? ''));
        $errors  = [];
        if (isset($business['currency_code']) && ! in_array(strtoupper($business['currency_code']), listSupportedCurrencies($country), true)) {
            $errors['currency_code'] = lang('BusinessMaster.invalidCurrency');
        }
        $subdivision = $business['contact_subdivision_code'] ?? null;
        if ($subdivision !== null && $subdivision !== '' && ! isset(listSupportedSubdivisions($country)[strtoupper($subdivision)])) {
            $errors['contact_subdivision_code'] = lang('BusinessMaster.invalidSubdivision');
        }

        return $errors;
    }

    /**
     * Whether another business in $countryCode has this name (the database refuses it; this tells the form first)
     * @param string   $name
     * @param string   $countryCode
     * @param int|null $exceptId The business being edited
     * @return bool
     */
    public function nameTaken(string $name, string $countryCode, ?int $exceptId = null): bool
    {
        $builder = $this->db->table($this->table)->where('business_name', $name)->where('country_code', strtoupper($countryCode));
        if ($exceptId !== null) {
            $builder->where('id !=', $exceptId);
        }

        return $builder->countAllResults() > 0;
    }

    /**
     * A business with tax_id decrypted
     * @param int $id
     * @return array|null
     */
    public function findDecrypted(int $id): ?array
    {
        $row = $this->db->table($this->table)->where('id', $id)->get()->getRowArray();

        return $row === null ? null : (new FieldEncryption())->decryptRow($this->table, $row);
    }

    /**
     * A business by its slug, with tax_id decrypted
     * @param string $slug
     * @return array|null
     */
    public function findBySlugDecrypted(string $slug): ?array
    {
        $row = $this->db->table($this->table)->where('business_slug', $slug)->get()->getRowArray();

        return $row === null ? null : (new FieldEncryption())->decryptRow($this->table, $row);
    }

    /**
     * The names of a business in other languages
     * @param int $id
     * @return array language_code => business_name
     */
    public function namesOf(int $id): array
    {
        $rows = $this->db->table('business_master_translation')
            ->select('language_code, business_name')
            ->where('business_id', $id)
            ->get()
            ->getResultArray();

        return array_column($rows, 'business_name', 'language_code');
    }

    /**
     * Create a business, its owner's link and its names in one transaction, logged to log_activity in it.
     * The link is the owner's default business when it is their first link (BusinessClientLinkModel::firstLinkDefault()).
     * @param array $business      Checked business_master columns (localeErrors() included), tax_id as plaintext
     * @param int   $ownerClientId client_master.id of its first owner
     * @param int   $actorId       auth_master.id of whoever creates it
     * @param array $names         language_code => business_name, for languages of listLanguages(); optional
     * @return int The new business_master.id
     * @throws Throwable When a write fails (such as the slug or the name being taken meanwhile); nothing is written then
     */
    public function createWithOwner(array $business, int $ownerClientId, int $actorId, array $names = []): int
    {
        $crypt = new FieldEncryption();
        $log   = new ActivityLog();

        return $this->inTransaction(function () use ($business, $ownerClientId, $actorId, $names, $crypt, $log) {
            $this->db->table($this->table)->insert($crypt->encryptRow($this->table, $business));
            $id = (int) $this->db->insertID();
            $log->inserted($actorId, 'Created business ' . $business['business_slug'], $this->table, $id, $business);

            $link = [
                'business_id'         => $id,
                'client_id'           => $ownerClientId,
                'client_status'       => BusinessClientLinkModel::STATUS_ACTIVE,
                'client_role'         => BusinessClientLinkModel::ROLE_OWNER,
                'is_default_business' => model(BusinessClientLinkModel::class)->firstLinkDefault($ownerClientId),
                'start_date'          => date('Y-m-d'),
            ];
            $this->db->table('business_client_link')->insert($link);
            $log->inserted($actorId, 'Made client ' . $ownerClientId . ' owner of ' . $business['business_slug'], 'business_client_link', (int) $this->db->insertID(), $link);

            $this->writeNames($id, $business['business_slug'], [], $names, $actorId, $log);

            return $id;
        });
    }

    /**
     * Update a business and its names in one transaction, logged to log_activity in it.
     * Only the columns and names whose value changes are written and logged; tax_id is compared as plaintext.
     * @param array      $stored   findDecrypted()
     * @param array      $business Checked business_master columns
     * @param int        $actorId  auth_master.id of whoever makes the change
     * @param array|null $names    language_code => business_name; a language left out or empty loses its name.
     *                             Null leaves the names as they are
     * @return void
     * @throws InvalidArgumentException When $business changes a column of FIXED_FIELDS
     * @throws Throwable When a write fails; nothing is written then
     */
    public function updateBusiness(array $stored, array $business, int $actorId, ?array $names = null): void
    {
        $changes = ActivityLog::changes($stored, $business);
        $fixed   = array_intersect(array_keys($changes), self::FIXED_FIELDS);
        if ($fixed !== []) {
            throw new InvalidArgumentException('Fixed once the business is created: ' . implode(', ', $fixed));
        }
        $id    = (int) $stored['id'];
        $crypt = new FieldEncryption();
        $log   = new ActivityLog();
        $this->inTransaction(function () use ($stored, $id, $changes, $actorId, $names, $crypt, $log) {
            if ($changes !== []) {
                $this->db->table($this->table)->where('id', $id)->update($crypt->encryptRow($this->table, $changes));
                $log->updated($actorId, 'Updated business ' . $stored['business_slug'], $this->table, $id, $stored, $changes);
            }
            if ($names !== null) {
                $this->writeNames($id, $changes['business_slug'] ?? $stored['business_slug'], $this->namesOf($id), $names, $actorId, $log, true);
            }
        });
    }

    /**
     * Insert, update or (with $replace) delete the names that differ from $stored
     * @param int         $id
     * @param string      $slug
     * @param array       $stored  language_code => business_name
     * @param array       $names   language_code => business_name; languages not in listLanguages() are ignored
     * @param int         $actorId
     * @param ActivityLog $log
     * @param bool        $replace Delete the stored names that $names leaves out or empties
     * @return void
     */
    private function writeNames(int $id, string $slug, array $stored, array $names, int $actorId, ActivityLog $log, bool $replace = false): void
    {
        helper('list');
        $table = 'business_master_translation';
        foreach (array_keys(listLanguages()) as $language) {
            $name = trim((string) ($names[$language] ?? ''));
            $key  = ['business_id' => $id, 'language_code' => $language];
            if ($name === '') {
                if ($replace && isset($stored[$language])) {
                    $old = $this->db->table($table)->where($key)->get()->getRowArray();
                    $this->db->table($table)->where('id', $old['id'])->delete();
                    $log->deleted($actorId, 'Removed ' . $language . ' name of business ' . $slug, $table, (int) $old['id'], $old);
                }
            } elseif (! isset($stored[$language])) {
                $row = $key + ['business_name' => $name];
                $this->db->table($table)->insert($row);
                $log->inserted($actorId, 'Named business ' . $slug . ' in ' . $language, $table, (int) $this->db->insertID(), $row);
            } elseif ($stored[$language] !== $name) {
                $old = $this->db->table($table)->select('id, business_name')->where($key)->get()->getRowArray();
                $this->db->table($table)->where('id', $old['id'])->update(['business_name' => $name]);
                $log->updated($actorId, 'Renamed business ' . $slug . ' in ' . $language, $table, (int) $old['id'], $old, ['business_name' => $name]);
            }
        }
    }
}
