<?php

namespace App\Models;

use App\Libraries\ActivityLog;
use CodeIgniter\Model;
use Throwable;

/**
 * Model for the artifact_master table: a product (P) or service (S) of a business, together with what is saved with
 * it: its names in other languages (artifact_master_translation), its variants (artifact_variant), their attribute
 * values (artifact_variant_attribute_value) and their stock per store (artifact_variant_inventory).
 * Nothing here is ever deleted but a variant's attribute value that changes; artifact_status D retires an artifact
 * for good and variant_status I takes a variant off sale.
 * price_active_lowest and price_compare_lowest follow the cheapest active variant, refreshed in the transaction of
 * every save.
 */
class ArtifactMasterModel extends Model
{
    use PublicIdTrait;
    use TransactionTrait;
    use TranslationWriteTrait;

    // artifact_type
    public const TYPE_PRODUCT = 'P';
    public const TYPE_SERVICE = 'S';

    // artifact_status
    public const STATUS_ACTIVE       = 'A';
    public const STATUS_INACTIVE     = 'I';
    public const STATUS_DISCONTINUED = 'D';

    // tax_calculation
    public const TAX_INCLUSIVE = 'I';
    public const TAX_EXCLUSIVE = 'E';
    public const TAX_NONE      = 'X';

    /** The columns a client sets, besides business_id and artifact_type */
    public const FORM_FIELDS = ['category_id', 'artifact_name', 'artifact_description', 'artifact_status', 'artifact_label', 'tax_calculation', 'tax_percentage'];

    /** The artifact_variant columns a client sets */
    public const VARIANT_FIELDS = ['sku', 'barcode', 'price_active', 'price_compare', 'variant_status', 'weight_grams', 'duration_minutes', 'booking_type', 'staff_required', 'capacity', 'sort_order'];

    /** The variant columns of products only, and of services only */
    public const PRODUCT_ONLY_FIELDS = ['barcode', 'weight_grams'];
    public const SERVICE_ONLY_FIELDS = ['duration_minutes', 'booking_type', 'staff_required', 'capacity'];

    // artifact_variant.booking_type
    public const BOOKING_APPOINTMENT = 'A';
    public const BOOKING_GROUP       = 'G';

    public const VARIANTS     = 'artifact_variant';
    public const VALUES       = 'artifact_variant_attribute_value';
    public const INVENTORY    = 'artifact_variant_inventory';
    private const TRANSLATIONS = 'artifact_master_translation';

    protected $table            = 'artifact_master';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [];

    /** public_id: CHAR(12), see PublicIdTrait; artifact_variant's are as long */
    protected int $publicIdLength = 12;

    // created_at and updated_at are maintained by the database
    protected $useTimestamps = false;

    /**
     * The artifacts of a business by name, as the list of the Client app shows them
     * @param int   $businessId
     * @param array $filters artifact_type, artifact_status, category_id: each only when set
     * @return list<array>
     */
    public function listOfBusiness(int $businessId, array $filters = []): array
    {
        $builder = $this->db->table($this->table . ' a')
            ->select('a.id, a.public_id, a.artifact_name, a.artifact_type, a.artifact_status, a.artifact_label, c.public_id AS category_public_id,'
                . ' a.price_active_lowest, a.price_compare_lowest, a.rating_count, a.rating_average,'
                . ' a.rating_1_count, a.rating_2_count, a.rating_3_count, a.rating_4_count, a.rating_5_count')
            ->select('(SELECT COUNT(*) FROM ' . $this->db->prefixTable(self::VARIANTS) . ' v WHERE v.artifact_id = a.id) AS variant_count', false)
            ->join('artifact_category c', 'c.id = a.category_id', 'left')
            ->where('a.business_id', $businessId);
        foreach (['artifact_type', 'artifact_status', 'category_id'] as $filter) {
            if (isset($filters[$filter])) {
                $builder->where('a.' . $filter, $filters[$filter]);
            }
        }
        $rows = $builder->orderBy('a.artifact_name')->orderBy('a.id')->get()->getResultArray();
        if ($rows === []) {
            return [];
        }

        // the cover of each: its image with the lowest sort_order
        $covers = [];
        $images = $this->db->table('artifact_image')
            ->select('artifact_id, image_path')
            ->whereIn('artifact_id', array_column($rows, 'id'))
            ->orderBy('sort_order', 'DESC')
            ->orderBy('id', 'DESC')
            ->get()
            ->getResultArray();
        foreach ($images as $image) {
            $covers[(int) $image['artifact_id']] = $image['image_path']; // the last written is the first in order
        }

        return array_map(static function (array $row) use ($covers): array {
            $id = (int) $row['id'];
            unset($row['id']);

            $row['rating_count']   = (int) $row['rating_count'];
            $row['rating_average'] = number_format((float) $row['rating_average'], 2, '.', '');
            $row['rating_breakdown'] = ArtifactReviewModel::breakdownOf($row);
            foreach (ArtifactReviewModel::STARS as $star) {
                unset($row['rating_' . $star . '_count']);
            }
            $row['variant_count']  = (int) $row['variant_count'];
            $row['cover_url']      = $covers[$id] ?? null;

            return self::withPrices($row);
        }, $rows);
    }

    /**
     * How many artifacts of each type a business has towards its plan's caps: the discontinued ones are not counted
     * @param int $businessId
     * @return array{P: int, S: int}
     */
    public function countsByType(int $businessId): array
    {
        $rows = $this->db->table($this->table)
            ->select('artifact_type, COUNT(*) AS artifacts')
            ->where('business_id', $businessId)
            ->where('artifact_status !=', self::STATUS_DISCONTINUED)
            ->groupBy('artifact_type')
            ->get()
            ->getResultArray();

        return array_map('intval', array_column($rows, 'artifacts', 'artifact_type')) + [self::TYPE_PRODUCT => 0, self::TYPE_SERVICE => 0];
    }

    /**
     * An artifact with everything saved with it, as the API sends it
     * @param int $id
     * @return array{artifact: array, variants: list<array>, images: list<array>}
     */
    public function detail(int $id): array
    {
        $row = $this->db->table($this->table . ' a')
            ->select('a.*, c.public_id AS category_public_id')
            ->join('artifact_category c', 'c.id = a.category_id', 'left')
            ->where('a.id', $id)
            ->get()
            ->getRowArray();
        $artifact = self::withPrices([
            'public_id'            => $row['public_id'],
            'artifact_name'        => $row['artifact_name'],
            'artifact_description' => $row['artifact_description'],
            'artifact_type'        => $row['artifact_type'],
            'artifact_status'      => $row['artifact_status'],
            'artifact_label'       => $row['artifact_label'],
            'category_public_id'   => $row['category_public_id'],
            'price_active_lowest'  => $row['price_active_lowest'],
            'price_compare_lowest' => $row['price_compare_lowest'],
            'tax_calculation'      => $row['tax_calculation'],
            'tax_percentage'       => $row['tax_percentage'] === null ? null : number_format((float) $row['tax_percentage'], 2, '.', ''),
            'rating_count'         => (int) $row['rating_count'],
            'rating_average'       => number_format((float) $row['rating_average'], 2, '.', ''),
            'rating_breakdown'     => ArtifactReviewModel::breakdownOf($row),
            'translations'         => $this->translationsOf(self::TRANSLATIONS, 'artifact_id', [$id], ['artifact_name', 'artifact_description'])[$id] ?? [],
        ]);

        $variants = array_map(static fn (array $variant): array => [
            'public_id'        => $variant['public_id'],
            'sku'              => $variant['sku'],
            'barcode'          => $variant['barcode'],
            'price_active'     => number_format((float) $variant['price_active'], 2, '.', ''),
            'price_compare'    => $variant['price_compare'] === null ? null : number_format((float) $variant['price_compare'], 2, '.', ''),
            'variant_status'   => $variant['variant_status'],
            'weight_grams'     => $variant['weight_grams'] === null ? null : (int) $variant['weight_grams'],
            'duration_minutes' => $variant['duration_minutes'] === null ? null : (int) $variant['duration_minutes'],
            'booking_type'     => $variant['booking_type'],
            'staff_required'   => $variant['staff_required'] === null ? null : (int) $variant['staff_required'],
            'capacity'         => $variant['capacity'] === null ? null : (int) $variant['capacity'],
            'sort_order'       => (int) $variant['sort_order'],
            'attribute_values' => $variant['attribute_values'],
            'inventory'        => $variant['inventory_out'],
        ], array_values($this->variantsOf($id)));

        return ['artifact' => $artifact, 'variants' => $variants, 'images' => model(ArtifactImageModel::class)->listOfArtifact($id)];
    }

    /**
     * The variants of an artifact as stored, in their order, keyed by public_id, each with
     * - values:           attribute_id => attribute_value_id
     * - inventory:        store_id => quantity
     * - attribute_values: [{ attribute_public_id, value_public_id }], and inventory_out: [{ store_public_id, quantity }],
     *                     as the API sends them
     * @param int $artifactId
     * @return array<string, array>
     */
    public function variantsOf(int $artifactId): array
    {
        $variants = $this->db->table(self::VARIANTS)
            ->where('artifact_id', $artifactId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->getResultArray();
        if ($variants === []) {
            return [];
        }
        $ids  = array_column($variants, 'id');
        $byId = [];
        foreach ($variants as $variant) {
            $byId[(int) $variant['id']] = $variant + ['values' => [], 'inventory' => [], 'attribute_values' => [], 'inventory_out' => []];
        }
        $values = $this->db->table(self::VALUES . ' vv')
            ->select('vv.variant_id, vv.attribute_id, vv.attribute_value_id, a.public_id AS attribute_public_id, v.public_id AS value_public_id')
            ->join('artifact_attribute a', 'a.id = vv.attribute_id')
            ->join('artifact_attribute_value v', 'v.id = vv.attribute_value_id')
            ->whereIn('vv.variant_id', $ids)
            ->orderBy('a.attribute_name')
            ->get()
            ->getResultArray();
        foreach ($values as $value) {
            $variant                                                         = (int) $value['variant_id'];
            $byId[$variant]['values'][(int) $value['attribute_id']]          = (int) $value['attribute_value_id'];
            $byId[$variant]['attribute_values'][]                            = ['attribute_public_id' => $value['attribute_public_id'], 'value_public_id' => $value['value_public_id']];
        }
        $stock = $this->db->table(self::INVENTORY . ' i')
            ->select('i.variant_id, i.store_id, i.quantity, s.public_id AS store_public_id')
            ->join('business_store s', 's.id = i.store_id')
            ->whereIn('i.variant_id', $ids)
            ->orderBy('s.store_name')
            ->get()
            ->getResultArray();
        foreach ($stock as $row) {
            $variant                                              = (int) $row['variant_id'];
            $byId[$variant]['inventory'][(int) $row['store_id']] = (int) $row['quantity'];
            $byId[$variant]['inventory_out'][]                    = ['store_public_id' => $row['store_public_id'], 'quantity' => (int) $row['quantity']];
        }

        return array_column(array_values($byId), null, 'public_id');
    }

    /**
     * The SKUs of $skus that a variant of the business other than those of $artifactId already has
     * @param int          $businessId
     * @param list<string> $skus
     * @param int|null     $artifactId The artifact being edited, whose own variants do not count
     * @return list<string>
     */
    public function skusTaken(int $businessId, array $skus, ?int $artifactId): array
    {
        if ($skus === []) {
            return [];
        }
        $builder = $this->db->table(self::VARIANTS)->select('sku')->where('business_id', $businessId)->whereIn('sku', $skus);
        if ($artifactId !== null) {
            $builder->where('artifact_id !=', $artifactId);
        }

        return array_column($builder->get()->getResultArray(), 'sku');
    }

    /**
     * Create or update an artifact with its names and variants in one transaction, logged to log_activity in it.
     * Only what changes is written. A create is refused when the business has $maxOfType artifacts of the type
     * already, not counting the discontinued ones; the business's row is locked first (on MySQL), so two creates at
     * once cannot both take the last place.
     * @param array|null $stored   The artifact_master row; null to create one
     * @param array      $artifact Checked columns: FORM_FIELDS, plus business_id and artifact_type for a create
     * @param array|null $names    language_code => [artifact_name, artifact_description]; null leaves them as they are
     * @param list<array{stored: array|null, row: array, values: array<int, int>|null, inventory: array<int, int>}> $variants
     *                             Checked variants: a stored one (as variantsOf() gives it) to update, or a new one.
     *                             values (attribute_id => attribute_value_id) replace the stored ones, null keeps them;
     *                             inventory (store_id => quantity) sets the quantity of each store given, the others
     *                             stay
     * @param int|null   $maxOfType The cap of Config\PlanLimits for the artifact's type; null for none
     * @param int        $actorId
     * @return string|null The artifact's public_id, or null when the create is refused for the cap
     * @throws Throwable When a write fails; nothing is written then
     */
    public function saveArtifact(?array $stored, array $artifact, ?array $names, array $variants, ?int $maxOfType, int $actorId): ?string
    {
        $log  = new ActivityLog();
        $work = function (string $publicId) use ($stored, $artifact, $names, $variants, $maxOfType, $actorId, $log): string|false {
            $what = 'artifact ' . $publicId;
            if ($stored === null) {
                if ($maxOfType !== null) {
                    if ($this->db->DBDriver === 'MySQLi') {
                        $this->db->query('SELECT id FROM ' . $this->db->escapeIdentifiers($this->db->prefixTable('business_master')) . ' WHERE id = ? FOR UPDATE', [$artifact['business_id']]);
                    }
                    $count = $this->db->table($this->table)
                        ->where('business_id', $artifact['business_id'])
                        ->where('artifact_type', $artifact['artifact_type'])
                        ->where('artifact_status !=', self::STATUS_DISCONTINUED)
                        ->countAllResults();
                    if ($count >= $maxOfType) {
                        return false;
                    }
                }
                $row = ['public_id' => $publicId] + $artifact;
                $this->db->table($this->table)->insert($row);
                $id = (int) $this->db->insertID();
                $log->inserted($actorId, 'Created ' . $what . ' of business ' . $row['business_id'], $this->table, $id, $row);
                $businessId  = (int) $row['business_id'];
                $storedNames = [];
            } else {
                $id         = (int) $stored['id'];
                $businessId = (int) $stored['business_id'];
                $changes    = ActivityLog::changes(self::decimals($stored, ['tax_percentage']), array_intersect_key($artifact, array_flip(self::FORM_FIELDS)));
                if ($changes !== []) {
                    $this->db->table($this->table)->where('id', $id)->update($changes);
                    $log->updated($actorId, 'Updated ' . $what, $this->table, $id, $stored, $changes);
                }
                $storedNames = $names === null ? [] : ($this->translationsOf(self::TRANSLATIONS, 'artifact_id', [$id], ['artifact_name', 'artifact_description'])[$id] ?? []);
            }
            if ($names !== null) {
                $this->writeTranslations(self::TRANSLATIONS, 'artifact_id', $id, $what, $storedNames, $names, $actorId, $log, $stored !== null);
            }
            foreach ($variants as $variant) {
                $this->writeVariant($businessId, $id, $publicId, $variant, $actorId, $log);
            }
            $this->refreshLowestPrices($id, $publicId, $actorId, $log);

            return $publicId;
        };

        $publicId = $stored === null
            ? $this->withPublicId(fn (string $publicId) => $this->inTransaction(static fn () => $work($publicId)))
            : $this->inTransaction(static fn () => $work($stored['public_id']));

        return $publicId === false ? null : $publicId;
    }

    /**
     * Insert or update one variant, with its attribute values and stock
     * @param int         $businessId
     * @param int         $artifactId
     * @param string      $artifactPublicId
     * @param array       $variant As saveArtifact() takes it
     * @param int         $actorId
     * @param ActivityLog $log
     * @return void
     */
    private function writeVariant(int $businessId, int $artifactId, string $artifactPublicId, array $variant, int $actorId, ActivityLog $log): void
    {
        $stored = $variant['stored'];
        if ($stored === null) {
            $row = ['public_id' => $this->newPublicIdIn(self::VARIANTS), 'business_id' => $businessId, 'artifact_id' => $artifactId] + $variant['row'];
            $this->db->table(self::VARIANTS)->insert($row);
            $id       = (int) $this->db->insertID();
            $publicId = $row['public_id'];
            $log->inserted($actorId, 'Added variant ' . $publicId . ' to artifact ' . $artifactPublicId, self::VARIANTS, $id, $row);
            $storedValues    = [];
            $storedInventory = [];
        } else {
            $id       = (int) $stored['id'];
            $publicId = $stored['public_id'];
            $changes  = ActivityLog::changes(self::decimals($stored, ['price_active', 'price_compare']), $variant['row']);
            if ($changes !== []) {
                $this->db->table(self::VARIANTS)->where('id', $id)->update($changes);
                $log->updated($actorId, 'Updated variant ' . $publicId, self::VARIANTS, $id, array_diff_key($stored, ['values' => 1, 'inventory' => 1, 'attribute_values' => 1, 'inventory_out' => 1]), $changes);
            }
            $storedValues    = $stored['values'];
            $storedInventory = $stored['inventory'];
        }

        if ($variant['values'] !== null) {
            foreach ($variant['values'] as $attributeId => $valueId) {
                if (! isset($storedValues[$attributeId])) {
                    $row = ['variant_id' => $id, 'attribute_id' => $attributeId, 'attribute_value_id' => $valueId];
                    $this->db->table(self::VALUES)->insert($row);
                    $log->inserted($actorId, 'Gave variant ' . $publicId . ' an attribute value', self::VALUES, (int) $this->db->insertID(), $row);
                } elseif ($storedValues[$attributeId] !== $valueId) {
                    $old = $this->db->table(self::VALUES)->where(['variant_id' => $id, 'attribute_id' => $attributeId])->get()->getRowArray();
                    $this->db->table(self::VALUES)->where('id', $old['id'])->update(['attribute_value_id' => $valueId]);
                    $log->updated($actorId, 'Changed an attribute value of variant ' . $publicId, self::VALUES, (int) $old['id'], $old, ['attribute_value_id' => $valueId]);
                }
            }
            foreach (array_diff_key($storedValues, $variant['values']) as $attributeId => $valueId) {
                $old = $this->db->table(self::VALUES)->where(['variant_id' => $id, 'attribute_id' => $attributeId])->get()->getRowArray();
                $this->db->table(self::VALUES)->where('id', $old['id'])->delete();
                $log->deleted($actorId, 'Took an attribute value from variant ' . $publicId, self::VALUES, (int) $old['id'], $old);
            }
        }

        foreach ($variant['inventory'] as $storeId => $quantity) {
            if (! array_key_exists($storeId, $storedInventory)) {
                $row = ['variant_id' => $id, 'store_id' => $storeId, 'quantity' => $quantity];
                $this->db->table(self::INVENTORY)->insert($row);
                $log->inserted($actorId, 'Stocked variant ' . $publicId . ' at store ' . $storeId, self::INVENTORY, (int) $this->db->insertID(), $row);
            } elseif ($storedInventory[$storeId] !== $quantity) {
                $old = $this->db->table(self::INVENTORY)->where(['variant_id' => $id, 'store_id' => $storeId])->get()->getRowArray();
                $this->db->table(self::INVENTORY)->where('id', $old['id'])->update(['quantity' => $quantity]);
                $log->updated($actorId, 'Counted the stock of variant ' . $publicId . ' at store ' . $storeId, self::INVENTORY, (int) $old['id'], $old, ['quantity' => $quantity]);
            }
        }
    }

    /**
     * Set price_active_lowest and price_compare_lowest to the cheapest active variant's prices (0.00 and NULL when
     * none is active)
     * @param int         $id
     * @param string      $publicId
     * @param int         $actorId
     * @param ActivityLog $log
     * @return void
     */
    private function refreshLowestPrices(int $id, string $publicId, int $actorId, ActivityLog $log): void
    {
        $cheapest = $this->db->table(self::VARIANTS)
            ->select('price_active, price_compare')
            ->where('artifact_id', $id)
            ->where('variant_status', 'A')
            ->orderBy('price_active')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->limit(1)
            ->get()
            ->getRowArray();
        $prices = [
            'price_active_lowest'  => $cheapest === null ? '0.00' : number_format((float) $cheapest['price_active'], 2, '.', ''),
            'price_compare_lowest' => $cheapest === null || $cheapest['price_compare'] === null ? null : number_format((float) $cheapest['price_compare'], 2, '.', ''),
        ];
        $stored = $this->db->table($this->table)->select('id, price_active_lowest, price_compare_lowest')->where('id', $id)->get()->getRowArray();
        $before = [
            'price_active_lowest'  => number_format((float) $stored['price_active_lowest'], 2, '.', ''),
            'price_compare_lowest' => $stored['price_compare_lowest'] === null ? null : number_format((float) $stored['price_compare_lowest'], 2, '.', ''),
        ];
        $changes = ActivityLog::changes($before, $prices);
        if ($changes !== []) {
            $this->db->table($this->table)->where('id', $id)->update($changes);
            $log->updated($actorId, 'Refreshed the lowest prices of artifact ' . $publicId, $this->table, $id, $before, $changes);
        }
    }

    /**
     * $row with the DECIMAL columns $columns as strings with two decimals, so that "299" read back from SQLite
     * compares equal to the "299.00" written
     * @param array        $row
     * @param list<string> $columns
     * @return array
     */
    private static function decimals(array $row, array $columns): array
    {
        foreach ($columns as $column) {
            if (isset($row[$column])) {
                $row[$column] = number_format((float) $row[$column], 2, '.', '');
            }
        }

        return $row;
    }

    /**
     * $row with its lowest prices as strings with two decimals, as the drivers return DECIMAL differently
     * @param array $row
     * @return array
     */
    private static function withPrices(array $row): array
    {
        $row['price_active_lowest']  = number_format((float) $row['price_active_lowest'], 2, '.', '');
        $row['price_compare_lowest'] = $row['price_compare_lowest'] === null ? null : number_format((float) $row['price_compare_lowest'], 2, '.', '');

        return $row;
    }

    /**
     * The services (artifact_type S) of a business, with their names in other languages, by name
     * @param int $businessId
     * @return list<array{id: int, public_id: string, artifact_name: string, artifact_status: string, translations: array<string, string>}>
     *         With the internal id, for the caller's own use; strip it before anything goes out
     */
    public function servicesOf(int $businessId): array
    {
        $rows = $this->db->table($this->table)->select('id, public_id, artifact_name, artifact_status')
            ->where('business_id', $businessId)->where('artifact_type', self::TYPE_SERVICE)
            ->orderBy('artifact_name')->orderBy('id')
            ->get()->getResultArray();
        if ($rows === []) {
            return [];
        }
        $names = [];
        foreach ($this->db->table(self::TRANSLATIONS)->select('artifact_id, language_code, artifact_name')
            ->whereIn('artifact_id', array_column($rows, 'id'))->get()->getResultArray() as $row) {
            $names[(int) $row['artifact_id']][$row['language_code']] = $row['artifact_name'];
        }

        return array_map(static fn (array $row): array => ['id' => (int) $row['id']] + $row + ['translations' => $names[(int) $row['id']] ?? []], $rows);
    }
}
