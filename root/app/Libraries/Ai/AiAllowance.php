<?php

namespace App\Libraries\Ai;

use Config\PlanLimits;

/**
 * How much of a plan's AI allowance one target (an artifact, a page) has left for one feature: the successful
 * attempts in the last Config\PlanLimits::$aiCreditWindowDays days (rolling; each comes back that many days after it
 * was used) against the plan's limit, e.g. PlanLimits::AI_CREDIT_TEXT_ATTEMPTS. Attempts are counted from ai_usage_log
 * through service('ai'), so only those of the driver in use count.
 */
final class AiAllowance
{
    /**
     * @param AiUsageContext $context The artifact or page, as the feature logs its calls
     * @param string         $feature AiRequest::$feature
     * @param string         $plan    plan_code of the business
     * @param string         $limit   PlanLimits::AI_CREDIT_TEXT_ATTEMPTS or AI_CREDIT_IMG_ATTEMPTS
     * @return array{max: int, count: int, can_add: bool, window_days: int}
     */
    public static function of(AiUsageContext $context, string $feature, string $plan, string $limit = PlanLimits::AI_CREDIT_TEXT_ATTEMPTS): array
    {
        $limits = config(PlanLimits::class);
        $days   = max(1, $limits->aiCreditWindowDays);
        $max    = (int) $limits->limitOf($plan, $limit);
        $count  = service('ai')->attemptsUsed($context, $feature, gmdate('Y-m-d H:i:s', time() - $days * 86400));

        return ['max' => $max, 'count' => $count, 'can_add' => $count < $max, 'window_days' => $days];
    }

    /**
     * The allowance after one more attempt
     * @param array{max: int, count: int, can_add: bool, window_days: int} $allowance
     * @return array{max: int, count: int, can_add: bool, window_days: int}
     */
    public static function afterOne(array $allowance): array
    {
        return array_replace($allowance, ['count' => $allowance['count'] + 1, 'can_add' => $allowance['count'] + 1 < $allowance['max']]);
    }
}
