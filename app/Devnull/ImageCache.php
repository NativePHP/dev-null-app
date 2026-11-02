<?php

namespace App\Devnull;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;

/**
 * Remote images kept on the device: avatars, commit images and link preview
 * images. NativePHP's image element streams a remote URL every time it comes
 * into view, but draws a file on the device from memory, so an image is
 * downloaded once in the background (see DownloadImages) and drawn from its
 * file until it's older than config('devnull.image_cache_hours').
 *
 * Downloads start when an answer from devnull arrives, never while a screen is
 * drawn, so an image not downloaded yet just loads from its URL as before. The
 * log's next page brings its images down with it (see FetchInBackground), so
 * its rows are drawn from the device from the start.
 *
 * Each URL is drawn the same way for as long as the app runs: from its file if
 * it had one the first time it was drawn, from the URL otherwise. A download
 * landing later is drawn from its file from the next launch on. Switching an
 * image to its file as soon as it landed rebuilt every row showing it, and the
 * phone loaded the visible ones again from disk, for a picture that looked the
 * same. So rows are kept against their commits alone (see RowInputs).
 *
 * The singleton holds what's settled, so it lives as long as the app does.
 */
final class ImageCache
{
    public const string Directory = 'image-cache';

    /**
     * What each URL drawn so far is drawn from, by URL.
     *
     * @var array<string, string>
     */
    private array $sources = [];

    /**
     * When the app started, which sets what the launch's one prune clears out.
     */
    private int $startedAt;

    private bool $pruneTaken = false;

    public function __construct()
    {
        $this->startedAt = CarbonImmutable::now()->getTimestamp();
    }

    /**
     * What to give an image element: the file this URL was downloaded to while
     * it's fresh, otherwise the URL itself, as it was the first time it was
     * drawn since the app started.
     */
    public static function src(?string $url): ?string
    {
        if (! self::isRemote($url)) {
            return $url;
        }

        return app(self::class)->sources[$url] ??= self::freshPath($url) ?? $url;
    }

    /**
     * Downloads, in the background, the images in an answer from devnull that
     * aren't on the device yet.
     *
     * @param  array<array-key, mixed>  $json
     */
    public static function prefetch(array $json): void
    {
        $missing = self::missingIn($json);

        if ($missing !== []) {
            DownloadImages::dispatch($missing, self::takePrune())->timeout(120);
        }
    }

    /**
     * The image URLs in an answer that aren't on the device yet, once each.
     * None with the cache off.
     *
     * @param  array<array-key, mixed>  $json
     * @return list<string>
     */
    public static function missingIn(array $json): array
    {
        if (self::hours() <= 0) {
            return [];
        }

        return array_values(array_filter(
            array_unique(self::urlsIn($json)),
            fn (string $url): bool => self::freshPath($url) === null,
        ));
    }

    /**
     * For the first task this launch that downloads images, the time images
     * older than are cleared out, and null for every task after it (see
     * DownloadImages::prune). Every task listed the whole folder before, which
     * cost the background threads 15 seconds of CPU in 75 of scrolling. Only
     * images that were already too old when the app started go, so none drawn
     * since can.
     */
    public static function takePrune(): ?int
    {
        $cache = app(self::class);

        if ($cache->pruneTaken || self::hours() <= 0) {
            return null;
        }

        $cache->pruneTaken = true;

        return $cache->startedAt - self::hours() * 3600;
    }

    /**
     * Deletes every image on the device, on sign-out, so the next person to
     * sign in never sees what the last one was shown. What's drawn from now on
     * comes from its URL.
     */
    public static function clear(): void
    {
        File::deleteDirectory(self::directory());

        app(self::class)->sources = [];
    }

    /**
     * Every image URL in an answer, wherever it's nested: people's avatars,
     * commits' images and link previews' images.
     *
     * @param  array<array-key, mixed>  $json
     * @return list<string>
     */
    public static function urlsIn(array $json): array
    {
        $urls = [];

        foreach ($json as $key => $value) {
            if (in_array($key, ['avatar_url', 'image_url'], true) && self::isRemote($value)) {
                $urls[] = $value;
            } elseif ($key === 'images' && is_array($value)) {
                foreach ($value as $image) {
                    if (is_array($image) && self::isRemote($image['url'] ?? null)) {
                        $urls[] = $image['url'];
                    }
                }
            } elseif (is_array($value)) {
                $urls = [...$urls, ...self::urlsIn($value)];
            }
        }

        return $urls;
    }

    /**
     * Where a URL's image is kept, named for the URL.
     */
    public static function path(string $url): string
    {
        return self::directory().'/'.hash('sha256', $url);
    }

    public static function directory(): string
    {
        return storage_path('app/'.self::Directory);
    }

    public static function hours(): int
    {
        return (int) config('devnull.image_cache_hours');
    }

    /**
     * The file a URL was downloaded to, while it's younger than the cache
     * keeps images, or null.
     */
    private static function freshPath(string $url): ?string
    {
        if (self::hours() <= 0) {
            return null;
        }

        $path = self::path($url);
        $modified = is_file($path) ? filemtime($path) : false;

        return $modified !== false && $modified > time() - self::hours() * 3600 ? $path : null;
    }

    /**
     * @phpstan-assert-if-true string $value
     */
    private static function isRemote(mixed $value): bool
    {
        return is_string($value) && preg_match('#^https?://#i', $value) === 1;
    }
}
