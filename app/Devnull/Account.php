<?php

namespace App\Devnull;

use App\Devnull\Data\Pasteis;
use App\Devnull\Data\User;
use Illuminate\Support\Facades\Cache;

/**
 * The devnull user this device has been verified as.
 *
 * Nothing here is secret: the key that proves it lives in the Keychain.
 */
final class Account
{
    private const string CacheKey = 'devnull.account';

    private const string UnreadKey = 'devnull.unread-notifications';

    private const string PasteisKey = 'devnull.pasteis';

    public function user(): ?User
    {
        $attributes = Cache::get(self::CacheKey);

        return is_array($attributes) ? User::fromArray($attributes) : null;
    }

    public function remember(User $user): void
    {
        Cache::forever(self::CacheKey, $user->toArray());
    }

    /**
     * How many notifications were unread when devnull last said, for the tab badge.
     */
    public function unreadNotifications(): int
    {
        $count = Cache::get(self::UnreadKey);

        return is_int($count) ? $count : 0;
    }

    public function rememberUnreadNotifications(int $count): void
    {
        Cache::forever(self::UnreadKey, max(0, $count));
    }

    /**
     * Your pastéis for today while pastéis mode is on, as devnull last said or
     * as this phone has counted them since, or null while the mode is off.
     */
    public function pasteis(): ?Pasteis
    {
        $kept = Cache::get(self::PasteisKey);

        return is_array($kept) ? Pasteis::fromKept($kept) : null;
    }

    /**
     * Remembers your pastéis, or with null that pastéis mode is off.
     */
    public function rememberPasteis(?Pasteis $pasteis): void
    {
        if ($pasteis === null) {
            Cache::forget(self::PasteisKey);

            return;
        }

        Cache::forever(self::PasteisKey, $pasteis->toArray());
    }

    /**
     * Signs this phone out: who it was, the badge, its pastéis, and every answer kept for a quick start.
     */
    public function forget(): void
    {
        app(ResponseCache::class)->flush();

        Cache::forget(self::CacheKey);
        Cache::forget(self::UnreadKey);
        Cache::forget(self::PasteisKey);
    }
}
