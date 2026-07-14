<?php

namespace App\Support;

/**
 * Rate limiter key helpers for the named `login` limiter.
 *
 * Must stay in sync with RateLimiter::for('login', ...) in AppServiceProvider.
 */
class LoginThrottleKeys
{
    public const LIMITER = 'login';

    public static function forIp(string $ip): string
    {
        return md5(self::LIMITER.$ip);
    }

    public static function forEmail(string $email): string
    {
        return md5(self::LIMITER.'email:'.strtolower(trim($email)));
    }

    /**
     * Legacy inline middleware key: throttle:5,15 (domain often empty).
     */
    public static function legacyForIp(string $ip, string $domain = ''): string
    {
        return sha1($domain.'|'.$ip);
    }

}
