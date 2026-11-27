<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Searching the businesses of the marketplace (otternova_mart's /search) by name, in any of the languages it is named
 * in (business_master_translation), or by its tags (TagMasterModel). Every word typed has to be found, each in a
 * name or a tag, so "thai massage bangkok" finds a business named "Bangkok Spa" tagged "Thai massage". Read-only;
 * internal ids never leave this model.
 */
class BusinessSearchModel extends Model
{
    protected $table      = 'business_master';
    protected $primaryKey = 'id';
    protected $returnType = 'array';

    /** The longest search taken, in characters, and the most words looked for */
    public const MAX_LENGTH = 100;
    public const MAX_WORDS  = 5;

    /** Businesses a page shows */
    public const PER_PAGE = 12;

    /** The most matches ranked: enough for every page anyone reads */
    private const MAX_MATCHES = 300;

    /** The character escaping %, _ and itself in a LIKE pattern */
    private const LIKE_ESCAPE = '!';

    /**
     * The words looked for in a search: its tidied form (TagMasterModel::clean() rules aside: anything may be typed)
     * split at spaces and commas, case folded, each once, at most MAX_WORDS
     * @param mixed $query
     * @return list<string>
     */
    public static function wordsOf(mixed $query): array
    {
        if (! is_string($query) || ! mb_check_encoding($query, 'UTF-8')) {
            return [];
        }
        $query = mb_substr(trim($query), 0, self::MAX_LENGTH);
        $query = mb_convert_case((string) \Normalizer::normalize(mb_convert_kana($query, 'as', 'UTF-8'), \Normalizer::FORM_C), MB_CASE_FOLD, 'UTF-8');
        $words = preg_split('/[\s,，、#]+/u', $query, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_slice(array_values(array_unique($words)), 0, self::MAX_WORDS);
    }

    /**
     * A page of the businesses found, best matches first: a name or a tag that is the whole search, then one that
     * starts with it, then the others; then the most reviewed, then by name
     * @param string $query
     * @param string $language for the names: the business's name in it when it has one
     * @param int    $page     from 1
     * @return array{query: string, businesses: list<array>, total: int, page: int, per_page: int, pages: int}
     *         A business: {slug, name, description, country_code, logo: {url, shape}|null, tags: list<string>,
     *         rating: {average, count}, age_restricted}
     */
    public function search(string $query, string $language, int $page): array
    {
        $words  = self::wordsOf($query);
        $phrase = implode(' ', $words);
        $empty  = ['query' => $phrase, 'businesses' => [], 'total' => 0, 'page' => 1, 'per_page' => self::PER_PAGE, 'pages' => 0];
        if ($words === []) {
            return $empty;
        }

        $builder = $this->db->table('business_master b')->select('b.id')->where('b.is_business_open', BusinessMasterModel::OPEN)->where('b.moderation_status', 'N'); // closed, blocked or removed ones are not on the mart
        foreach ($words as $word) {
            // not escapeLikeString(): it doubles quotes too, which escape() then does again
            $pattern = str_replace([self::LIKE_ESCAPE, '%', '_'], [self::LIKE_ESCAPE . self::LIKE_ESCAPE, self::LIKE_ESCAPE . '%', self::LIKE_ESCAPE . '_'], $word);
            $like    = $this->db->escape('%' . $pattern . '%') . " ESCAPE '" . self::LIKE_ESCAPE . "'";
            $builder->groupStart()
                ->where('LOWER(b.business_name) LIKE ' . $like, null, false)
                ->orWhere('EXISTS (SELECT 1 FROM ' . $this->db->prefixTable('business_master_translation') . ' bmt WHERE bmt.business_id = b.id AND LOWER(bmt.business_name) LIKE ' . $like . ')', null, false)
                ->orWhere('EXISTS (SELECT 1 FROM ' . $this->db->prefixTable('business_tag') . ' bt JOIN ' . $this->db->prefixTable('tag_master')
                    . ' t ON t.id = bt.tag_id WHERE bt.business_id = b.id AND t.tag_key LIKE ' . $like . ')', null, false)
                ->groupEnd();
        }
        $ids = array_map('intval', array_column($builder->limit(self::MAX_MATCHES)->get()->getResultArray(), 'id'));
        if ($ids === []) {
            return $empty;
        }

        $businesses = $this->rowsOf($ids, $language);
        foreach ($businesses as &$business) {
            $business['score'] = $this->score($business, $phrase);
        }
        unset($business);
        usort($businesses, static fn (array $a, array $b): int => [$b['score'], $b['rating']['count'], mb_strtolower($a['name'])]
            <=> [$a['score'], $a['rating']['count'], mb_strtolower($b['name'])]);

        $total = count($businesses);
        $pages = (int) ceil($total / self::PER_PAGE);
        $page  = max(1, min($page, $pages));
        $shown = array_slice($businesses, ($page - 1) * self::PER_PAGE, self::PER_PAGE);

        return [
            'query'      => $phrase,
            'businesses' => array_map(static function (array $b): array {
                unset($b['score'], $b['names']);

                return $b;
            }, $shown),
            'total'      => $total,
            'page'       => $page,
            'per_page'   => self::PER_PAGE,
            'pages'      => $pages,
        ];
    }

    /**
     * The businesses as the results show them, with every name they have (for the ranking)
     * @param list<int> $ids
     * @param string    $language
     * @return list<array>
     */
    private function rowsOf(array $ids, string $language): array
    {
        $rows = $this->db->table('business_master b')
            ->select('b.id, b.business_slug, b.business_name, b.business_description, b.country_code, b.rating_average, b.rating_count, b.age_restricted, w.logo_path, w.logo_shape')
            ->join('business_website w', 'w.business_id = b.id', 'left')
            ->whereIn('b.id', $ids)->get()->getResultArray();

        $names = [];
        foreach ($this->db->table('business_master_translation')->select('business_id, language_code, business_name')->whereIn('business_id', $ids)->get()->getResultArray() as $t) {
            $names[(int) $t['business_id']][$t['language_code']] = $t['business_name'];
        }
        $tags = [];
        foreach ($this->db->table('business_tag bt')->select('bt.business_id, t.tag_name')->join('tag_master t', 't.id = bt.tag_id')
            ->whereIn('bt.business_id', $ids)->orderBy('t.tag_name')->get()->getResultArray() as $t) {
            $tags[(int) $t['business_id']][] = $t['tag_name'];
        }

        return array_map(static function (array $row) use ($names, $tags, $language): array {
            $id = (int) $row['id'];

            return [
                'slug'           => $row['business_slug'],
                'name'           => $names[$id][$language] ?? $row['business_name'],
                'description'    => $row['business_description'],
                'country_code'   => $row['country_code'],
                'logo'           => $row['logo_path'] === null ? null : ['url' => $row['logo_path'], 'shape' => $row['logo_shape']],
                'tags'           => $tags[$id] ?? [],
                'rating'         => ['average' => number_format((float) $row['rating_average'], 2, '.', ''), 'count' => (int) $row['rating_count']],
                'age_restricted' => $row['age_restricted'] === BusinessMasterModel::AGE_ADULTS_ONLY,
                'names'          => [$row['business_name'], ...array_values($names[$id] ?? [])],
            ];
        }, $rows);
    }

    /**
     * How well a business matches the whole search: 5 a name is it, 4 a tag is it, 3 a name starts with it, 2 a tag
     * starts with it, 1 otherwise (every word is somewhere)
     * @param array  $business from rowsOf()
     * @param string $phrase   the words, case folded
     * @return int
     */
    private function score(array $business, string $phrase): int
    {
        $fold  = static fn (string $s): string => mb_convert_case($s, MB_CASE_FOLD, 'UTF-8');
        $names = array_map($fold, $business['names']);
        $tags  = array_map($fold, $business['tags']);
        $starts = static fn (array $list): bool => array_filter($list, static fn (string $s): bool => str_starts_with($s, $phrase)) !== [];

        return match (true) {
            in_array($phrase, $names, true) => 5,
            in_array($phrase, $tags, true)  => 4,
            $starts($names)                 => 3,
            $starts($tags)                  => 2,
            default                         => 1,
        };
    }
}
