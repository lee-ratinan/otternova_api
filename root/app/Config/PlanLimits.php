<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * What a business may have or use on each plan, by plan_master.plan_code. The plan a business is on now is
 * SubscriptionMasterModel::planOfBusiness() (free unless its subscription is active); forPlan() gives its limits.
 * A plan_code with no entry here (a plan staff added later) gets the free plan's limits until one is added, so a new
 * plan never opens more than it should.
 *
 * Caps are the most rows the business may have at once; reaching one refuses the next create, never touches what is
 * there already (a business that moves to a smaller plan keeps its rows, but cannot add more until it is under it):
 * - STORES:    business_store rows of the business
 * - EMPLOYEES: active business_client_link rows of the business, its owners included
 * - PRODUCTS:  artifact_master rows of artifact_type P; 0 means the plan cannot sell products at all
 * - SERVICES:  artifact_master rows of artifact_type S
 * - VARIANTS:  artifact_variant rows of artifact_master
 * - WEBPAGES:  pages of its own design
 * - IMAGE_PER_ARTIFACT: artifact_image rows per 1 artifact_master
 * - IMAGE_PER_WEBPAGE: (future) number of images per 1 webpage
 * Monthly allowances, not caps on rows: how many times a month the business may ask the AI to generate, counted
 * separately for each field it generates into:
 * - AI_CREDIT_TEXT_ATTEMPTS: text generations (a name, a description) per field
 * - AI_CREDIT_IMG_ATTEMPTS:  image generations per field
 * The features are on (true) or off (false):
 * - PRIVATE_SESSION_BOOKING: a customer books a service session for themself alone
 * - SESSION_REGISTRATION:    the business opens a session (a class) and customers register to join it
 */
class PlanLimits extends BaseConfig
{
    public const STORES                  = 'stores';
    public const EMPLOYEES               = 'employees';
    public const PRODUCTS                = 'products';
    public const SERVICES                = 'services';
    public const VARIANTS                = 'variants';
    public const WEBPAGES                = 'webpages';
    public const IMAGE_PER_ARTIFACT      = 'image_per_artifact';
    public const IMAGE_PER_WEBPAGE       = 'image_per_webpage';
    public const AI_CREDIT_TEXT_ATTEMPTS = 'ai_credit_text_attempts';
    public const AI_CREDIT_IMG_ATTEMPTS  = 'ai_credit_image_attempts';
    public const PRIVATE_SESSION_BOOKING = 'private_session_booking';
    public const SESSION_REGISTRATION    = 'session_registration';

    /** The cap that counts artifacts of each artifact_master.artifact_type */
    public const ARTIFACT_TYPE_CAPS = ['P' => self::PRODUCTS, 'S' => self::SERVICES];

    /** The plan whose limits a plan_code with no entry gets */
    public string $fallbackPlan = 'free';

    /**
     * plan_code => limits
     * @var array<string, array{stores: int, employees: int, products: int, services: int, variants: int, webpages: int, image_per_artifact: int, image_per_webpage: int, ai_credit_text_attempts: int, ai_credit_image_attempts: int, private_session_booking: bool, session_registration: bool}>
     */
    public array $plans = [
        'free'     => [
            self::STORES                  => 1,
            self::EMPLOYEES               => 1,
            self::PRODUCTS                => 0,
            self::SERVICES                => 1,
            self::VARIANTS                => 2,
            self::WEBPAGES                => 0,
            self::IMAGE_PER_ARTIFACT      => 1,
            self::IMAGE_PER_WEBPAGE       => 0,
            self::AI_CREDIT_TEXT_ATTEMPTS => 0,
            self::AI_CREDIT_IMG_ATTEMPTS  => 0,
            self::PRIVATE_SESSION_BOOKING => true,
            self::SESSION_REGISTRATION    => false,
        ],
        'boost'    => [
            self::STORES                  => 2,
            self::EMPLOYEES               => 3,
            self::PRODUCTS                => 5,
            self::SERVICES                => 5,
            self::VARIANTS                => 10,
            self::WEBPAGES                => 1,
            self::IMAGE_PER_ARTIFACT      => 3,
            self::IMAGE_PER_WEBPAGE       => 3,
            self::AI_CREDIT_TEXT_ATTEMPTS => 2,
            self::AI_CREDIT_IMG_ATTEMPTS  => 2,
            self::PRIVATE_SESSION_BOOKING => true,
            self::SESSION_REGISTRATION    => false,
        ],
        'velocity' => [
            self::STORES                  => 4,
            self::EMPLOYEES               => 5,
            self::PRODUCTS                => 20,
            self::SERVICES                => 20,
            self::VARIANTS                => 15,
            self::WEBPAGES                => 3,
            self::IMAGE_PER_ARTIFACT      => 5,
            self::IMAGE_PER_WEBPAGE       => 5,
            self::AI_CREDIT_TEXT_ATTEMPTS => 3,
            self::AI_CREDIT_IMG_ATTEMPTS  => 3,
            self::PRIVATE_SESSION_BOOKING => true,
            self::SESSION_REGISTRATION    => true,
        ],
        'quantum'  => [
            self::STORES                  => 8,
            self::EMPLOYEES               => 15,
            self::PRODUCTS                => 50,
            self::SERVICES                => 50,
            self::VARIANTS                => 30,
            self::WEBPAGES                => 5,
            self::IMAGE_PER_ARTIFACT      => 10,
            self::IMAGE_PER_WEBPAGE       => 10,
            self::AI_CREDIT_TEXT_ATTEMPTS => 4,
            self::AI_CREDIT_IMG_ATTEMPTS  => 4,
            self::PRIVATE_SESSION_BOOKING => true,
            self::SESSION_REGISTRATION    => true,
        ],
    ];

    /**
     * The limits of a plan, the fallback plan's when it has no entry
     * @param string $planCode plan_master.plan_code
     * @return array{stores: int, employees: int, products: int, services: int, variants: int, webpages: int, image_per_artifact: int, image_per_webpage: int, ai_credit_text_attempts: int, ai_credit_image_attempts: int, private_session_booking: bool, session_registration: bool}
     */
    public function forPlan(string $planCode): array
    {
        return $this->plans[$planCode] ?? $this->plans[$this->fallbackPlan];
    }

    /**
     * One limit of a plan: a cap (int) or a feature (bool)
     * @param string $planCode
     * @param string $limit    One of the constants above
     * @return int|bool
     */
    public function limitOf(string $planCode, string $limit): int|bool
    {
        return $this->forPlan($planCode)[$limit];
    }
}
