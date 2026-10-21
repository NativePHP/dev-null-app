<?php

namespace App\Devnull;

/**
 * Remote images kept on the device: avatars, commit images and link preview
 * images. NativePHP's image element streams a remote URL every time it comes
 * into view, but draws a file on the device from memory, so an image is
 * downloaded once in the background (see DownloadImages) and drawn from its
 * file until it's older than config('devnull.image_cache_hours').
 *
 * Downloads start when an answer from devnull arrives, never while a screen is
 * drawn, so an image not downloaded yet just loads from its URL as before.
 */
final class ImageCache
{
    public const string Directory = 'image-cache';

    /**
     * What to give an image element: the file this URL was downloaded to while
     * it's fresh, otherwise the URL itself.
     */
    public static function src(?string $url): ?string
    {
        return self::freshPath($url) ?? $url;
    }

    /**
     * Downloads, in the background, the images in an answer from devnull that
     * aren't on the device yet.
     *
     * @param  array<array-key, mixed>  $json
     */
    public static function prefetch(array $json): void
    {
        if (self::hours() <= 0) {
            return;
        }

        $missing = array_values(array_filter(
            array_unique(self::urlsIn($json)),
            fn (string $url): bool => self::freshPath($url) === null,
        ));

        if ($missing !== []) {
            DownloadImages::dispatch($missing)->timeout(120);
        }
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
    private static function freshPath(?string $url): ?string
    {
        if (self::hours() <= 0 || ! self::isRemote($url)) {
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
