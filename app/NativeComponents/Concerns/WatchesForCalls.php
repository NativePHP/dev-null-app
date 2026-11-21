<?php

namespace App\NativeComponents\Concerns;

use App\Devnull\Account;
use App\Devnull\Client;
use App\Devnull\Data\IncomingCall;
use App\Devnull\Exceptions\KeychainUnavailable;
use App\Devnull\FetchInBackground;
use App\NativeComponents\Call;
use Carbon\CarbonImmutable;
use Native\Mobile\Attributes\Poll;
use Native\Mobile\Edge\Transition;

/**
 * Rings while the screen is up, as the web checks for a call every three seconds on every page: a call ringing for
 * you opens the call screen to pick up or decline. One you already saw ring doesn't ring again.
 *
 * Each check is signed here, where the Keychain is, and sent from a background PHP thread (see FetchInBackground),
 * so the screen never waits on it. Asked here, a devnull that took the connection and never answered held the
 * whole app for the request's 20 seconds, and the next check was due by then, so no tap was answered until devnull
 * did. A check still out isn't sent again, and one that fails says nothing: the next one tries again.
 */
trait WatchesForCalls
{
    /**
     * How long a check may wait for devnull, in seconds. The next one is due long before an answer this late would
     * be worth anything, so it gives up and frees its background thread rather than wait out a request's usual time.
     */
    public const int CallCheckTimeout = 8;

    /**
     * When the check still out was sent, or null when none is. NativePHP drops the answer to one sent from a screen
     * that has gone under another, so a check older than its time counts as gone.
     */
    protected ?float $callCheckSentAt = null;

    #[Poll(3000)]
    public function checkForCall(): void
    {
        if (app(Account::class)->user() === null || $this->checkingForCall()) {
            return;
        }

        try {
            $request = app(Client::class)->signedGet(Client::IncomingCallPath);
        } catch (KeychainUnavailable) {
            // The screen's own requests say what's wrong; this tries again on the next check.
            return;
        }

        $this->callCheckSentAt = self::callCheckClock();

        FetchInBackground::dispatch($request->url, $request->headers, 'GET', '', false, null, 'application/json', self::CallCheckTimeout)
            ->timeout(self::CallCheckTimeout + 2)
            ->finished(function (mixed $result): void {
                $this->callCheckSentAt = null;

                if (FetchInBackground::failureOf($result) === null) {
                    $this->openRingingCall(Client::incomingCallIn(FetchInBackground::jsonOf($result)));
                }
            })
            ->failed(function (): void {
                $this->callCheckSentAt = null;
            });
    }

    private function checkingForCall(): bool
    {
        return $this->callCheckSentAt !== null && self::callCheckClock() - $this->callCheckSentAt < self::CallCheckTimeout + 2;
    }

    private static function callCheckClock(): float
    {
        return (float) CarbonImmutable::now()->format('U.u');
    }

    /**
     * Opens the call screen for a call ringing for you that hasn't rung here before.
     */
    private function openRingingCall(?IncomingCall $call): void
    {
        if ($call === null || $call->offer === '' || Call::hasSeen($call->offer)) {
            return;
        }

        $this->navigate('/call/'.rawurlencode($call->partner->username), [
            'name' => $call->partner->name,
            'avatar_url' => $call->partner->avatarUrl,
            'offer' => $call->offer,
            'is_muted' => $call->isMuted,
        ])->transition(Transition::SlideFromBottom);
    }
}
