<?php

namespace App\Models;

use App\Libraries\ActivityLog;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Model;
use Normalizer;
use Throwable;

/**
 * Model for tag_master and business_tag: the tags every business shares, in any language, and the ones each business
 * gives itself, for searching businesses by what they offer. A business writes its tags itself: one found by its key
 * (keyOf()) is reused, as first written ("thai massage" is the tag "Thai Massage"), and one not found is made. A tag in
 * another language is another tag; there are no translations. Tags no business has any more are kept, but not
 * suggested.
 */
class TagMasterModel extends Model
{
    use PublicIdTrait;
    use TransactionTrait;

    public const TABLE          = 'tag_master';
    public const BUSINESS_TABLE = 'business_tag';

    /** The longest tag taken, in characters */
    public const MAX_LENGTH = 50;

    /** The most tags a business may have */
    public const MAX_PER_BUSINESS = 20;

    /** The most suggestions given */
    public const MAX_SUGGESTIONS = 10;

    /**
     * Letters (with their marks: Thai vowels and tone marks), digits, and spaces, hyphens, apostrophes, ampersands and
     * full stops between them; it starts with a letter or digit
     */
    private const PATTERN = '/^[\p{L}\p{N}][\p{L}\p{M}\p{N} \'&.\-]*$/u';

    protected $table            = self::TABLE;
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [];

    /** public_id: CHAR(8), see PublicIdTrait */
    protected int $publicIdLength = 8;

    // created_at and updated_at are maintained by the database
    protected $useTimestamps = false;

    /**
     * A tag as written, tidied: NFC, full-width Latin letters, digits and spaces as plain ones, a leading # dropped,
     * spaces at the ends trimmed and runs of them made one. Not NFKC: it splits the Thai sara am (ำ) into two
     * characters, so a tag would no longer be what Thai people type.
     * @param mixed $tag
     * @return string|null null when it is not a tag: empty, longer than MAX_LENGTH, or with other characters
     */
    public static function clean(mixed $tag): ?string
    {
        if (! is_string($tag) || ! mb_check_encoding($tag, 'UTF-8')) {
            return null;
        }
        $tag = Normalizer::normalize(mb_convert_kana($tag, 'as', 'UTF-8'), Normalizer::FORM_C);
        if (! is_string($tag)) {
            return null;
        }
        $tag = trim((string) preg_replace('/\s+/u', ' ', ltrim(trim($tag), '#')));

        return $tag !== '' && mb_strlen($tag) <= self::MAX_LENGTH && preg_match(self::PATTERN, $tag) === 1 ? $tag : null;
    }

    /**
     * The key a tag is found by: its clean() form with the case folded, so tags told apart only by case are one
     * @param string $clean From clean()
     * @return string
     */
    public static function keyOf(string $clean): string
    {
        return mb_convert_case($clean, MB_CASE_FOLD, 'UTF-8');
    }

    /**
     * Check and tidy the tags of a request. Tags that are the same once tidied are taken once, the first as written.
     * @param mixed $tags list of strings
     * @return array{tags: array<string, string>, errors: array<int|string, string>} tags: key => clean() form, in
     *         order; errors: by index in $tags, a key of the Tag language file (invalidTag), or
     *         ['tags' => invalidTags|tooManyTags]; empty when every tag is good
     */
    public static function normalizeTags(mixed $tags): array
    {
        if (! is_array($tags) || ! array_is_list($tags)) {
            return ['tags' => [], 'errors' => ['tags' => 'invalidTags']];
        }
        $normalized = [];
        $errors     = [];
        foreach ($tags as $i => $tag) {
            $clean = self::clean($tag);
            if ($clean === null) {
                $errors[$i] = 'invalidTag';
            } else {
                $normalized[self::keyOf($clean)] ??= $clean;
            }
        }
        if ($errors === [] && count($normalized) > self::MAX_PER_BUSINESS) {
            $errors['tags'] = 'tooManyTags';
        }

        return ['tags' => $errors === [] ? $normalized : [], 'errors' => $errors];
    }

    /**
     * The tags of the business, by name
     * @param int $businessId
     * @return list<array{public_id: string, tag_name: string}>
     */
    public function tagsOf(int $businessId): array
    {
        return $this->db->table(self::BUSINESS_TABLE . ' bt')
            ->select('t.public_id, t.tag_name')
            ->join(self::TABLE . ' t', 't.id = bt.tag_id')
            ->where('bt.business_id', $businessId)
            ->orderBy('t.tag_name')
            ->get()->getResultArray();
    }

    /**
     * Tags that businesses have, whose key holds what is typed: those starting with it first, then the ones more
     * businesses have; the business's own are left out
     * @param string   $typed
     * @param int|null $exceptBusinessId
     * @return list<array{tag_name: string, business_count: int}>
     */
    public function suggest(string $typed, ?int $exceptBusinessId = null): array
    {
        $clean = self::clean($typed);
        if ($clean === null) {
            return [];
        }
        $key     = self::keyOf($clean);
        $builder = $this->db->table(self::TABLE . ' t')
            ->select('t.tag_name, t.tag_key, COUNT(bt.business_id) AS business_count')
            ->join(self::BUSINESS_TABLE . ' bt', 'bt.tag_id = t.id')
            ->like('t.tag_key', $key, 'both')
            ->groupBy('t.id, t.tag_name, t.tag_key')
            ->orderBy('business_count', 'DESC')
            ->orderBy('t.tag_key')
            ->limit(self::MAX_SUGGESTIONS * 5);
        if ($exceptBusinessId !== null) {
            $own = $this->db->table(self::BUSINESS_TABLE)->select('tag_id')->where('business_id', $exceptBusinessId);
            $builder->whereNotIn('t.id', $own);
        }
        $rows = $builder->get()->getResultArray();
        // stable: within each group the order of the query stays
        $starting = array_filter($rows, static fn (array $row): bool => str_starts_with($row['tag_key'], $key));
        $rows     = [...$starting, ...array_diff_key($rows, $starting)];

        return array_map(static fn (array $row): array => ['tag_name' => $row['tag_name'], 'business_count' => (int) $row['business_count']],
            array_slice($rows, 0, self::MAX_SUGGESTIONS));
    }

    /**
     * Make the business have exactly $tags, in one transaction, logged to log_activity in it: the tags left out are
     * taken off, the others found by their key or made, and given to it. Run again once when another request made the
     * same new tag meanwhile.
     * @param int                   $businessId
     * @param array<string, string> $tags       key => clean() form, from normalizeTags()
     * @param int|null              $actorId    auth_master.id of whoever makes the change
     * @return void
     * @throws Throwable When a write fails; nothing is written then
     */
    public function replaceTags(int $businessId, array $tags, ?int $actorId): void
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                $this->inTransaction(fn () => $this->writeTags($businessId, $tags, $actorId));

                return;
            } catch (DatabaseException $e) {
                if ($attempt >= 2 || ! str_contains($e->getMessage(), 'tag_key')) {
                    throw $e;
                }
            }
        }
    }

    /**
     * replaceTags() inside its transaction
     * @param int                   $businessId
     * @param array<string, string> $tags
     * @param int|null              $actorId
     * @return void
     */
    private function writeTags(int $businessId, array $tags, ?int $actorId): void
    {
        $log  = new ActivityLog();
        $byKey = [];
        if ($tags !== []) {
            foreach ($this->db->table(self::TABLE)->whereIn('tag_key', array_map('strval', array_keys($tags)))->get()->getResultArray() as $row) {
                $byKey[$row['tag_key']] = $row;
            }
        }
        foreach ($tags as $key => $name) {
            $key = (string) $key;
            if (! isset($byKey[$key])) {
                $row = ['public_id' => $this->newPublicId(), 'tag_name' => $name, 'tag_key' => $key];
                $this->db->table(self::TABLE)->insert($row);
                $row['id'] = (int) $this->db->insertID();
                $log->inserted($actorId, 'Made the tag ' . $name, self::TABLE, $row['id'], $row);
                $byKey[$key] = $row;
            }
        }

        $wanted = [];
        foreach ($tags as $key => $name) {
            $wanted[(int) $byKey[(string) $key]['id']] = $byKey[(string) $key]['tag_name'];
        }
        $have = $this->db->table(self::BUSINESS_TABLE . ' bt')->select('bt.tag_id, t.tag_name')
            ->join(self::TABLE . ' t', 't.id = bt.tag_id')
            ->where('bt.business_id', $businessId)->get()->getResultArray();
        foreach ($have as $row) {
            $tagId = (int) $row['tag_id'];
            if (isset($wanted[$tagId])) {
                unset($wanted[$tagId]);
            } else {
                $this->db->table(self::BUSINESS_TABLE)->where('business_id', $businessId)->where('tag_id', $tagId)->delete();
                $log->deleted($actorId, 'Took the tag ' . $row['tag_name'] . ' off business ' . $businessId, self::BUSINESS_TABLE, null,
                    ['business_id' => $businessId, 'tag_id' => $tagId]);
            }
        }
        foreach ($wanted as $tagId => $name) {
            $row = ['business_id' => $businessId, 'tag_id' => $tagId];
            $this->db->table(self::BUSINESS_TABLE)->insert($row);
            $log->inserted($actorId, 'Gave business ' . $businessId . ' the tag ' . $name, self::BUSINESS_TABLE, null, $row);
        }
    }
}
