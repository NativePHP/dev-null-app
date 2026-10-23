<?php

namespace App\Devnull;

use Illuminate\Support\Facades\Cache;

/**
 * The notification categories the web's filter row keeps on, as the web keeps
 * them: a selection of its six categories, remembered per account. Nothing
 * selected asks for none of them, all six ask for all, and anything between
 * is sent as the comma-separated ?type= the API takes.
 *
 * An app that kept one filter at a time, on the Notifications tab (see
 * ShownTabs), opens on that one category, or on all of them when it kept All,
 * until a new selection is remembered here.
 */
final class NotificationFilters
{
    /**
     * The web's notification categories, in the order its filter row runs them.
     */
    public const array Categories = ['replies', 'quotes', 'stars', 'mentions', 'follows', 'moderation'];

    /**
     * The categories kept on for the account, in the web's order: all of them
     * when nothing was kept, or what the single filter an older app kept on
     * was, which All opens on all of them.
     *
     * @return list<string>
     */
    public function enabled(?string $userId): array
    {
        $kept = $userId === null ? null : Cache::get($this->key($userId));

        if (is_array($kept) && array_is_list($kept) && self::areCategories($kept)) {
            return self::normalize($kept);
        }

        $filter = app(ShownTabs::class)->get($userId, 'notifications', ['all', ...self::Categories], 'all');

        return $filter === 'all' ? self::Categories : [$filter];
    }

    /**
     * The ?type= the selection asks for: all, none, or the categories the web
     * sends them as, comma-separated in its order.
     */
    public function type(?string $userId): string
    {
        return self::typeOf($this->enabled($userId));
    }

    /**
     * Keeps the selection on for the account, dropping anything that isn't one
     * of the web's categories, so nothing else is ever kept or sent.
     *
     * @param  list<string>  $categories
     */
    public function remember(?string $userId, array $categories): void
    {
        if ($userId !== null) {
            Cache::forever($this->key($userId), self::normalize($categories));
        }
    }

    /**
     * The ?type= a selection asks for, as the web sends it: all six is all,
     * none is none, and anything between is the categories comma-separated in
     * the web's order.
     *
     * @param  list<string>  $categories
     */
    public static function typeOf(array $categories): string
    {
        $enabled = self::normalize($categories);

        return match (count($enabled)) {
            0 => 'none',
            count(self::Categories) => 'all',
            default => implode(',', $enabled),
        };
    }

    /**
     * A selection as the web's order runs it, without repeats and without
     * anything that isn't one of its categories. The ?type= a selection was
     * sent as reads back as the selection it asks for: all is every one of
     * them, none is not one.
     *
     * @param  array<string>|string  $categories  the categories, or the comma-separated ?type= they were sent as
     * @return list<string>
     */
    public static function normalize(array|string $categories): array
    {
        $sent = match ($categories) {
            'all' => self::Categories,
            'none' => [],
            default => is_string($categories) ? explode(',', $categories) : $categories,
        };

        return array_values(array_intersect(self::Categories, array_unique($sent)));
    }

    /**
     * Whether every one of these is one of the web's categories: a string
     * each, and nothing else, so nothing else kept in the cache is ever taken
     * for a selection.
     *
     * @param  list<string>  $categories
     */
    private static function areCategories(array $categories): bool
    {
        foreach ($categories as $category) {
            if (! is_string($category) || ! in_array($category, self::Categories, true)) {
                return false;
            }
        }

        return true;
    }

    private function key(string $userId): string
    {
        return "devnull.notification-filters.{$userId}";
    }
}
