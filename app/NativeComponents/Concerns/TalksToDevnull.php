<?php

namespace App\NativeComponents\Concerns;

use App\Devnull\Account;
use App\Devnull\Client;
use App\Devnull\Data\User;
use App\Devnull\Exceptions\ApiException;
use App\Devnull\Exceptions\KeychainUnavailable;
use App\Devnull\Exceptions\Unauthenticated;
use App\Devnull\Lane;
use Closure;

/**
 * Calls the devnull API from a screen.
 *
 * A 401 means devnull no longer accepts this phone's key, so the screen
 * hands over to onboarding with the reason, worded by its code (see
 * ErrorCode). When devnull only wants the phone approved, that says signing
 * in approves it. Any other failure lands in $error for the screen to print.
 */
trait TalksToDevnull
{
    public ?string $error = null;

    /**
     * @template TResult
     *
     * @param  Closure(Client): TResult  $call
     * @return TResult|null
     */
    protected function attempt(Closure $call): mixed
    {
        try {
            $result = $call(app(Client::class));
            $this->error = null;

            return $result;
        } catch (Unauthenticated $exception) {
            $this->signOut($exception);
        } catch (ApiException $exception) {
            $this->error = $exception->firstError();
        } catch (KeychainUnavailable $exception) {
            $this->error = $exception->getMessage();
        }

        return null;
    }

    /**
     * devnull no longer takes this phone's key: sign out, and say why on onboarding.
     */
    protected function signOut(Unauthenticated $exception): void
    {
        app(Account::class)->forget();

        $this->replace('/onboarding', ['message' => $exception->firstError()]);
    }

    protected function me(): ?User
    {
        return app(Account::class)->user();
    }

    /**
     * The theme token for your lane, which the main buttons and the pager tab
     * underlines are drawn in.
     */
    protected function myColour(): string
    {
        return Lane::token($this->me()?->lane());
    }
}
