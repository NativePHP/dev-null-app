<?php

namespace App\Devnull;

use App\Devnull\Data\LinkPreview;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * The title and channel of the YouTube videos commits link to, which the web
 * doesn't show and devnull doesn't send yet, and a thumbnail for the ones
 * devnull has no poster for, such as playlists. The app asks YouTube's oEmbed
 * for them on a background thread (see FetchVideoDetails) and keeps the
 * answers on the phone, so a screen never waits on YouTube: it draws with
 * what it has and shows them once they're here.
 *
 * When devnull sends an embed's title and channel itself, those are used and
 * nothing is looked up.
 */
final class VideoDetails
{
    public const string Store = 'videos';

    public const int KeptForDays = 30;

    /**
     * How long a lookup that failed is left before it's tried again.
     */
    public const int FailureKeptForMinutes = 60;

    /**
     * The videos in an answer whose title and channel the app should look up,
     * by link, however deep, once each.
     *
     * @param  array<array-key, mixed>  $json
     * @return list<string>
     */
    public static function missingIn(array $json): array
    {
        return array_values(array_filter(
            array_unique(self::urlsIn($json)),
            fn (string $url): bool => ! self::store()->has(self::key($url)),
        ));
    }

    /**
     * Looks up, in the background, the videos in an answer from devnull that
     * haven't been looked up yet.
     *
     * @param  array<array-key, mixed>  $json
     */
    public static function prefetch(array $json): void
    {
        $missing = self::missingIn($json);

        if ($missing !== []) {
            FetchVideoDetails::dispatch($missing)->timeout(60);
        }
    }

    /**
     * How to draw a video's card: who plays it, its title and channel when
     * they're known, and its poster, devnull's or else YouTube's thumbnail.
     * The page title devnull scraped is only used when there's more to it
     * than " - YouTube".
     *
     * @return array{provider: string, title: ?string, author: ?string, poster: ?string}
     */
    public static function card(LinkPreview $preview): array
    {
        $embed = $preview->embed;
        $kept = self::kept($preview->url);

        return [
            'provider' => $embed?->provider ?? $preview->site(),
            'title' => $embed?->title ?? $kept['title'] ?? self::scrapedTitle($preview->title),
            'author' => $embed?->author ?? $kept['author'] ?? null,
            'poster' => $embed?->poster ?? $kept['thumbnail'] ?? null,
        ];
    }

    /**
     * Keeps what YouTube said about a video. Only an https thumbnail is kept.
     */
    public static function remember(string $url, string $title, ?string $author, ?string $thumbnail = null): void
    {
        self::store()->put(self::key($url), [
            'title' => $title,
            'author' => $author,
            'thumbnail' => is_string($thumbnail) && str_starts_with($thumbnail, 'https://') ? $thumbnail : null,
        ], now()->addDays(self::KeptForDays));
    }

    /**
     * Notes that YouTube couldn't say, so it isn't asked again for a while.
     */
    public static function rememberFailure(string $url): void
    {
        self::store()->put(self::key($url), false, now()->addMinutes(self::FailureKeptForMinutes));
    }

    /**
     * @return array{title: string, author: ?string, thumbnail?: ?string}|null
     */
    private static function kept(string $url): ?array
    {
        $kept = self::store()->get(self::key($url));

        return is_array($kept) && is_string($kept['title'] ?? null) ? $kept : null;
    }

    /**
     * @param  array<array-key, mixed>  $json
     * @return list<string>
     */
    private static function urlsIn(array $json): array
    {
        $urls = [];

        foreach ($json as $key => $value) {
            if (! is_array($value)) {
                continue;
            }

            if ($key === 'link_preview' && self::needsLookup($value)) {
                $urls[] = $value['url'];
            } else {
                $urls = [...$urls, ...self::urlsIn($value)];
            }
        }

        return $urls;
    }

    /**
     * @param  array<array-key, mixed>  $preview
     */
    private static function needsLookup(array $preview): bool
    {
        $embed = $preview['embed'] ?? null;

        return is_string($preview['url'] ?? null) && str_starts_with($preview['url'], 'https://')
            && is_array($embed)
            && is_string($embed['provider'] ?? null) && strcasecmp($embed['provider'], 'YouTube') === 0
            && ! (is_string($embed['title'] ?? null) && trim($embed['title']) !== '');
    }

    private static function scrapedTitle(?string $title): ?string
    {
        $title = trim((string) preg_replace(['/\s*-\s*YouTube\s*$/i', '/^\s*-\s*/'], '', (string) $title));

        return $title !== '' ? $title : null;
    }

    private static function key(string $url): string
    {
        return 'video:'.hash('sha256', $url);
    }

    private static function store(): Repository
    {
        return Cache::store(self::Store);
    }
}
