<?php

namespace App\Devnull;

use App\Devnull\Data\Pasteis;
use App\Devnull\Data\Profile;
use App\Devnull\Data\Remotes;
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

    private const string RemotesKey = 'devnull.remotes';

    private const string ProfileKey = 'devnull.profile';

    /**
     * What's kept while devnull has said you have Remotes off, as against not having said yet.
     */
    private const string RemotesOff = 'off';

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
     * Your own profile as devnull last sent it (GET /users/{you}), kept for as
     * long as you're signed in rather than as long as the response cache keeps
     * things, so your profile still shows when devnull can't be reached. Your
     * commits and replies aren't kept here.
     *
     * @return array<string, mixed>|null
     */
    public function profile(): ?array
    {
        $json = Cache::get(self::ProfileKey);

        return is_array($json) ? $json : null;
    }

    /**
     * Keeps your profile. An answer with nobody in it, like a maintenance page
     * read as JSON, leaves the one kept alone.
     *
     * @param  array<string, mixed>  $json
     */
    public function rememberProfile(array $json): void
    {
        if (Profile::fromArray($json)->user->username === '') {
            return;
        }

        Cache::forever(self::ProfileKey, $json);
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
     * Your Remotes as devnull last said, or null while you have them off. Until devnull has said, they're on, as
     * they are for everyone to start with.
     */
    public function remotes(): ?Remotes
    {
        $kept = Cache::get(self::RemotesKey);

        return match (true) {
            $kept === self::RemotesOff => null,
            is_array($kept) => Remotes::fromArray($kept),
            default => new Remotes,
        };
    }

    /**
     * Remembers your Remotes, or with null that you have them off.
     */
    public function rememberRemotes(?Remotes $remotes): void
    {
        Cache::forever(self::RemotesKey, $remotes?->toArray() ?? self::RemotesOff);
    }

    /**
     * How many messages and requests were waiting when devnull last said, for the Remotes tab's badge.
     */
    public function unreadRemotes(): int
    {
        return $this->remotes()->unreadCount ?? 0;
    }

    public function rememberUnreadRemotes(int $count): void
    {
        $remotes = $this->remotes();

        if ($remotes !== null) {
            $this->rememberRemotes($remotes->withUnreadCount($count));
        }
    }

    /**
     * Signs this phone out: who it was, the badges, its pastéis and Remotes, every answer and image kept for a
     * quick start, and your unsent drafts.
     */
    public function forget(): void
    {
        app(ResponseCache::class)->flush();
        ImageCache::clear();
        app(Stash::class)->forget($this->user()?->id);
        app(Outbox::class)->forgetAll($this->user()?->id);

        Cache::forget(self::CacheKey);
        Cache::forget(self::UnreadKey);
        Cache::forget(self::PasteisKey);
        Cache::forget(self::RemotesKey);
        Cache::forget(self::ProfileKey);
    }
}
