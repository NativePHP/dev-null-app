<?php

namespace App\Devnull;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Native\Mobile\AsyncTask;

/**
 * Asks YouTube's oEmbed for videos' titles, channels and thumbnails on one
 * of NativePHP's background PHP threads, a few at a time, and keeps the
 * answers for VideoDetails. oEmbed needs no key. A video YouTube can't describe
 * (private, removed, or the phone is offline) is left alone for an hour.
 */
final class FetchVideoDetails extends AsyncTask
{
    public const string Endpoint = 'https://www.youtube.com/oembed';

    private const int AtOnce = 6;

    /**
     * How long one lookup may take, in seconds.
     */
    private const int Timeout = 10;

    /**
     * @param  list<string>  $urls
     */
    public function handle(array $urls): int
    {
        return self::save($urls);
    }

    /**
     * Looks videos up and says how many it kept. With $until, a microtime(true),
     * it starts no batch after then and gives the last one only the time that's
     * left, so a caller waiting on it isn't held up long.
     *
     * @param  list<string>  $urls
     */
    public static function save(array $urls, ?float $until = null): int
    {
        $saved = 0;

        foreach (array_chunk($urls, self::AtOnce) as $batch) {
            $timeout = $until === null ? self::Timeout : min(self::Timeout, $until - microtime(true));

            if ($timeout <= 0) {
                break;
            }

            $responses = Http::pool(fn (Pool $pool): array => array_map(
                fn (string $url) => $pool->acceptJson()->timeout(max(1, (int) ceil($timeout)))->get(self::Endpoint, ['url' => $url, 'format' => 'json']),
                $batch,
            ));

            foreach ($batch as $index => $url) {
                $response = $responses[$index] ?? null;
                $title = $response instanceof Response && $response->successful() ? $response->json('title') : null;

                if (is_string($title) && trim($title) !== '') {
                    $author = $response->json('author_name');
                    $thumbnail = $response->json('thumbnail_url');
                    VideoDetails::remember($url, $title, is_string($author) && trim($author) !== '' ? $author : null, is_string($thumbnail) ? $thumbnail : null);
                    $saved++;
                } else {
                    VideoDetails::rememberFailure($url);
                }
            }
        }

        return $saved;
    }
}
