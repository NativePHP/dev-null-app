<?php

namespace App\Devnull;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Native\Mobile\AsyncTask;
use Throwable;

/**
 * Downloads images for ImageCache on one of NativePHP's background PHP threads,
 * a few at a time, so the screen never waits on them. Only images are kept,
 * and each is written whole before it can be drawn. The first task of a launch
 * clears out images past the cache's age (see ImageCache::takePrune), so the
 * folder holds about a day's worth.
 */
final class DownloadImages extends AsyncTask
{
    private const int AtOnce = 6;

    /**
     * How long one image may take, in seconds.
     */
    private const int Timeout = 15;

    /**
     * @param  list<string>  $urls
     */
    public function handle(array $urls, ?int $pruneBefore = null): int
    {
        if ($pruneBefore !== null) {
            self::prune($pruneBefore);
        }

        return self::save($urls);
    }

    /**
     * Downloads images into the cache and says how many it kept. With $until,
     * a microtime(true), it starts no batch after then and gives the last one
     * only the time that's left, so a caller waiting on it isn't held up long.
     *
     * @param  list<string>  $urls
     */
    public static function save(array $urls, ?float $until = null): int
    {
        if ($urls === []) {
            return 0;
        }

        File::ensureDirectoryExists(ImageCache::directory());

        $saved = 0;

        foreach (array_chunk($urls, self::AtOnce) as $batch) {
            $timeout = $until === null ? self::Timeout : min(self::Timeout, $until - microtime(true));

            if ($timeout <= 0) {
                break;
            }

            $responses = Http::pool(fn (Pool $pool): array => array_map(
                fn (string $url) => $pool->timeout(max(1, $timeout))->get($url),
                $batch,
            ));

            // Signing out empties the cache (see Account::forget), and this thread can't tell it happened otherwise.
            if (app(Account::class)->user() === null) {
                break;
            }

            foreach ($batch as $index => $url) {
                $response = $responses[$index] ?? null;

                if ($response instanceof Response && $response->successful() && str_starts_with((string) $response->header('Content-Type'), 'image/')) {
                    $path = ImageCache::path($url);
                    File::put($path.'.part', $response->body());
                    File::move($path.'.part', $path);
                    $saved++;
                }
            }
        }

        return $saved;
    }

    /**
     * Deletes images last written before a time, and any half-written ones.
     */
    public static function prune(int $before): void
    {
        if (! File::isDirectory(ImageCache::directory())) {
            return;
        }

        foreach (File::files(ImageCache::directory()) as $file) {
            try {
                if (str_ends_with($file->getFilename(), '.part') || $file->getMTime() <= $before) {
                    File::delete($file->getPathname());
                }
            } catch (Throwable) {
                // Another download may have just replaced it.
            }
        }
    }
}
