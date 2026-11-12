<?php

namespace App\Models;

use CodeIgniter\Model;
use Config\Mart as MartConfig;

/**
 * What the public pages of a business on the marketplace (otternova_mart) show, read-only and in one language:
 * - site():      the business and its website settings, for every page's layout and the main page
 * - cards():     artifacts as product cards, by public_ids, category or type
 * - artifact():  one artifact with its variants, options, images and reviews
 * Only active artifacts (artifact_status A) and their active variants and categories are shown. A text falls back to
 * the row's own when it has no translation in the language. Internal ids never leave this model.
 * App\Controllers\Mart caches what these give (App\Libraries\MartCache).
 */
class MartCatalogModel extends Model
{
    protected $table      = 'artifact_master';
    protected $primaryKey = 'id';
    protected $returnType = 'array';

    /** /mart/artifacts' sort => ORDER BY, name and id last so a page is always cut the same way */
    public const SORTS = [
        'name'       => [],
        'price_asc'  => [['a.price_active_lowest', 'ASC']],
        'price_desc' => [['a.price_active_lowest', 'DESC']],
        'rating'     => [['a.rating_average', 'DESC'], ['a.rating_count', 'DESC']],
        'newest'     => [['a.created_at', 'DESC'], ['a.id', 'DESC']],
    ];

    private MartConfig $config;

    protected function initialize()
    {
        $this->config = config(MartConfig::class);
    }

    /**
     * The language the business's pages are shown in for a visitor asking for $requested, and the ones it has
     * - the business has website languages: $requested when it is one of them, else its default (the first)
     * - it has none yet: $requested when it is in listLanguages(), else the first of listLanguages()
     * @param int    $businessId
     * @param string $requested A language_code, or '' for none
     * @return array{0: string, 1: list<array>} [language_code, the business_website_language rows, the default first]
     */
    public function languageOf(int $businessId, string $requested): array
    {
        helper('list');
        $rows  = model(BusinessWebsiteModel::class)->languagesOf($businessId);
        $codes = array_column($rows, 'language_code');
        if ($codes === []) {
            $codes = array_keys(listLanguages());
        }

        return [in_array($requested, $codes, true) ? $requested : $codes[0], $rows];
    }

    /**
     * Everything every page of the business needs, in $language
     * @param array  $business  business_master row
     * @param string $language  from languageOf()
     * @param list<array> $languageRows from languageOf()
     * @return array
     */
    public function site(array $business, string $language, array $languageRows): array
    {
        helper('list');
        $businessId = (int) $business['id'];
        $websites   = model(BusinessWebsiteModel::class);
        $website    = $websites->websiteOf($businessId);
        $settings   = [];
        foreach ($languageRows as $row) {
            if ($row['language_code'] === $language) {
                $settings = $row;
            }
        }
        $name = $this->db->table('business_master_translation')->select('business_name')
            ->where('business_id', $businessId)->where('language_code', $language)
            ->get()->getRowArray()['business_name'] ?? $business['business_name'];

        $fonts     = [];
        $typefaces = listTypefacesOfLanguage($language);
        foreach (['heading' => 'heading_font', 'nav' => 'nav_font', 'default' => 'default_font'] as $role => $column) {
            $font          = $settings[$column] ?? null;
            $fonts[$role]  = $font === null ? null : ['name' => $font, 'query' => $typefaces[$font] ?? null];
        }
        $queries = array_values(array_unique(array_filter(array_column(array_filter($fonts), 'query'))));

        $languages = [];
        $names     = listLanguages();
        foreach ($languageRows === [] ? [['language_code' => $language]] : $languageRows as $row) {
            $languages[] = ['code' => $row['language_code'], 'name' => $names[$row['language_code']] ?? $row['language_code']];
        }

        $featured = $websites->featuredIdsOf($businessId);
        $cards    = $this->cardsByIds($businessId, $language, array_merge(...array_values($featured)));

        return [
            'language'  => $language,
            'languages' => $languages,
            'business'  => [
                'slug'          => $business['business_slug'],
                'name'          => $name,
                'description'   => $business['business_description'],
                'country_code'  => $business['country_code'],
                'currency_code' => $business['currency_code'],
                'contact'       => [
                    'email_address'    => $business['contact_email_address'],
                    'phone_number'     => $business['contact_phone_number'],
                    'website'          => $business['contact_website'],
                    'address_line_1'   => $business['contact_address_line_1'],
                    'address_line_2'   => $business['contact_address_line_2'],
                    'city'             => $business['contact_city'],
                    'subdivision_code' => $business['contact_subdivision_code'],
                    'postal_code'      => $business['contact_postal_code'],
                ],
                'audience'      => [
                    'lgbt'           => $business['lgbt_audience'],
                    'naturist'       => $business['naturist_audience'],
                    'age_restricted' => $business['age_restricted'] === BusinessMasterModel::AGE_ADULTS_ONLY,
                ],
                // what the customer pays besides the prices; value as "10.00", in percent (unit P) or currency_code (F)
                'charges'       => [
                    'tip'            => [
                        'policy' => $business['tip_policy'], // D disabled, OFF off-system, O optional, M mandatory
                        'value'  => number_format((float) $business['tip_value'], 2, '.', ''),
                        'unit'   => $business['tip_unit'],
                        'kind'   => $business['tip_value_kind'], // S suggested, M minimum
                    ],
                    'service_charge' => [
                        'value' => number_format((float) $business['service_charge_value'], 2, '.', ''),
                        'unit'  => $business['service_charge_unit'],
                    ],
                ],
                'rating'        => [
                    'average'   => number_format((float) $business['rating_average'], 2, '.', ''),
                    'count'     => (int) $business['rating_count'],
                    'breakdown' => ArtifactReviewModel::breakdownOf($business),
                ],
            ],
            'theme'     => [
                'colors'           => array_intersect_key($website, BusinessWebsiteModel::DEFAULT_COLORS),
                'fonts'            => $fonts,
                'google_fonts_url' => $queries === [] ? null : 'https://fonts.googleapis.com/css2?' . implode('&', $queries) . '&display=swap',
                'logo'             => $website['logo_path'] === null ? null : ['url' => $website['logo_path'], 'shape' => $website['logo_shape']],
            ],
            'meta'      => [
                'title'       => ($settings['meta_title'] ?? '') !== '' ? $settings['meta_title'] : $name,
                'description' => $settings['meta_description'] ?? null,
                'keywords'    => $settings['meta_keywords'] ?? null,
            ],
            'hero'      => [
                'badge'     => $settings['hero_badge'] ?? null,
                'headline'  => $settings['hero_headline'] ?? null,
                'paragraph' => $settings['hero_paragraph'] ?? null,
            ],
            'featured'  => [
                'best_sellers'  => $this->pick($cards, $featured[BusinessWebsiteModel::SECTION_BEST_SELLERS]),
                'trending'      => $this->pick($cards, $featured[BusinessWebsiteModel::SECTION_TRENDING]),
                'highest_rated' => $this->highestRated($businessId, $language),
            ],
            'categories' => $this->categories($businessId, $language),
            'stores'     => $this->stores($businessId, $language),
        ];
    }

    /**
     * A page of the business's artifacts as cards
     * @param int         $businessId
     * @param string      $language
     * @param array{type?: string, category_id?: int, sort: string, page: int, per_page: int} $filters
     * @return array{artifacts: list<array>, total: int, page: int, per_page: int, pages: int}
     */
    public function cards(int $businessId, string $language, array $filters): array
    {
        $builder = $this->cardQuery($businessId);
        if (isset($filters['type'])) {
            $builder->where('a.artifact_type', $filters['type']);
        }
        if (isset($filters['category_id'])) {
            $builder->where('a.category_id', $filters['category_id']);
        }
        $total = (int) $builder->countAllResults(false);
        foreach (self::SORTS[$filters['sort']] as [$column, $direction]) {
            $builder->orderBy($column, $direction);
        }
        $rows = $builder->orderBy('a.artifact_name')->orderBy('a.id')
            ->limit($filters['per_page'], ($filters['page'] - 1) * $filters['per_page'])
            ->get()->getResultArray();

        return [
            'artifacts' => array_values($this->toCards($rows, $language)),
            'total'     => $total,
            'page'      => $filters['page'],
            'per_page'  => $filters['per_page'],
            'pages'     => (int) ceil($total / $filters['per_page']),
        ];
    }

    /**
     * The business's active artifacts with these public_ids as cards, in the order asked; those that are not (any
     * more) are left out
     * @param int          $businessId
     * @param string       $language
     * @param list<string> $publicIds
     * @return list<array>
     */
    public function cardsByPublicIds(int $businessId, string $language, array $publicIds): array
    {
        if ($publicIds === []) {
            return [];
        }
        $rows  = $this->cardQuery($businessId)->whereIn('a.public_id', $publicIds)->get()->getResultArray();
        $cards = array_column($this->toCards($rows, $language), null, 'public_id');

        return array_values(array_filter(array_map(static fn (string $id) => $cards[$id] ?? null, $publicIds)));
    }

    /**
     * One active artifact of the business with everything its page shows, or null when it has none such
     * @param int    $businessId
     * @param string $language
     * @param string $publicId
     * @return array|null
     */
    public function artifact(int $businessId, string $language, string $publicId): ?array
    {
        $row = $this->cardQuery($businessId)
            ->select('a.artifact_description, a.tax_calculation, a.tax_percentage, a.rating_1_count, a.rating_2_count, a.rating_3_count, a.rating_4_count, a.rating_5_count')
            ->where('a.public_id', $publicId)
            ->get()->getRowArray();
        if ($row === null) {
            return null;
        }
        $id   = (int) $row['id'];
        $card = $this->toCards([$row], $language)[$id];
        $text = $this->db->table('artifact_master_translation')->select('artifact_description')
            ->where('artifact_id', $id)->where('language_code', $language)
            ->get()->getRowArray();

        [$variants, $options] = $this->variants($id, $language);
        $variantIds           = array_column($variants, 'public_id');

        $images = array_values(array_filter(
            model(ArtifactImageModel::class)->listOfArtifact($id),
            static fn (array $image): bool => $image['variant_public_id'] === null || in_array($image['variant_public_id'], $variantIds, true),
        ));

        return [
            'artifact' => $card + [
                'description' => ($text['artifact_description'] ?? '') !== '' ? $text['artifact_description'] : $row['artifact_description'],
                'tax'         => [
                    'calculation' => $row['tax_calculation'],
                    'percentage'  => $row['tax_percentage'] === null ? null : number_format((float) $row['tax_percentage'], 2, '.', ''),
                ],
            ],
            'options'  => $options,
            'variants' => $variants,
            'images'   => array_map(static fn (array $image): array => [
                'public_id'         => $image['public_id'],
                'url'               => $image['url'],
                'variant_public_id' => $image['variant_public_id'],
            ], $images),
            'reviews'  => $this->reviews($id),
        ];
    }

    /**
     * The id of an active category of the business, by its public_id
     * @param int    $businessId
     * @param string $publicId
     * @return int|null
     */
    public function categoryIdOf(int $businessId, string $publicId): ?int
    {
        $row = $this->db->table('artifact_category')->select('id')
            ->where('business_id', $businessId)->where('public_id', $publicId)
            ->where('category_status', ArtifactCategoryModel::STATUS_ACTIVE)
            ->get()->getRowArray();

        return $row === null ? null : (int) $row['id'];
    }

    /**
     * The active artifacts of a business with what a card needs; the caller adds its own WHERE and ORDER BY
     */
    private function cardQuery(int $businessId)
    {
        $variants = $this->db->prefixTable(ArtifactMasterModel::VARIANTS);

        return $this->db->table($this->table . ' a')
            ->select('a.id, a.public_id, a.artifact_name, a.artifact_type, a.artifact_label, a.category_id,'
                . ' c.public_id AS category_public_id, c.category_name, c.category_status,'
                . ' a.price_active_lowest, a.price_compare_lowest, a.rating_average, a.rating_count')
            ->select('(SELECT COUNT(DISTINCT v.price_active) FROM ' . $variants . ' v WHERE v.artifact_id = a.id AND v.variant_status = \'A\') AS price_count', false)
            ->join('artifact_category c', 'c.id = a.category_id', 'left')
            ->where('a.business_id', $businessId)
            ->where('a.artifact_status', ArtifactMasterModel::STATUS_ACTIVE);
    }

    /**
     * Rows of cardQuery() as cards, keyed by artifact id, in their order
     * @param list<array> $rows
     * @param string      $language
     * @return array<int, array>
     */
    private function toCards(array $rows, string $language): array
    {
        if ($rows === []) {
            return [];
        }
        $ids        = array_map('intval', array_column($rows, 'id'));
        $names      = $this->namesIn('artifact_master_translation', 'artifact_id', 'artifact_name', $ids, $language);
        $categoryIds = array_values(array_unique(array_map('intval', array_filter(array_column($rows, 'category_id')))));
        $categories = $this->namesIn('artifact_category_translation', 'category_id', 'category_name', $categoryIds, $language);

        $covers = [];
        foreach ($this->db->table('artifact_image')->select('artifact_id, image_path')->whereIn('artifact_id', $ids)
            ->orderBy('sort_order', 'DESC')->orderBy('id', 'DESC')->get()->getResultArray() as $image) {
            $covers[(int) $image['artifact_id']] = $image['image_path']; // the last written is the first in order
        }

        $cards = [];
        foreach ($rows as $row) {
            $id         = (int) $row['id'];
            $categoryId = $row['category_id'] === null ? null : (int) $row['category_id'];
            $cards[$id] = [
                'public_id' => $row['public_id'],
                'type'      => $row['artifact_type'],
                'name'      => $names[$id] ?? $row['artifact_name'],
                'label'     => $row['artifact_label'],
                // an inactive category is not one to browse by
                'category'  => $categoryId === null || $row['category_status'] !== ArtifactCategoryModel::STATUS_ACTIVE ? null : [
                    'public_id' => $row['category_public_id'],
                    'name'      => $categories[$categoryId] ?? $row['category_name'],
                ],
                'price'     => [
                    'active'  => number_format((float) $row['price_active_lowest'], 2, '.', ''),
                    'compare' => $row['price_compare_lowest'] === null ? null : number_format((float) $row['price_compare_lowest'], 2, '.', ''),
                    'varies'  => (int) $row['price_count'] > 1, // "from" the active price
                ],
                'rating'    => [
                    'average' => number_format((float) $row['rating_average'], 2, '.', ''),
                    'count'   => (int) $row['rating_count'],
                ] + (array_key_exists('rating_1_count', $row) ? ['breakdown' => ArtifactReviewModel::breakdownOf($row)] : []),
                'cover_url' => $covers[$id] ?? null,
            ];
        }

        return $cards;
    }

    /**
     * Cards by artifact id, for the featured lists
     * @param int       $businessId
     * @param string    $language
     * @param list<int> $ids
     * @return array<int, array>
     */
    private function cardsByIds(int $businessId, string $language, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return $this->toCards($this->cardQuery($businessId)->whereIn('a.id', $ids)->get()->getResultArray(), $language);
    }

    /**
     * The cards of $ids that there are, in that order
     * @param array<int, array> $cards
     * @param list<int>         $ids
     * @return list<array>
     */
    private function pick(array $cards, array $ids): array
    {
        return array_values(array_filter(array_map(static fn (int $id) => $cards[$id] ?? null, $ids)));
    }

    /**
     * The best-rated active artifacts with at least one rating
     */
    private function highestRated(int $businessId, string $language): array
    {
        $rows = $this->cardQuery($businessId)->where('a.rating_count >', 0)
            ->orderBy('a.rating_average', 'DESC')->orderBy('a.rating_count', 'DESC')->orderBy('a.id')
            ->limit($this->config->highestRatedCount)
            ->get()->getResultArray();

        return array_values($this->toCards($rows, $language));
    }

    /**
     * The active categories of the business in their order, with how many active artifacts each has
     */
    private function categories(int $businessId, string $language): array
    {
        $rows = $this->db->table('artifact_category c')
            ->select('c.id, c.public_id, c.category_name, COUNT(a.id) AS artifact_count')
            ->join('artifact_master a', 'a.category_id = c.id AND a.artifact_status = ' . $this->db->escape(ArtifactMasterModel::STATUS_ACTIVE), 'left', false)
            ->where('c.business_id', $businessId)
            ->where('c.category_status', ArtifactCategoryModel::STATUS_ACTIVE)
            ->groupBy('c.id, c.public_id, c.category_name, c.sort_order')
            ->orderBy('c.sort_order')->orderBy('c.category_name')
            ->get()->getResultArray();
        $names = $this->namesIn('artifact_category_translation', 'category_id', 'category_name', array_map('intval', array_column($rows, 'id')), $language);

        return array_map(static fn (array $row): array => [
            'public_id'      => $row['public_id'],
            'name'           => $names[(int) $row['id']] ?? $row['category_name'],
            'artifact_count' => (int) $row['artifact_count'],
        ], $rows);
    }

    /**
     * The stores of the business by name, with what a visitor needs to find or call them
     */
    private function stores(int $businessId, string $language): array
    {
        $rows = $this->db->table('business_store')
            ->select('id, public_id, store_name, store_type, store_phone_number, store_address_line_1, store_address_line_2, store_city, store_subdivision_code, store_postal_code')
            ->where('business_id', $businessId)
            ->orderBy('store_name')->orderBy('id')
            ->get()->getResultArray();
        $texts = [];
        if ($rows !== []) {
            // the name and address in the visitor's language; each column falls back to business_store's on its own
            $translated = $this->db->table('business_store_translation')
                ->select('store_id, ' . implode(', ', BusinessStoreModel::TRANSLATED_FIELDS))
                ->whereIn('store_id', array_map('intval', array_column($rows, 'id')))->where('language_code', $language)
                ->get()->getResultArray();
            foreach ($translated as $text) {
                $texts[(int) $text['store_id']] = array_filter(
                    array_intersect_key($text, array_flip(BusinessStoreModel::TRANSLATED_FIELDS)),
                    static fn ($value) => $value !== null && (string) $value !== ''
                );
            }
        }

        return array_map(static fn (array $row): array => [
            'public_id'        => $row['public_id'],
            'name'             => $texts[(int) $row['id']]['store_name'] ?? $row['store_name'],
            'type'             => $row['store_type'],
            'phone_number'     => $row['store_phone_number'],
            // an online store has no address, translated or not
            'address_line_1'   => $row['store_type'] === BusinessStoreModel::TYPE_ONLINE ? null : ($texts[(int) $row['id']]['store_address_line_1'] ?? $row['store_address_line_1']),
            'address_line_2'   => $row['store_type'] === BusinessStoreModel::TYPE_ONLINE ? null : ($texts[(int) $row['id']]['store_address_line_2'] ?? $row['store_address_line_2']),
            'city'             => $row['store_type'] === BusinessStoreModel::TYPE_ONLINE ? null : ($texts[(int) $row['id']]['store_city'] ?? $row['store_city']),
            'subdivision_code' => $row['store_subdivision_code'],
            'postal_code'      => $row['store_postal_code'],
        ], $rows);
    }

    /**
     * The active variants of an artifact in their order, and the attributes they are chosen by, each with only the
     * values some active variant has, in their order
     * @param int    $artifactId
     * @param string $language
     * @return array{0: list<array>, 1: list<array>}
     */
    private function variants(int $artifactId, string $language): array
    {
        $variants = array_values(array_filter(
            model(ArtifactMasterModel::class)->variantsOf($artifactId),
            static fn (array $variant): bool => $variant['variant_status'] === 'A',
        ));
        if ($variants === []) {
            return [[], []];
        }

        $attributeIds = [];
        $valueIds     = [];
        foreach ($variants as $variant) {
            foreach ($variant['values'] as $attributeId => $valueId) {
                $attributeIds[$attributeId] = true;
                $valueIds[$valueId]         = true;
            }
        }
        $options = [];
        if ($attributeIds !== []) {
            $attributes = $this->db->table('artifact_attribute')->select('id, public_id, attribute_name')
                ->whereIn('id', array_keys($attributeIds))->orderBy('attribute_name')->get()->getResultArray();
            $attributeNames = $this->namesIn('artifact_attribute_translation', 'attribute_id', 'attribute_name', array_keys($attributeIds), $language);
            $values = $this->db->table('artifact_attribute_value')->select('id, attribute_id, public_id, attribute_value, color_code')
                ->whereIn('id', array_keys($valueIds))->orderBy('sort_order')->orderBy('attribute_value')->get()->getResultArray();
            $valueNames = $this->namesIn('artifact_attribute_value_translation', 'attribute_value_id', 'attribute_value', array_keys($valueIds), $language);
            $byAttribute = [];
            foreach ($values as $value) {
                $byAttribute[(int) $value['attribute_id']][] = [
                    'public_id'  => $value['public_id'],
                    'value'      => $valueNames[(int) $value['id']] ?? $value['attribute_value'],
                    'color_code' => $value['color_code'], // #rrggbb, or null
                ];
            }
            foreach ($attributes as $attribute) {
                $options[] = [
                    'public_id' => $attribute['public_id'],
                    'name'      => $attributeNames[(int) $attribute['id']] ?? $attribute['attribute_name'],
                    'values'    => $byAttribute[(int) $attribute['id']] ?? [],
                ];
            }
        }

        $out = array_map(static fn (array $variant): array => [
            'public_id'        => $variant['public_id'],
            'sku'              => $variant['sku'],
            'price'            => [
                'active'  => number_format((float) $variant['price_active'], 2, '.', ''),
                'compare' => $variant['price_compare'] === null ? null : number_format((float) $variant['price_compare'], 2, '.', ''),
            ],
            'attribute_values' => $variant['attribute_values'],
            'weight_grams'     => $variant['weight_grams'] === null ? null : (int) $variant['weight_grams'],
            'duration_minutes' => $variant['duration_minutes'] === null ? null : (int) $variant['duration_minutes'],
            'booking_type'     => $variant['booking_type'],
            'capacity'         => $variant['capacity'] === null ? null : (int) $variant['capacity'],
            // the stock of every store together; null when no store keeps count of it
            'stock'            => $variant['inventory'] === [] ? null : array_sum($variant['inventory']),
        ], $variants);

        return [$out, $options];
    }

    /**
     * The approved reviews of an artifact, the newest first, with only the reviewer's first name and initial
     */
    private function reviews(int $artifactId): array
    {
        $rows = $this->db->table('artifact_review r')
            ->select('r.public_id, r.rating, r.review_title, r.review_message, r.reply_message, r.replied_at, r.created_at, c.customer_name')
            ->join('customer_master c', 'c.id = r.customer_id')
            ->where('r.artifact_id', $artifactId)
            ->where('r.review_status', ArtifactReviewModel::STATUS_APPROVED)
            ->orderBy('r.created_at', 'DESC')->orderBy('r.id', 'DESC')
            ->limit($this->config->reviewCount)
            ->get()->getResultArray();

        return array_map(static fn (array $row): array => [
            'public_id'     => $row['public_id'],
            'rating'        => (int) $row['rating'],
            'title'         => $row['review_title'],
            'message'       => $row['review_message'],
            'reviewer_name' => self::shortName((string) $row['customer_name']),
            'created_at'    => $row['created_at'],
            'reply'         => $row['reply_message'] === null ? null : ['message' => $row['reply_message'], 'replied_at' => $row['replied_at']],
        ], $rows);
    }

    /** "Somchai Jaidee" as "Somchai J.", so a public page does not give a customer's full name */
    public static function shortName(string $name): string
    {
        $parts = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($parts) < 2) {
            return $parts[0] ?? '';
        }

        return $parts[0] . ' ' . mb_substr((string) end($parts), 0, 1) . '.';
    }

    /**
     * The texts of a translation table in one language
     * @param string    $table
     * @param string    $parentColumn
     * @param string    $column
     * @param list<int> $parentIds
     * @param string    $language
     * @return array<int, string> parent id => text
     */
    private function namesIn(string $table, string $parentColumn, string $column, array $parentIds, string $language): array
    {
        if ($parentIds === []) {
            return [];
        }
        $rows = $this->db->table($table)->select($parentColumn . ', ' . $column)
            ->whereIn($parentColumn, $parentIds)->where('language_code', $language)
            ->get()->getResultArray();
        $out = [];
        foreach ($rows as $row) {
            if ((string) $row[$column] !== '') {
                $out[(int) $row[$parentColumn]] = $row[$column];
            }
        }

        return $out;
    }
}
