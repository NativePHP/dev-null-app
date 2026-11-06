<?php

namespace App\Devnull;

use App\Devnull\Exceptions\KeychainUnavailable;
use Carbon\CarbonImmutable;

/**
 * The sign-in under way.
 *
 * It keeps the attempt the browser is showing, and the browser's answer once
 * devnull://auth/callback brings it back, until onboarding takes both. A new
 * attempt replaces the old one, and an answer is taken once, so each attempt
 * is good for one answer whatever it says.
 *
 * The attempt is also kept in the Keychain until it's answered. Phones short
 * on memory, Xiaomi's above all, kill the app while the browser is in front,
 * and the callback then lands in a fresh process that would know of no
 * attempt. Only the Keychain holds it, it's gone once taken, and one older
 * than KeptFor is never used. The browser's answer stays in memory.
 */
final class SignIn
{
    public const string StorageKey = 'devnull.sign-in';

    /**
     * How long a kept attempt can still be answered, in seconds.
     */
    public const int KeptFor = 600;

    private ?SignInAttempt $attempt = null;

    /**
     * @var array<string, string>|null
     */
    private ?array $callback = null;

    public function __construct(private KeyStore $store) {}

    public function begin(): SignInAttempt
    {
        $this->callback = null;
        $this->attempt = SignInAttempt::start();
        $this->keep($this->attempt);

        return $this->attempt;
    }

    /**
     * @param  array<string, string>  $query
     */
    public function receive(array $query): void
    {
        $this->callback = $query;
    }

    public function hasCallback(): bool
    {
        return $this->callback !== null;
    }

    /**
     * The browser's answer and the attempt it should answer, forgetting both.
     * The attempt comes from the Keychain when the app was started afresh.
     *
     * @return array{0: array<string, string>, 1: SignInAttempt|null}|null
     */
    public function take(): ?array
    {
        if ($this->callback === null) {
            return null;
        }

        $taken = [$this->callback, $this->attempt ?? $this->kept()];
        $this->callback = null;
        $this->attempt = null;
        $this->forgetKept();

        return $taken;
    }

    /**
     * Keeping it is a fallback: when the Keychain won't take it, the attempt
     * still works for as long as the app stays alive.
     */
    private function keep(SignInAttempt $attempt): void
    {
        try {
            $this->store->set(self::StorageKey, (string) json_encode([
                'verifier' => $attempt->verifier(),
                'state' => $attempt->state,
                'started_at' => CarbonImmutable::now()->getTimestamp(),
            ]));
        } catch (KeychainUnavailable) {
            //
        }
    }

    private function kept(): ?SignInAttempt
    {
        try {
            $stored = $this->store->read(self::StorageKey);
        } catch (KeychainUnavailable) {
            return null;
        }

        $kept = $stored->found() ? json_decode((string) $stored->value, true) : null;

        if (! is_array($kept) || ! is_string($kept['verifier'] ?? null) || ! is_string($kept['state'] ?? null) || ! is_int($kept['started_at'] ?? null)) {
            return null;
        }

        if (CarbonImmutable::now()->getTimestamp() - $kept['started_at'] > self::KeptFor) {
            return null;
        }

        return SignInAttempt::resume($kept['verifier'], $kept['state']);
    }

    private function forgetKept(): void
    {
        try {
            $this->store->delete(self::StorageKey);
        } catch (KeychainUnavailable) {
            //
        }
    }
}
