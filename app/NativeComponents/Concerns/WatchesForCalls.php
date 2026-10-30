<?php

namespace App\NativeComponents\Concerns;

use App\Devnull\Account;
use App\Devnull\Client;
use App\Devnull\Exceptions\ApiException;
use App\Devnull\Exceptions\KeychainUnavailable;
use App\NativeComponents\Call;
use Native\Mobile\Attributes\Poll;
use Native\Mobile\Edge\Transition;

/**
 * Rings while the screen is up, as the web checks for a call every three seconds on every page: a call ringing for
 * you opens the call screen to pick up or decline. One you already saw ring doesn't ring again.
 */
trait WatchesForCalls
{
    #[Poll(3000)]
    public function checkForCall(): void
    {
        if (app(Account::class)->user() === null) {
            return;
        }

        try {
            $call = app(Client::class)->incomingCall();
        } catch (ApiException|KeychainUnavailable) {
            // The screen's own requests say what's wrong; this tries again on the next check.
            return;
        }

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
