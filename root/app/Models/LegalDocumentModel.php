<?php

namespace App\Models;

use App\Libraries\ActivityLog;
use CodeIgniter\Model;
use InvalidArgumentException;
use Throwable;

/**
 * The versions of the Terms of Service and the Privacy Policy (legal_document_version, with their texts in
 * legal_document_text) and who accepted which (legal_acceptance); see the CreateLegalDocumentTables migration.
 * A version is current from its effective_from until the next version of the same audience and type takes effect.
 */
class LegalDocumentModel extends Model
{
    use PublicIdTrait;
    use TransactionTrait;

    /** audience */
    public const AUDIENCE_BUSINESS = 'B'; // the Client app's accounts (auth_master)
    public const AUDIENCE_MART     = 'M'; // the marketplace's customers (customer_master)
    public const AUDIENCES         = [self::AUDIENCE_BUSINESS, self::AUDIENCE_MART];

    /** Who accepts each audience's documents: the legal_acceptance column that holds them */
    private const AUDIENCE_COLUMNS = [self::AUDIENCE_BUSINESS => 'auth_id', self::AUDIENCE_MART => 'customer_id'];

    /** document_type, in the order they are listed */
    public const TERMS   = 'T';
    public const PRIVACY = 'P';
    public const TYPES   = [self::TERMS, self::PRIVACY];

    /** legal_acceptance.context */
    public const CONTEXT_SIGN_UP    = 'S';
    public const CONTEXT_INVITATION = 'I';
    public const CONTEXT_REACCEPT   = 'R';

    /** The language a version is shown in when it has no text in the one asked for */
    public const FALLBACK_LOCALE = 'en-US';

    /** legal_acceptance.user_agent length */
    private const USER_AGENT_MAX_LENGTH = 512;

    protected $table            = 'legal_document_version';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = ['public_id', 'audience', 'document_type', 'version_label', 'effective_from', 'requires_reacceptance', 'created_by_acc'];
    protected $useTimestamps    = false; // created_at is maintained by the database

    /** public_id: CHAR(8), see PublicIdTrait */
    protected int $publicIdLength = 8;
    protected $beforeInsert       = ['fillPublicId'];

    /**
     * The version of each document type in effect at $at, keyed by document_type; a type with no version yet is left out
     * @param string      $audience
     * @param string|null $at UTC, Y-m-d H:i:s; null for now
     * @return array<string, array> legal_document_version rows
     */
    public function currentVersions(string $audience, ?string $at = null): array
    {
        $at      = $at ?? gmdate('Y-m-d H:i:s');
        $current = [];
        foreach (self::TYPES as $type) {
            $row = $this->db->table($this->table)
                ->where('audience', $audience)
                ->where('document_type', $type)
                ->where('effective_from <=', $at)
                ->orderBy('effective_from', 'DESC')
                ->orderBy('id', 'DESC')
                ->limit(1)
                ->get()
                ->getRowArray();
            if ($row !== null) {
                $current[$type] = $row;
            }
        }

        return $current;
    }

    /**
     * The current versions as the apps show them, with the text in $locale (or the fallback language)
     * @param string $audience
     * @param string $locale
     * @return list<array> toPayload() with body and body_sha256
     */
    public function currentWithText(string $audience, string $locale): array
    {
        $documents = [];
        foreach ($this->currentVersions($audience) as $version) {
            $documents[] = $this->toPayload($version, $this->textOf((int) $version['id'], $locale), true);
        }

        return $documents;
    }

    /**
     * The Client app's current versions an account has still to accept; see pendingFor()
     * @param int    $authId
     * @param string $locale For change_summary
     * @return list<array> toPayload() without the body
     */
    public function pendingForAccount(int $authId, string $locale): array
    {
        return $this->pendingFor(self::AUDIENCE_BUSINESS, $authId, $locale);
    }

    /**
     * The marketplace's current versions a customer has still to accept; see pendingFor()
     * @param int    $customerId customer_master.id
     * @param string $locale
     * @return list<array> toPayload() without the body
     */
    public function pendingForCustomer(int $customerId, string $locale): array
    {
        return $this->pendingFor(self::AUDIENCE_MART, $customerId, $locale);
    }

    /**
     * The current versions of an audience someone has still to accept: a document type they never accepted a version
     * of, or one that has had a version that requires re-acceptance take effect since the latest version they accepted
     * @param string $audience AUDIENCE_*: B for an account (auth_master.id), M for a customer (customer_master.id)
     * @param int    $whoId
     * @param string $locale   For change_summary
     * @return list<array> toPayload() without the body
     */
    public function pendingFor(string $audience, int $whoId, string $locale): array
    {
        $column  = self::AUDIENCE_COLUMNS[$audience] ?? throw new InvalidArgumentException('Unknown audience ' . $audience);
        $now     = gmdate('Y-m-d H:i:s');
        $pending = [];
        foreach ($this->currentVersions($audience, $now) as $type => $current) {
            $accepted = $this->db->table('legal_acceptance a')
                ->select('MAX(v.effective_from) AS effective_from')
                ->join($this->table . ' v', 'v.id = a.version_id')
                ->where('a.' . $column, $whoId)
                ->where('v.audience', $audience)
                ->where('v.document_type', $type)
                ->get()
                ->getRowArray()['effective_from'] ?? null;
            if ($accepted !== null && $accepted >= $current['effective_from']) {
                continue;
            }
            if ($accepted !== null) {
                $material = $this->db->table($this->table)
                    ->where('audience', $audience)
                    ->where('document_type', $type)
                    ->where('requires_reacceptance', 'Y')
                    ->where('effective_from >', $accepted)
                    ->where('effective_from <=', $now)
                    ->countAllResults();
                if ($material === 0) {
                    continue; // only minor changes since: continuing to use the service is enough
                }
            }
            $pending[] = $this->toPayload($current, $this->textOf((int) $current['id'], $locale), false);
        }

        return $pending;
    }

    /**
     * The current versions when $publicIds names exactly them, else null: a page shown before a new version took
     * effect, or tampered with, must not count as accepting it
     * @param string $audience
     * @param mixed  $publicIds From the request
     * @return list<array>|null legal_document_version rows
     */
    public function matchCurrent(string $audience, mixed $publicIds): ?array
    {
        $current = array_values($this->currentVersions($audience));
        $given   = is_array($publicIds) ? array_values(array_unique(array_filter($publicIds, 'is_string'))) : [];
        $ids     = array_column($current, 'public_id');
        sort($ids);
        sort($given);

        return $ids === $given ? $current : null;
    }

    /**
     * Record that someone accepted these versions; a version they accepted already is skipped. Logged to log_activity.
     * Runs in its own transaction, or in the caller's when there is one.
     * @param list<array> $versions legal_document_version rows
     * @param array       $who      ['auth_id' => int] or ['customer_id' => int]
     * @param string      $locale   The language the texts were shown in
     * @param string      $context  CONTEXT_*
     * @param string      $ip
     * @param string|null $userAgent
     * @return int The rows added
     * @throws Throwable When a write fails; nothing is written then
     */
    public function recordAcceptances(array $versions, array $who, string $locale, string $context, string $ip, ?string $userAgent): int
    {
        $who = array_filter(array_intersect_key($who, ['auth_id' => true, 'customer_id' => true]), static fn ($id) => is_int($id) && $id > 0);
        if (count($who) !== 1) {
            throw new InvalidArgumentException('An acceptance is by exactly one account or customer');
        }
        $column  = array_key_first($who);
        $actorId = $column === 'auth_id' ? $who['auth_id'] : null;
        $log     = new ActivityLog();
        $agent   = $userAgent === null || $userAgent === '' ? null : mb_substr($userAgent, 0, self::USER_AGENT_MAX_LENGTH, 'UTF-8');

        return $this->inTransaction(function () use ($versions, $who, $column, $actorId, $locale, $context, $ip, $agent, $log) {
            $added = 0;
            foreach ($versions as $version) {
                $acceptances = $this->db->table('legal_acceptance');
                if ($acceptances->where($column, $who[$column])->where('version_id', $version['id'])->countAllResults() > 0) {
                    continue;
                }
                $row = $who + [
                    'version_id' => (int) $version['id'],
                    'locale'     => mb_substr($locale, 0, 16),
                    'context'    => $context,
                    'ip_address' => mb_substr($ip, 0, 45),
                    'user_agent' => $agent,
                ];
                $this->db->table('legal_acceptance')->insert($row);
                $log->inserted($actorId, 'Accepted ' . $this->describe($version), 'legal_acceptance', (int) $this->db->insertID(), $row);
                $added++;
            }

            return $added;
        });
    }

    // ---- the website's pages ----

    /**
     * Every version of a document that is or was in effect at $at, newest first: the list of earlier versions the
     * website links to. Scheduled ones are left out until they take effect.
     * @param string      $audience
     * @param string      $type
     * @param string|null $at UTC; null for now
     * @return list<array{public_id: string, version_label: string, effective_from: string}>
     */
    public function publishedVersions(string $audience, string $type, ?string $at = null): array
    {
        return $this->db->table($this->table)
            ->select('public_id, version_label, effective_from')
            ->where('audience', $audience)
            ->where('document_type', $type)
            ->where('effective_from <=', $at ?? gmdate('Y-m-d H:i:s'))
            ->orderBy('effective_from', 'DESC')
            ->orderBy('id', 'DESC')
            ->get()
            ->getResultArray();
    }

    /**
     * One version of a document by its label, when it is or was in effect at $at
     * @param string      $audience
     * @param string      $type
     * @param string      $label
     * @param string|null $at UTC; null for now
     * @return array|null legal_document_version row
     */
    public function publishedByLabel(string $audience, string $type, string $label, ?string $at = null): ?array
    {
        return $this->db->table($this->table)
            ->where('audience', $audience)
            ->where('document_type', $type)
            ->where('version_label', $label)
            ->where('effective_from <=', $at ?? gmdate('Y-m-d H:i:s'))
            ->get()
            ->getRowArray();
    }

    // ---- the Admin app ----

    /** State of a version at a moment, as the staff list sends it */
    public const STATE_SCHEDULED = 'S';
    public const STATE_CURRENT   = 'C';
    public const STATE_ENDED     = 'E';

    /** version_label: what people see, such as 2026-12-01 or 2026-12-01a */
    public const LABEL_PATTERN = '/^[0-9A-Za-z][0-9A-Za-z._-]{0,31}$/';

    /** Longest text and change summary a version may have, in bytes and characters */
    public const BODY_MAX_BYTES     = 200000;
    public const SUMMARY_MAX_LENGTH = 2000;

    /**
     * Every version for the staff list: newest effective_from first, with its state at $at, the languages it has
     * and how many people accepted it
     * @param string $at UTC
     * @return list<array>
     */
    public function listVersions(string $at): array
    {
        $versions = $this->db->table($this->table . ' v')
            ->select('v.public_id, v.id, v.audience, v.document_type, v.version_label, v.effective_from, v.requires_reacceptance, v.created_at, a.full_name AS created_by')
            ->join('auth_master a', 'a.id = v.created_by_acc', 'left')
            ->orderBy('v.effective_from', 'DESC')
            ->orderBy('v.id', 'DESC')
            ->get()
            ->getResultArray();
        $locales = [];
        foreach ($this->db->table('legal_document_text')->select('version_id, locale')->orderBy('locale')->get()->getResultArray() as $row) {
            $locales[(int) $row['version_id']][] = $row['locale'];
        }
        $counts = array_column($this->db->table('legal_acceptance')->select('version_id, COUNT(*) AS n')->groupBy('version_id')->get()->getResultArray(), 'n', 'version_id');
        $current = [];
        foreach (self::AUDIENCES as $audience) {
            foreach ($this->currentVersions($audience, $at) as $version) {
                $current[(int) $version['id']] = true;
            }
        }

        return array_map(static function (array $row) use ($locales, $counts, $current, $at): array {
            $id = (int) $row['id'];
            unset($row['id']);

            return $row + [
                'state'       => isset($current[$id]) ? self::STATE_CURRENT : ($row['effective_from'] > $at ? self::STATE_SCHEDULED : self::STATE_ENDED),
                'locales'     => $locales[$id] ?? [],
                'acceptances' => (int) ($counts[$id] ?? 0),
            ];
        }, $versions);
    }

    /**
     * One version with every text it has, for the staff view
     * @param string $publicId
     * @param string $at UTC
     * @return array|null The listVersions() row with texts: [{locale, body, body_sha256, change_summary}]
     */
    public function versionForStaff(string $publicId, string $at): ?array
    {
        $row = array_column($this->listVersions($at), null, 'public_id')[$publicId] ?? null;
        if ($row === null) {
            return null;
        }
        $version      = $this->findByPublicId($publicId);
        $row['texts'] = $this->db->table('legal_document_text')
            ->select('locale, body, body_sha256, change_summary')
            ->where('version_id', $version['id'])
            ->orderBy('locale')
            ->get()
            ->getResultArray();

        return $row;
    }

    /**
     * The latest effective_from of a document, scheduled versions included; a new version must come after it
     * @param string $audience
     * @param string $type
     * @return string|null UTC
     */
    public function latestEffectiveFrom(string $audience, string $type): ?string
    {
        return $this->db->table($this->table)
            ->selectMax('effective_from')
            ->where('audience', $audience)
            ->where('document_type', $type)
            ->get()
            ->getRow()->effective_from ?? null;
    }

    /**
     * Publish a new version with its texts, in one transaction, logged to log_activity in it
     * @param array                $version  Checked audience, document_type, version_label, effective_from, requires_reacceptance
     * @param array<string, array> $texts    locale => [body, change_summary], checked
     * @param int                  $actorId  The staff member publishing it
     * @return string The new version's public_id
     * @throws Throwable When a write fails (such as the label being taken meanwhile); nothing is written then
     */
    public function publish(array $version, array $texts, int $actorId): string
    {
        $log = new ActivityLog();

        return $this->withPublicId(fn (string $publicId) => $this->inTransaction(function () use ($version, $texts, $actorId, $publicId, $log) {
            $row = ['public_id' => $publicId] + $version + ['created_by_acc' => $actorId];
            $this->db->table($this->table)->insert($row);
            $id = (int) $this->db->insertID();
            $log->inserted($actorId, 'Published ' . $this->describe($row), $this->table, $id, $row);
            foreach ($texts as $locale => $text) {
                $textRow = [
                    'version_id'     => $id,
                    'locale'         => $locale,
                    'body'           => $text['body'],
                    'body_sha256'    => hash('sha256', $text['body']),
                    'change_summary' => $text['change_summary'] === '' ? null : $text['change_summary'],
                ];
                $this->db->table('legal_document_text')->insert($textRow);
                // the body itself is in the table; its hash says which one it was
                $log->inserted($actorId, 'Added the ' . $locale . ' text of ' . $this->describe($row), 'legal_document_text', (int) $this->db->insertID(),
                    ['body' => null] + $textRow);
            }

            return $publicId;
        }));
    }

    /**
     * Take back a version that has not taken effect yet (so nobody can have accepted it), with its texts
     * @param array  $version legal_document_version row
     * @param int    $actorId
     * @return void
     * @throws Throwable
     */
    public function cancelScheduled(array $version, int $actorId): void
    {
        $log = new ActivityLog();
        $this->inTransaction(function () use ($version, $actorId, $log) {
            $this->db->table('legal_document_text')->where('version_id', $version['id'])->delete();
            $this->db->table($this->table)->where('id', $version['id'])->delete();
            $log->deleted($actorId, 'Cancelled ' . $this->describe($version), $this->table, (int) $version['id'], $version);
        });
    }

    /**
     * One page of acceptances for the staff list, newest first
     * @param int         $start
     * @param int         $length
     * @param int|null    $versionId Only this version's
     * @param string      $search    Matches the email address, the name and the IP address
     * @param string|null $audience  Only this audience's (AUDIENCE_*)
     * @return array{records_total: int, records_filtered: int, rows: list<array>}
     */
    public function acceptancesForStaff(int $start, int $length, ?int $versionId, string $search, ?string $audience = null): array
    {
        $builder = $this->db->table('legal_acceptance l')
            ->select('l.id, l.locale, l.context, l.ip_address, l.user_agent, l.accepted_at, v.public_id AS version_public_id, v.document_type, v.version_label, v.audience,'
                . ' a.public_id AS account_public_id, a.email_address AS account_email, a.full_name AS account_name,'
                . ' c.public_id AS customer_public_id, c.email_address AS customer_email, c.customer_name')
            ->join($this->table . ' v', 'v.id = l.version_id')
            ->join('auth_master a', 'a.id = l.auth_id', 'left')
            ->join('customer_master c', 'c.id = l.customer_id', 'left');
        if ($versionId !== null) {
            $builder->where('l.version_id', $versionId);
        }
        if ($audience !== null) {
            $builder->where('v.audience', $audience);
        }
        $total = $builder->countAllResults(false);
        if ($search !== '') {
            $char = $this->db->likeEscapeChar;
            $term = str_replace([$char, '%', '_'], [$char . $char, $char . '%', $char . '_'], $search);
            $builder->groupStart()
                ->like('a.email_address', $term, 'both', null, true)
                ->orLike('a.full_name', $term, 'both', null, true)
                ->orLike('c.email_address', $term, 'both', null, true)
                ->orLike('c.customer_name', $term, 'both', null, true)
                ->orLike('l.ip_address', $term, 'both', null, true)
                ->groupEnd();
        }
        $filtered = $search === '' ? $total : $builder->countAllResults(false);
        $rows     = $builder->orderBy('l.id', 'DESC')->limit($length, $start)->get()->getResultArray();

        return ['records_total' => $total, 'records_filtered' => $filtered, 'rows' => array_map(static fn (array $row): array => [
            'id'                => (int) $row['id'],
            'accepted_at'       => $row['accepted_at'],
            'document_type'     => $row['document_type'],
            'version_label'     => $row['version_label'],
            'version_public_id' => $row['version_public_id'],
            'audience'          => $row['audience'],
            'who_type'          => $row['account_public_id'] !== null ? 'account' : 'customer',
            'who_public_id'     => $row['account_public_id'] ?? $row['customer_public_id'],
            'who_email'         => $row['account_email'] ?? $row['customer_email'],
            'who_name'          => $row['account_name'] ?? $row['customer_name'],
            'locale'            => $row['locale'],
            'context'           => $row['context'],
            'ip_address'        => $row['ip_address'],
            'user_agent'        => $row['user_agent'],
        ], $rows)];
    }

    /**
     * The text of a version in $locale, else in the fallback language, else in any it has
     * @param int    $versionId
     * @param string $locale
     * @return array|null legal_document_text row
     */
    public function textOf(int $versionId, string $locale): ?array
    {
        $texts = $this->db->table('legal_document_text')->where('version_id', $versionId)->get()->getResultArray();
        $by    = array_column($texts, null, 'locale');

        return $by[$locale] ?? $by[self::FALLBACK_LOCALE] ?? ($texts[0] ?? null);
    }

    /**
     * A version as the API sends it
     * @param array      $version legal_document_version row
     * @param array|null $text    legal_document_text row
     * @param bool       $withBody
     * @return array
     */
    public function toPayload(array $version, ?array $text, bool $withBody): array
    {
        $payload = [
            'public_id'             => $version['public_id'],
            'audience'              => $version['audience'],
            'document_type'         => $version['document_type'],
            'version_label'         => $version['version_label'],
            'effective_from'        => $version['effective_from'],
            'requires_reacceptance' => $version['requires_reacceptance'],
            'locale'                => $text['locale'] ?? null,
            'change_summary'        => $text['change_summary'] ?? null,
        ];
        if ($withBody) {
            $payload['body']        = $text['body'] ?? null;
            $payload['body_sha256'] = $text['body_sha256'] ?? null;
        }

        return $payload;
    }

    /**
     * "Terms of Service 2025-12-01" (the Client app's) or "marketplace Terms of Service 2025-12-01", for the activity log
     * @param array $version
     * @return string
     */
    private function describe(array $version): string
    {
        return ($version['audience'] === self::AUDIENCE_MART ? 'marketplace ' : '')
            . ($version['document_type'] === self::TERMS ? 'Terms of Service ' : 'Privacy Policy ') . $version['version_label'];
    }
}
