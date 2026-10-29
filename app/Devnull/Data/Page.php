<?php

namespace App\Devnull\Data;

use Closure;

/**
 * One page of a cursor-paginated list. A list paged by number, as scheduled
 * commits are, carries the next page's number as its cursor.
 *
 * @template T
 */
final readonly class Page
{
    /**
     * @param  list<T>  $items
     */
    public function __construct(public array $items, public ?string $nextCursor = null) {}

    /**
     * @template TItem
     *
     * @param  array<string, mixed>  $json
     * @param  Closure(array<string, mixed>): TItem  $map
     * @return self<TItem>
     */
    public static function fromArray(array $json, Closure $map): self
    {
        $items = is_array($json['data'] ?? null) ? $json['data'] : [];

        $cursor = $json['meta']['next_cursor']
            ?? $json['next_cursor']
            ?? self::cursorFromUrl($json['links']['next'] ?? null);

        return new self(
            array_values(array_map($map, array_filter($items, is_array(...)))),
            is_string($cursor) && $cursor !== '' ? $cursor : null,
        );
    }

    public function hasMore(): bool
    {
        return $this->nextCursor !== null;
    }

    private static function cursorFromUrl(mixed $url): ?string
    {
        if (! is_string($url)) {
            return null;
        }

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $cursor = $query['cursor'] ?? $query['page'] ?? null;

        return is_string($cursor) ? $cursor : null;
    }
}
