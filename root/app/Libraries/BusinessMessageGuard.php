<?php

namespace App\Libraries;

use Config\BusinessMessageForm;
use LogicException;

/**
 * Keeps bots and floods out of the contact form of a business on otternova_mart, in the layers of ContactFormGuard
 * (form token, honeypot, duplicates, content checks), with the counts of Config\BusinessMessageForm:
 * - per IP address, email address and telephone number, to this business;
 * - per IP address, to all businesses together, so one sender cannot work through the marketplace;
 * - per business per day, so no one can flood its inbox.
 * A guard is for one business: service('businessMessageGuard')->forBusiness($id). Its form tokens are signed for
 * that business, so a token from one shop's page does not work on another's, nor on the public contact form.
 * The cache keys start with bmsg_, so these counts and the public contact form's never mix.
 */
class BusinessMessageGuard extends ContactFormGuard
{
    /** Why isSenderBlocked() refuses: the sender sent too many, or the business took too many today */
    public const BLOCKED_SENDER   = 'sender';
    public const BLOCKED_BUSINESS = 'business';

    /** The scope of the count per IP address across all businesses; see key() */
    private const SCOPE_ALL_IP = 'all_ip';

    private int $businessId = 0;

    /**
     * This guard, for one business
     * @param int $businessId business_master.id
     * @return static
     */
    public function forBusiness(int $businessId): static
    {
        $guard             = clone $this;
        $guard->businessId = $businessId;

        return $guard;
    }

    /**
     * Why a message may not be taken now, or null when it may
     * @param string|null $email Lower-cased
     * @param string|null $phone
     * @param string      $ip
     * @return string|null One of the BLOCKED_ constants
     */
    public function isSenderBlocked(?string $email, ?string $phone, string $ip): ?string
    {
        /** @var BusinessMessageForm $config */
        $config = $this->config;
        if ($this->count('day', 'business') >= $config->maxPerBusinessPerDay) {
            return self::BLOCKED_BUSINESS;
        }
        $phoneKey = self::phoneKey($phone);
        if ($this->count(self::SCOPE_ALL_IP, $ip) >= $config->maxPerIpAllBusinesses
            || $this->count('ip', $ip) >= $config->maxPerIp
            || ($email !== null && $this->count('email', $email) >= $config->maxPerEmail)
            || ($phoneKey !== null && $this->count('phone', $phoneKey) >= $config->maxPerPhone)) {
            return self::BLOCKED_SENDER;
        }

        return null;
    }

    /**
     * Count a submission that passed the token and field checks, whether it is stored or not
     * @param string|null $email Lower-cased
     * @param string|null $phone
     * @param string      $ip
     * @return void
     */
    public function recordSender(?string $email, ?string $phone, string $ip): void
    {
        $this->increment(self::SCOPE_ALL_IP, $ip);
        $this->increment('ip', $ip);
        if ($email !== null) {
            $this->increment('email', $email);
        }
        $phoneKey = self::phoneKey($phone);
        if ($phoneKey !== null) {
            $this->increment('phone', $phoneKey);
        }
        $this->increment('day', 'business', DAY);
    }

    /**
     * Who sent a message, for isDuplicate() and remember(): the email address, else the telephone number's digits
     * @param string|null $email Lower-cased
     * @param string|null $phone
     * @return string
     */
    public static function senderKey(?string $email, ?string $phone): string
    {
        return $email ?? ('tel:' . self::phoneKey($phone));
    }

    /**
     * A telephone number as its digits, so "+66 81-234 5678" and "+66812345678" count as one; null for none
     * @param string|null $phone
     * @return string|null
     */
    private static function phoneKey(?string $phone): ?string
    {
        $digits = $phone === null ? '' : (string) preg_replace('/\D+/', '', $phone);

        return $digits === '' ? null : $digits;
    }

    protected function tokenPurpose(): string
    {
        return 'business-message-token:' . $this->requireBusiness();
    }

    protected function keyPrefix(): string
    {
        return 'bmsg_' . $this->requireBusiness();
    }

    /**
     * The count per IP address across all businesses is the one key without the business in it
     */
    protected function key(string $scope, string $value): string
    {
        return $scope === self::SCOPE_ALL_IP
            ? 'bmsg_' . $scope . '_' . hash('sha256', strtolower(trim($value)))
            : parent::key($scope, $value);
    }

    /**
     * @return int
     * @throws LogicException When forBusiness() was not called: a guard without a business would share its counts
     */
    private function requireBusiness(): int
    {
        if ($this->businessId < 1) {
            throw new LogicException('BusinessMessageGuard is used without forBusiness()');
        }

        return $this->businessId;
    }
}
