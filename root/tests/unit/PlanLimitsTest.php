<?php

use CodeIgniter\Test\CIUnitTestCase;
use Config\PlanLimits;

/**
 * Config\PlanLimits: every plan has every limit, of the right kind, and an unknown plan gets the free plan's
 *
 * @internal
 */
final class PlanLimitsTest extends CIUnitTestCase
{
    private const CAPS     = [PlanLimits::STORES, PlanLimits::EMPLOYEES, PlanLimits::PRODUCTS, PlanLimits::SERVICES, PlanLimits::WEBPAGES, PlanLimits::AI_CREDIT_TEXT_ATTEMPTS, PlanLimits::AI_CREDIT_IMG_ATTEMPTS, PlanLimits::IMAGE_PER_ARTIFACT, PlanLimits::IMAGE_PER_WEBPAGE];
    private const FEATURES = [PlanLimits::PRIVATE_SESSION_BOOKING, PlanLimits::SESSION_REGISTRATION];

    public function testEverySeededPlanHasEveryLimit(): void
    {
        $config = config(PlanLimits::class);
        foreach (['free', 'boost', 'velocity', 'quantum'] as $plan) {
            $limits = $config->plans[$plan] ?? null;
            $this->assertIsArray($limits, $plan);
            $this->assertEqualsCanonicalizing([...self::CAPS, ...self::FEATURES], array_keys($limits), $plan);
            foreach (self::CAPS as $cap) {
                $this->assertIsInt($limits[$cap], $plan . '.' . $cap);
                $this->assertGreaterThanOrEqual(0, $limits[$cap], $plan . '.' . $cap);
            }
            foreach (self::FEATURES as $feature) {
                $this->assertIsBool($limits[$feature], $plan . '.' . $feature);
            }
        }
    }

    public function testTheBiggerPlansNeverGiveLess(): void
    {
        $config = config(PlanLimits::class);
        $order  = ['free', 'boost', 'velocity', 'quantum'];
        for ($i = 1; $i < count($order); $i++) {
            foreach ([...self::CAPS, ...self::FEATURES] as $limit) {
                $this->assertGreaterThanOrEqual($config->plans[$order[$i - 1]][$limit], $config->plans[$order[$i]][$limit], $order[$i] . '.' . $limit);
            }
        }
    }

    public function testTheAgreedLimits(): void
    {
        $config = config(PlanLimits::class);

        $this->assertSame(0, $config->limitOf('free', PlanLimits::PRODUCTS)); // the free plan cannot sell products
        $this->assertSame(1, $config->limitOf('free', PlanLimits::SERVICES));
        $this->assertSame(15, $config->limitOf('quantum', PlanLimits::EMPLOYEES));
        $this->assertSame(0, $config->limitOf('free', PlanLimits::AI_CREDIT_TEXT_ATTEMPTS)); // no AI on the free plan
        $this->assertSame(3, $config->limitOf('velocity', PlanLimits::AI_CREDIT_TEXT_ATTEMPTS));
        $this->assertSame(4, $config->limitOf('quantum', PlanLimits::AI_CREDIT_IMG_ATTEMPTS));
        $this->assertTrue($config->limitOf('free', PlanLimits::PRIVATE_SESSION_BOOKING));
        $this->assertFalse($config->limitOf('boost', PlanLimits::SESSION_REGISTRATION));
        $this->assertTrue($config->limitOf('velocity', PlanLimits::SESSION_REGISTRATION));
        $this->assertSame(PlanLimits::PRODUCTS, PlanLimits::ARTIFACT_TYPE_CAPS['P']);
    }

    public function testAPlanWithNoEntryGetsTheFreePlansLimits(): void
    {
        $config = config(PlanLimits::class);

        $this->assertSame($config->plans['free'], $config->forPlan('enterprise'));
    }
}
