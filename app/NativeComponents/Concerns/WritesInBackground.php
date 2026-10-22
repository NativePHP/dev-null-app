<?php

namespace App\NativeComponents\Concerns;

use App\Devnull\Client;
use App\Devnull\Exceptions\ApiException;
use App\Devnull\Exceptions\KeychainUnavailable;
use App\Devnull\Exceptions\Unauthenticated;
use App\Devnull\FetchInBackground;
use App\Devnull\SignedRequest;
use Closure;
use Throwable;

/**
 * A write to devnull that takes long enough to show it's under way, such as a
 * sync from GitHub, which waits on GitHub. It's signed here, on the UI thread,
 * where the Keychain is, and sent from a background PHP thread, so the screen
 * paints its spinner first (see FetchInBackground).
 *
 * A 401 signs the phone out, as any request does. Anything else that goes wrong
 * reaches $failed worded for the screen, by its code when the app knows it,
 * with the error itself for a screen that tells codes apart. NativePHP drops
 * the answer if the screen is no longer on top, and the next `GET /me` picks
 * up what changed.
 */
trait WritesInBackground
{
    /**
     * @param  array<string, mixed>|null  $payload
     * @param  Closure(array<string, mixed>): void  $done
     * @param  Closure(string, ?ApiException): void  $failed
     */
    protected function writeInBackground(string $method, string $path, ?array $payload, Closure $done, Closure $failed): void
    {
        $this->sendInBackground(fn (Client $devnull): SignedRequest => $devnull->signedWrite($method, $path, $payload), $done, $failed);
    }

    /**
     * Sends a write that $sign signs, like a commit with images, which can take
     * longer than $timeout seconds over a slow connection before it gives up.
     *
     * @param  Closure(Client): SignedRequest  $sign
     * @param  Closure(array<string, mixed>): void  $done
     * @param  Closure(string, ?ApiException): void  $failed
     */
    protected function sendInBackground(Closure $sign, Closure $done, Closure $failed, int $timeout = 30): void
    {
        try {
            $request = $sign(app(Client::class));
        } catch (KeychainUnavailable $exception) {
            $failed($exception->getMessage());

            return;
        }

        FetchInBackground::dispatch($request->url, $request->headers, $request->method, $request->body, false, null, $request->contentType)
            ->timeout($timeout)
            ->finished(function (mixed $result) use ($done, $failed): void {
                $result = is_array($result) ? $result : [];
                $status = (int) ($result['status'] ?? 0);
                $successful = $status >= 200 && $status < 300;

                if ($successful && ($result['from_devnull'] ?? false) === true) {
                    $done(is_array($result['json'] ?? null) ? $result['json'] : []);

                    return;
                }

                $exception = match (true) {
                    $status === 0 => ApiException::couldNotConnect((string) config('devnull.url')),
                    $successful => ApiException::unexpectedAnswer((string) config('devnull.url'), $status),
                    default => ApiException::fromParts($status, $result['json'] ?? null, (string) ($result['retry_after'] ?? '')),
                };

                if ($exception instanceof Unauthenticated) {
                    $this->signOut($exception);

                    return;
                }

                $failed($exception->firstError(), $exception);
            })
            ->failed(fn (Throwable $exception) => $failed('fatal: '.$exception->getMessage()));
    }
}
