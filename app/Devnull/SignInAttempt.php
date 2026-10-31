<?php

namespace App\Devnull;

use SensitiveParameterValue;

/**
 * One go at signing in through dev-null.sh: OAuth's authorization code flow
 * with PKCE, devnull being the provider.
 *
 * The verifier and state are made fresh for each attempt and live only in
 * memory. The verifier is wrapped so it never shows up in a dump or a stack
 * trace, and can't be serialized to disk.
 */
final readonly class SignInAttempt
{
    public const string RedirectUri = 'devnull://auth/callback';

    private function __construct(private SensitiveParameterValue $verifier, public string $state) {}

    public static function start(): self
    {
        return new self(new SensitiveParameterValue(self::base64Url(random_bytes(32))), self::base64Url(random_bytes(16)));
    }

    public function verifier(): string
    {
        return $this->verifier->getValue();
    }

    /**
     * The S256 challenge: base64url(sha256(verifier)).
     */
    public function challenge(): string
    {
        return self::base64Url(hash('sha256', $this->verifier(), true));
    }

    /**
     * Whether a callback's state is this attempt's, compared in constant time.
     */
    public function answers(string $state): bool
    {
        return $state !== '' && hash_equals($this->state, $state);
    }

    /**
     * The page on dev-null.sh that signs you in with GitHub and approves this
     * phone's key, then sends the browser back to the app with a code. The
     * app's name goes with the phone's, so Settings → Devices tells them apart.
     */
    public function authorizeUrl(string $baseUrl, string $fingerprint, string $deviceName, string $appName): string
    {
        return rtrim($baseUrl, '/').'/mobile/authorize?'.http_build_query([
            'fingerprint' => $fingerprint,
            'device_name' => $deviceName,
            'app_name' => $appName,
            'code_challenge' => $this->challenge(),
            'code_challenge_method' => 'S256',
            'state' => $this->state,
            'redirect_uri' => self::RedirectUri,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
