<?php

namespace App\Devnull;

use Illuminate\Http\Client\ConnectionException;
use Native\Mobile\AsyncTask;

/**
 * Sends a request, signed on the UI thread, from one of NativePHP's background
 * PHP threads, so a refresh never holds up a tap, and a slow write can say it's
 * under way. It only carries bytes: the screen that asked decides what the
 * answer means and keeps it (see Concerns\RefreshesInBackground and
 * Concerns\WritesInBackground). Nothing here touches the Keychain or the cache.
 */
final class FetchInBackground extends AsyncTask
{
    /**
     * @param  array<string, string>  $headers
     * @return array{status: int, json: mixed, retry_after: string, from_devnull: bool}
     */
    public function handle(string $url, array $headers, string $method = 'GET', string $body = ''): array
    {
        try {
            $response = Client::sendSigned(new SignedRequest($url, $headers, $method, $body));
        } catch (ConnectionException) {
            return ['status' => 0, 'json' => null, 'retry_after' => '', 'from_devnull' => false];
        }

        return [
            'status' => $response->status(),
            'json' => $response->json(),
            'retry_after' => $response->header('Retry-After'),
            'from_devnull' => Client::answeredByDevnull($response, $method),
        ];
    }
}
