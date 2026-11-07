<?php

namespace Config;

use App\Libraries\AuthContext;
use App\Libraries\ContactFormGuard;
use App\Libraries\EmailService;
use App\Libraries\ImageProcessor;
use App\Libraries\JwtService;
use App\Libraries\LoginThrottle;
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
     * The signed-in account of the current request (set by the jwt filter)
     */
    public static function imageProcessor(bool $getShared = true): ImageProcessor
    {
        if ($getShared) {
            return static::getSharedInstance('imageProcessor');
        }

        return new ImageProcessor();
    }

    public static function authContext(bool $getShared = true): AuthContext
    {
        if ($getShared) {
            return static::getSharedInstance('authContext');
        }

        return new AuthContext();
    }
}
