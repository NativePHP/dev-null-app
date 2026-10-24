<?php

namespace App\Devnull;

/**
 * A request signed with this device's key, ready to send from anywhere: the
 * method and full URL as signed, the headers carrying the signature, and the
 * exact body that was signed, empty for a GET, with its type: JSON, or
 * multipart for a commit with images. The signature is for this one
 * request, with its own nonce and a timestamp devnull only accepts for a few
 * minutes, and it's never kept.
 */
final readonly class SignedRequest
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(public string $url, public array $headers, public string $method = 'GET', public string $body = '', public string $contentType = 'application/json') {}
}
