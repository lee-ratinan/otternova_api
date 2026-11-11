<?php

namespace Config;

use App\Libraries\AuthContext;
use App\Libraries\ContactFormGuard;
use App\Libraries\EmailService;
use App\Libraries\GeoLocator;
use App\Libraries\ImageProcessor;
use App\Libraries\JwtService;
use App\Libraries\LoginThrottle;
use App\Libraries\MartCache;
use App\Libraries\TrackerGuard;
use CodeIgniter\Config\BaseService;

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
