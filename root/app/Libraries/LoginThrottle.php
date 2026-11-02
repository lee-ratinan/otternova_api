<?php

namespace App\Libraries;

use CodeIgniter\Cache\CacheInterface;
use Config\Auth;

/**
 * Counts failed sign-ins (wrong password or wrong OTP) per email address and per IP address
 * in a fixed window, so /login can answer 429 once either count reaches its limit.
 * Sign-ups are counted the same way, apart from the sign-ins, so /create-client-account cannot be used to flood
 * an inbox with emails.
 */
class LoginThrottle
{
    public function __construct(private readonly CacheInterface $cache, private readonly Auth $config)
    {
    }

    /**
     * @param string $email
     * @param string $ip
     * @return bool
     */
    public function isBlocked(string $email, string $ip): bool
    {
        return $this->count('email', $email) >= $this->config->throttleMaxPerEmail
            || $this->count('ip', $ip) >= $this->config->throttleMaxPerIp;
    }

    /**
     * @param string $email
     * @param string $ip
     * @return void
     */
    public function recordFailure(string $email, string $ip): void
    {
        $this->increment('email', $email);
        $this->increment('ip', $ip);
    }

    /**
     * Forget the failures of an email address after it signs in
     * @param string $email
     * @return void
     */
    public function clear(string $email): void
    {
        $this->cache->delete($this->key('email', $email));
    }

    /**
     * @param string $email
     * @param string $ip
     * @return bool
     */
    public function isSignUpBlocked(string $email, string $ip): bool
    {
        return $this->count('signup_email', $email) >= $this->config->signUpMaxPerEmail
            || $this->count('signup_ip', $ip) >= $this->config->signUpMaxPerIp;
    }

    /**
     * Count a sign-up, whether it created an account or not
     * @param string $email
     * @param string $ip
     * @return void
     */
    public function recordSignUp(string $email, string $ip): void
    {
        $this->increment('signup_email', $email);
        $this->increment('signup_ip', $ip);
    }

    /**
     * @param string $scope
     * @param string $value
     * @return int
     */
    private function count(string $scope, string $value): int
    {
        $entry = $this->cache->get($this->key($scope, $value));

        return is_array($entry) && $entry['until'] > time() ? $entry['count'] : 0;
    }

    /**
     * @param string $scope
     * @param string $value
     * @return void
     */
    private function increment(string $scope, string $value): void
    {
        $key   = $this->key($scope, $value);
        $entry = $this->cache->get($key);
        if (! is_array($entry) || $entry['until'] <= time()) {
            $entry = ['count' => 0, 'until' => time() + $this->config->throttleWindow];
        }
        $entry['count']++;
        $this->cache->save($key, $entry, max(1, $entry['until'] - time()));
    }

    /**
     * Hashed, so the key holds no reserved cache characters and no plain email address
     * @param string $scope
     * @param string $value
     * @return string
     */
    private function key(string $scope, string $value): string
    {
        return 'login_fail_' . $scope . '_' . hash('sha256', strtolower(trim($value)));
    }
}
