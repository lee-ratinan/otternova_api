<?php

namespace Config;

use App\Libraries\Ai\AiClient;
use App\Libraries\Ai\FakeAiClient;
use App\Libraries\Ai\GeminiClient;
use App\Libraries\Ai\RecordingAiClient;
use App\Models\AiUsageLogModel;
use App\Libraries\AuthContext;
use App\Libraries\BusinessMessageGuard;
use App\Libraries\ContactFormGuard;
use App\Libraries\EmailService;
use App\Libraries\ExchangeRates;
use App\Libraries\GeoLocator;
use App\Libraries\ImageProcessor;
use App\Libraries\JwtService;
use App\Libraries\LoginThrottle;
use App\Libraries\MartCache;
use App\Libraries\TrackerGuard;
use CodeIgniter\Config\BaseService;
use RuntimeException;

/**
 * Services Configuration file.
 *
 * Services are simply other classes/libraries that the system uses
 * to do its job. This is used by CodeIgniter to allow the core of the
 * framework to be swapped out easily without affecting the usage within
 * the rest of your application.
 *
 * This file holds any application-specific services, or service overrides
 * that you might need. An example has been included with the general
 * method format you should use for your service methods. For more examples,
 * see the core Services file at system/Config/Services.php.
 */
class Services extends BaseService
{
    /*
     * public static function example($getShared = true)
     * {
     *     if ($getShared) {
     *         return static::getSharedInstance('example');
     *     }
     *
     *     return new \CodeIgniter\Example();
     * }
     */

    public static function jwt(bool $getShared = true): JwtService
    {
        if ($getShared) {
            return static::getSharedInstance('jwt');
        }

        return new JwtService(config(Auth::class));
    }

    /**
     * The day's exchange rate for a ledger entry; see App\Libraries\ExchangeRates
     */
    public static function exchangeRates(bool $getShared = true): ExchangeRates
    {
        if ($getShared) {
            return static::getSharedInstance('exchangeRates');
        }

        return new ExchangeRates(db_connect(), config(\Config\ExchangeRate::class));
    }

    public static function emailService(bool $getShared = true): EmailService
    {
        if ($getShared) {
            return static::getSharedInstance('emailService');
        }

        return new EmailService();
    }

    public static function loginThrottle(bool $getShared = true): LoginThrottle
    {
        if ($getShared) {
            return static::getSharedInstance('loginThrottle');
        }

        return new LoginThrottle(service('cache'), config(Auth::class));
    }

    /**
     * The token, throttle and spam checks of the public contact form
     */
    public static function contactFormGuard(bool $getShared = true): ContactFormGuard
    {
        if ($getShared) {
            return static::getSharedInstance('contactFormGuard');
        }

        return new ContactFormGuard(service('cache'), config(ContactForm::class), config(Auth::class)->jwtSecret);
    }

    /**
     * The bot and flood checks of the contact form of each business on otternova_mart; use ->forBusiness($id)
     */
    public static function businessMessageGuard(bool $getShared = true): BusinessMessageGuard
    {
        if ($getShared) {
            return static::getSharedInstance('businessMessageGuard');
        }

        return new BusinessMessageGuard(service('cache'), config(BusinessMessageForm::class), config(Auth::class)->jwtSecret);
    }

    /**
     * The origin, bot and throttle checks of the visitor tracker
     */
    public static function trackerGuard(bool $getShared = true): TrackerGuard
    {
        if ($getShared) {
            return static::getSharedInstance('trackerGuard');
        }

        return new TrackerGuard(service('cache'), config(Tracker::class));
    }

    /**
     * The cache of the public mart endpoints
     */
    public static function martCache(bool $getShared = true): MartCache
    {
        if ($getShared) {
            return static::getSharedInstance('martCache');
        }

        return new MartCache(service('cache'), config(Mart::class));
    }

    /**
     * Where an IP address is, for the tracker jobs
     */
    public static function geoLocator(bool $getShared = true): GeoLocator
    {
        if ($getShared) {
            return static::getSharedInstance('geoLocator');
        }

        return new GeoLocator(config(Tracker::class)->geoipDatabase);
    }

    /**
     * The AI client: FakeAiClient or GeminiClient, by ai.driver, wrapped in RecordingAiClient so every call is logged
     * to ai_usage_log. Production refuses the fake (customers would get dummy answers) and the tests refuse Gemini
     * (a test run would spend money).
     */
    public static function ai(bool $getShared = true): RecordingAiClient
    {
        if ($getShared) {
            return static::getSharedInstance('ai');
        }

        $config = config(Ai::class);

        return new RecordingAiClient(self::aiDriver($config), model(AiUsageLogModel::class, false), $config->driver);
    }

    private static function aiDriver(Ai $config): AiClient
    {
        return match ($config->driver) {
            Ai::DRIVER_FAKE => ENVIRONMENT === 'production'
                ? throw new RuntimeException('ai.driver "fake" is not allowed in production')
                : new FakeAiClient($config),
            Ai::DRIVER_GEMINI => ENVIRONMENT === 'testing'
                ? throw new RuntimeException('ai.driver "gemini" is not allowed in testing')
                : new GeminiClient($config, static::curlrequest([], null, null, false)),
            default => throw new RuntimeException('Unknown ai.driver "' . $config->driver . '"'),
        };
    }

    public static function imageProcessor(bool $getShared = true): ImageProcessor
    {
        if ($getShared) {
            return static::getSharedInstance('imageProcessor');
        }

        return new ImageProcessor();
    }

    /**
     * The signed-in account of the current request (set by the jwt filter)
     */
    public static function authContext(bool $getShared = true): AuthContext
    {
        if ($getShared) {
            return static::getSharedInstance('authContext');
        }

        return new AuthContext();
    }
}
