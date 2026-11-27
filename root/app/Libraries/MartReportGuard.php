<?php

namespace App\Libraries;

use App\Models\BusinessMasterModel;
use Config\MartReportForm;

/**
 * Keeps bots, floods and bad-faith reports out of the report form of otternova_mart (moderation_report), with the
 * layers of BusinessMessageGuard (form token, honeypot, counts per IP address, email address and business, duplicates,
 * content checks) and the counts of Config\MartReportForm, and one more: audienceReason(), the check of a report
 * against what the business declared about its audience.
 * A guard is for one business: service('martReportGuard')->forBusiness($id). Its form tokens are signed for reports
 * of that business, so neither a contact form's token nor another shop's works. The cache keys start with brpt_.
 */
class MartReportGuard extends BusinessMessageGuard
{
    /** The audiences a business declares, the keys of Config\MartReportForm::$audienceKeywords */
    public const AUDIENCE_LGBT     = 'lgbt';
    public const AUDIENCE_NATURIST = 'naturist';
    public const AUDIENCE_ADULT    = 'adult';

    /**
     * The audiences a business declared: LGBT-friendly or for LGBT people, naturist, adults only
     * @param array $business business_master's lgbt_audience, naturist_audience and age_restricted
     * @return list<string> AUDIENCE_ constants
     */
    public static function audiencesOf(array $business): array
    {
        $audiences = [];
        if (($business['lgbt_audience'] ?? BusinessMasterModel::LGBT_GENERAL) !== BusinessMasterModel::LGBT_GENERAL) {
            $audiences[] = self::AUDIENCE_LGBT;
        }
        if (($business['naturist_audience'] ?? BusinessMasterModel::NATURIST_GENERAL) !== BusinessMasterModel::NATURIST_GENERAL) {
            $audiences[] = self::AUDIENCE_NATURIST;
        }
        if (($business['age_restricted'] ?? BusinessMasterModel::AGE_GENERAL) !== BusinessMasterModel::AGE_GENERAL) {
            $audiences[] = self::AUDIENCE_ADULT;
        }

        return $audiences;
    }

    /**
     * Whether a report is about an audience the business declared, by the words of Config\MartReportForm::$audienceKeywords
     * @param list<string> $audiences audiencesOf()
     * @param string       $text      What the visitor wrote
     * @return string|null The audience it is about, or null
     */
    public function audienceReason(array $audiences, string $text): ?string
    {
        /** @var MartReportForm $config */
        $config = $this->config;
        $text   = mb_strtolower($text, 'UTF-8');
        foreach ($audiences as $audience) {
            foreach ($config->audienceKeywords[$audience] ?? [] as $word) {
                if (self::mentions($text, mb_strtolower($word, 'UTF-8'))) {
                    return $audience;
                }
            }
        }

        return null;
    }

    /**
     * A word in Latin letters is matched as a whole word, with s or es after it ("gays", but not "Gaylord" nor
     * "transport"); one in another script anywhere, as Thai, Chinese and Japanese leave no spaces between words
     */
    private static function mentions(string $text, string $word): bool
    {
        if (preg_match('/\p{Thai}|\p{Han}|\p{Hiragana}|\p{Katakana}/u', $word)) {
            return str_contains($text, $word);
        }

        return preg_match('~(?<![\p{L}\p{N}])' . preg_quote($word, '~') . '(?:e?s)?(?![\p{L}\p{N}])~u', $text) === 1;
    }

    protected function tokenPurpose(): string
    {
        return 'mart-report:' . parent::tokenPurpose();
    }

    protected function keyPrefix(): string
    {
        return 'brpt' . substr(parent::keyPrefix(), 4); // bmsg_<id> -> brpt_<id>
    }

    /**
     * The count per IP address across all businesses has no business in its key; its prefix is this guard's too
     */
    protected function key(string $scope, string $value): string
    {
        return $scope === 'all_ip'
            ? 'brpt_' . $scope . '_' . hash('sha256', strtolower(trim($value)))
            : parent::key($scope, $value);
    }
}
