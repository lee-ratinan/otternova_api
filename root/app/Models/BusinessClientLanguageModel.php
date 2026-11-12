<?php

namespace App\Models;

use App\Libraries\ActivityLog;
use CodeIgniter\Model;
use Throwable;

/**
 * Model for the business_client_language table: the languages each person of a business speaks, and how well.
 * language_code is any BCP 47 tag, not only one of listLanguages().
 */
class BusinessClientLanguageModel extends Model
{
    use TransactionTrait;

    /** proficiency: native, fluent, conversational, basic, from the best */
    public const PROFICIENCIES = ['N', 'F', 'C', 'B'];

    protected $table            = 'business_client_language';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $protectFields    = true;
    protected $allowedFields    = ['business_id', 'client_id', 'language_code', 'proficiency'];

    // created_at and updated_at are maintained by the database
    protected $useTimestamps = false;

    /**
     * A BCP 47 tag of a spoken language in its usual case (language, optional script, optional region):
     * "JA" => "ja", "zh-hant-tw" => "zh-Hant-TW", "nan-tw" => "nan-TW"
     * @param mixed $code
     * @return string|null null when it is not one
     */
    public static function normalizeCode(mixed $code): ?string
    {
        if (! is_string($code) || preg_match('/^([a-z]{2,3})(?:-([a-z]{4}))?(?:-([a-z]{2}|\d{3}))?$/i', trim($code), $m) !== 1) {
            return null;
        }
        $tag = strtolower($m[1]);
        if (($m[2] ?? '') !== '') {
            $tag .= '-' . ucfirst(strtolower($m[2]));
        }
        if (($m[3] ?? '') !== '') {
            $tag .= '-' . strtoupper($m[3]);
        }

        return $tag;
    }

    /**
     * @param int $businessId
     * @param int $clientId
     * @return list<array{language_code: string, proficiency: string}> The best spoken first, then by code
     */
    public function languagesOf(int $businessId, int $clientId): array
    {
        $rows = $this->db->table($this->table)->select('language_code, proficiency')
            ->where('business_id', $businessId)->where('client_id', $clientId)
            ->get()->getResultArray();
        $rank = array_flip(self::PROFICIENCIES);
        usort($rows, static fn (array $a, array $b): int => [$rank[$a['proficiency']], $a['language_code']] <=> [$rank[$b['proficiency']], $b['language_code']]);

        return $rows;
    }

    /**
     * Make the person speak exactly $languages, in one transaction, logged to log_activity in it: the ones left out
     * are deleted, the others added or their proficiency changed
     * @param int                   $businessId
     * @param int                   $clientId
     * @param array<string, string> $languages language_code (normalized) => proficiency
     * @param int                   $actorId
     * @param string                $uid       auth_master.uid of the person, for the log
     * @return void
     * @throws Throwable When a write fails; nothing is written then
     */
    public function replaceLanguages(int $businessId, int $clientId, array $languages, int $actorId, string $uid): void
    {
        $log = new ActivityLog();
        $this->inTransaction(function () use ($businessId, $clientId, $languages, $actorId, $uid, $log) {
            $rows = $this->db->table($this->table)->where('business_id', $businessId)->where('client_id', $clientId)->get()->getResultArray();
            $have = [];
            foreach ($rows as $row) {
                $code        = $row['language_code'];
                $have[$code] = true;
                if (! isset($languages[$code])) {
                    $this->db->table($this->table)->where('id', $row['id'])->delete();
                    $log->deleted($actorId, 'Removed language ' . $code . ' of ' . $uid, $this->table, (int) $row['id'], $row);
                } elseif ($row['proficiency'] !== $languages[$code]) {
                    $this->db->table($this->table)->where('id', $row['id'])->update(['proficiency' => $languages[$code]]);
                    $log->updated($actorId, 'Changed how well ' . $uid . ' speaks ' . $code, $this->table, (int) $row['id'], $row, ['proficiency' => $languages[$code]]);
                }
            }
            foreach ($languages as $code => $proficiency) {
                if (! isset($have[$code])) {
                    $row = ['business_id' => $businessId, 'client_id' => $clientId, 'language_code' => $code, 'proficiency' => $proficiency];
                    $this->db->table($this->table)->insert($row);
                    $log->inserted($actorId, 'Added language ' . $code . ' to ' . $uid, $this->table, (int) $this->db->insertID(), $row);
                }
            }
        });
    }
}
