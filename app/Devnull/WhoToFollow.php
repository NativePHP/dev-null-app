<?php

namespace App\Devnull;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Who to follow on this phone, per user: whether the Log shows it, which the
 * web keeps in a cookie and Settings → Appearance switches, and which round
 * of the day's picks it's on, which the web's refresh button moves through.
 */
final class WhoToFollow
{
    public function shows(?string $userId): bool
    {
        return $userId !== null && Cache::get($this->key($userId, 'hidden')) !== true;
    }

    public function show(?string $userId, bool $shows): void
    {
        if ($userId === null) {
            return;
        }

        $shows ? Cache::forget($this->key($userId, 'hidden')) : Cache::forever($this->key($userId, 'hidden'), true);
    }

    /**
     * The round showing today. devnull picks afresh each day, so a new day starts at the first.
     */
    public function round(?string $userId): int
    {
        $kept = $userId === null ? null : Cache::get($this->key($userId, 'round'));

        return is_array($kept) && ($kept['day'] ?? null) === $this->today() && is_int($kept['round'] ?? null) ? $kept['round'] : 0;
    }

    /**
     * The round showing now, once its people have landed.
     */
    public function rememberRound(?string $userId, int $round): void
    {
        if ($userId !== null) {
            Cache::forever($this->key($userId, 'round'), ['day' => $this->today(), 'round' => $round]);
        }
    }

    private function today(): string
    {
        return CarbonImmutable::now('UTC')->toDateString();
    }

    private function key(string $userId, string $name): string
    {
        return "devnull.who-to-follow.{$userId}.{$name}";
    }
}
