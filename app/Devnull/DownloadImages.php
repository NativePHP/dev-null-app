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
 * and each is written whole before it can be drawn. Images past the cache's
 * age are cleared out first, so the folder holds about a day's worth.
 */
final class DownloadImages extends AsyncTask
{
    private const int AtOnce = 6;

    /**
     * @param  list<string>  $urls
     */
    public function handle(array $urls): int
    {
        File::ensureDirectoryExists(ImageCache::directory());
        self::prune();

        $saved = 0;

        foreach (array_chunk($urls, self::AtOnce) as $batch) {
            $responses = Http::pool(fn (Pool $pool): array => array_map(
                fn (string $url) => $pool->timeout(15)->get($url),
                $batch,
            ));

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
     * Deletes images older than the cache keeps them, and any half-written ones.
     */
    private static function prune(): void
    {
        $oldest = time() - ImageCache::hours() * 3600;

        foreach (File::files(ImageCache::directory()) as $file) {
            try {
                if (str_ends_with($file->getFilename(), '.part') || $file->getMTime() <= $oldest) {
                    File::delete($file->getPathname());
                }
            } catch (Throwable) {
                // Another download may have just replaced it.
            }
        }
    }
}
