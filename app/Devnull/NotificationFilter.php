<?php

namespace App\Devnull;

use Illuminate\Support\Facades\Cache;

/**
 * Which kinds of notification you see, as the web's filters choose them.
 *
 * Each kind is on or off on its own. devnull is asked for the ones that are
 * on as a comma list (?type=replies,stars), for all of them as `all`, and for
 * none as `none`, exactly as the web asks. The web keeps the choice in a
 * cookie per browser, and devnull's API keeps nothing, so the app keeps it on
 * this phone, per user.
 */
final class NotificationFilter
{
    /**
     * The web's kinds, in the web's order: the label, a line for the settings screen, and what the list says when
     * only that kind is on and there's nothing of it.
     *
     * @var array<string, array{0: string, 1: string, 2: string}>
     */
    public const array Kinds = [
        'replies' => ['Replies', 'Someone replies to one of your commits.', 'No replies yet. When someone replies to one of your commits, it shows up here.'],
        'quotes' => ['Quotes', 'Someone quotes one of your commits.', 'No quotes yet. When someone quotes one of your commits, it shows up here.'],
        'stars' => ['Stars', 'Someone stars one of your commits.', 'No stars yet. When someone stars one of your commits, it shows up here.'],
        'mentions' => ['Mentions', 'Someone mentions you in a commit.', 'No mentions yet. When someone mentions you in a commit, it shows up here.'],
        'follows' => ['Follows', 'Someone follows you.', 'No followers yet. When someone follows you, it shows up here.'],
        'moderation' => ['Moderation', 'Approved, rejected, and timeout notices.', 'No moderation updates. Approved, rejected, and timeout notices show up here.'],
    ];

    /**
     * What the list says with every kind on and nothing in it.
     */
    public const string AllEmpty = 'Nothing here yet. Follows, stars, replies, quotes, mentions, level-ups, and moderation updates for your commits will show up here.';

    /**
     * The kinds that are on for this user, in the web's order. Everything is on until they turn something off.
     *
     * @return list<string>
     */
    public function enabled(?string $userId): array
    {
        $kept = $userId === null ? null : Cache::get($this->key($userId));

        return is_array($kept) ? self::only($kept) : array_keys(self::Kinds);
    }

    /**
     * @param  list<string>  $kinds
     */
    public function save(?string $userId, array $kinds): void
    {
        if ($userId !== null) {
            Cache::forever($this->key($userId), self::only($kinds));
        }
    }

    /**
     * Turns one kind on or off, and says what's on now.
     *
     * @return list<string>
     */
    public function set(?string $userId, string $kind, bool $on): array
    {
        $enabled = $this->enabled($userId);
        $kinds = $on ? [...$enabled, $kind] : array_values(array_diff($enabled, [$kind]));

        $this->save($userId, $kinds);

        return $this->enabled($userId);
    }

    /**
     * What devnull is asked for, as the web asks: `all`, `none`, or the kinds that are on, comma separated.
     *
     * @param  list<string>  $kinds
     */
    public static function type(array $kinds): string
    {
        $kinds = self::only($kinds);

        return match (true) {
            count($kinds) === count(self::Kinds) => 'all',
            $kinds === [] => 'none',
            default => implode(',', $kinds),
        };
    }

    /**
     * The web's empty state for what's on: its own words for one kind or all of them, and plainer ones otherwise.
     *
     * @param  list<string>  $kinds
     */
    public static function emptyMessage(array $kinds): string
    {
        return match (true) {
            $kinds === [] => 'All categories are turned off. Enable a category to see notifications.',
            count($kinds) === count(self::Kinds) => self::AllEmpty,
            count($kinds) === 1 => self::Kinds[$kinds[0]][2],
            default => 'No notifications in the selected categories yet.',
        };
    }

    /**
     * The shell line over the empty state, as the web prints it.
     *
     * @param  list<string>  $kinds
     */
    public static function command(array $kinds): string
    {
        return count($kinds) === count(self::Kinds)
            ? '$ cat /dev/notifications'
            : "\$ cat /dev/notifications | grep -E '".($kinds === [] ? '^$' : implode('|', $kinds))."'";
    }

    /**
     * Known kinds only, once each, in the web's order.
     *
     * @param  array<mixed>  $kinds
     * @return list<string>
     */
    private static function only(array $kinds): array
    {
        return array_values(array_filter(array_keys(self::Kinds), fn (string $kind): bool => in_array($kind, $kinds, true)));
    }

    private function key(string $userId): string
    {
        return "devnull.notification-kinds.{$userId}";
    }
}
