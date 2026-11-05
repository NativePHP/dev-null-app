<?php

namespace App\Devnull;

use Illuminate\Support\Facades\Cache;
use Native\Mobile\Facades\System;

/**
 * Which push token devnull already has from this phone, and for whom, so a token is sent once: when it first
 * arrives, after a new sign-in, and when Firebase hands the phone a new one. Only a hash of it is kept.
 */
final class PushToken
{
    private const string SentKey = 'devnull.push-token.sent';

    public function needsSending(string $userId, string $token): bool
    {
        return Cache::get(self::SentKey) !== self::mark($userId, $token);
    }

    public function sent(string $userId, string $token): void
    {
        Cache::forever(self::SentKey, self::mark($userId, $token));
    }

    public function forget(): void
    {
        Cache::forget(self::SentKey);
    }

    /**
     * The platform devnull sends through, as it names them.
     */
    public static function platform(): string
    {
        return System::isIos() ? 'ios' : 'android';
    }

    private static function mark(string $userId, string $token): string
    {
        return $userId.':'.hash('sha256', $token);
    }
}
