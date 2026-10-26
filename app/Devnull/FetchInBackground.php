<?php

namespace App\Devnull;

use App\Devnull\Exceptions\ApiException;
use Illuminate\Http\Client\ConnectionException;
use Native\Mobile\AsyncTask;

/**
 * Sends a request, signed on the UI thread, from one of NativePHP's background
 * PHP threads, so a refresh never holds up a tap, and a slow write can say it's
 * under way. It only carries bytes: the screen that asked decides what the
 * answer means and keeps it (see Concerns\RefreshesInBackground and
 * Concerns\WritesInBackground). Nothing here touches the Keychain or the
 * response cache.
 *
 * Asked $withImages, it downloads the answer's images that aren't on the
 * device yet before it comes back, so they land with it. Every task that
 * lands makes the screen render again, so a log page and its images landing
 * apart cost two renders where one will do, and rows drawn before their
 * images came would load them from their URLs. The Log's next page asks for
 * this, since it waits in memory until you scroll near it anyway.
 *
 * A write's body goes with the type it was signed with: JSON, or multipart
 * for a commit with photos. It comes last, since a task's arguments are
 * passed in order.
 */
final class FetchInBackground extends AsyncTask
{
    /**
     * How long an answer's images may hold it up once it's here, in seconds.
     * Any not down by then load from their URLs, as before they're kept.
     */
    public const int ImagesWithin = 5;

    /**
     * What went wrong with a write this task sent, as the API would have
     * thrown it on the UI thread, or null when devnull took it. A phone that
     * couldn't reach devnull reads as not connecting, and an answer that
     * isn't devnull's, such as a captive portal's page, as unexpected.
     */
    public static function failureOf(mixed $result): ?ApiException
    {
        $result = is_array($result) ? $result : [];
        $status = (int) ($result['status'] ?? 0);
        $successful = $status >= 200 && $status < 300;

        return match (true) {
            $successful && ($result['from_devnull'] ?? false) === true => null,
            $status === 0 => ApiException::couldNotConnect((string) config('devnull.url')),
            $successful => ApiException::unexpectedAnswer((string) config('devnull.url'), $status),
            default => ApiException::fromParts($status, $result['json'] ?? null, (string) ($result['retry_after'] ?? '')),
        };
    }

    /**
     * The JSON devnull answered a write with, or an empty array for a write it answered with nothing.
     *
     * @return array<string, mixed>
     */
    public static function jsonOf(mixed $result): array
    {
        return is_array($result) && is_array($result['json'] ?? null) ? $result['json'] : [];
    }

    /**
     * @param  array<string, string>  $headers
     * @return array{status: int, json: mixed, retry_after: string, from_devnull: bool}
     */
    public function handle(string $url, array $headers, string $method = 'GET', string $body = '', bool $withImages = false, ?int $pruneBefore = null, string $contentType = 'application/json'): array
    {
        if ($pruneBefore !== null) {
            DownloadImages::prune($pruneBefore);
        }

        try {
            $response = Client::sendSigned(new SignedRequest($url, $headers, $method, $body, $contentType));
        } catch (ConnectionException) {
            return ['status' => 0, 'json' => null, 'retry_after' => '', 'from_devnull' => false];
        }

        $result = [
            'status' => $response->status(),
            'json' => $response->json(),
            'retry_after' => $response->header('Retry-After'),
            'from_devnull' => Client::answeredByDevnull($response, $method),
        ];

        if ($withImages && $result['from_devnull'] && $response->successful() && is_array($result['json'])) {
            DownloadImages::save(ImageCache::missingIn($result['json']), until: microtime(true) + self::ImagesWithin);
        }

        return $result;
    }
}
