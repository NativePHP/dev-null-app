<?php

namespace App\NativeComponents\Concerns;

use App\Devnull\Account;
use App\Devnull\Client;
use App\Devnull\DeviceKey;
use Closure;
use Native\Mobile\Facades\Dialog;

/**
 * Signing out of this phone, and deleting the account altogether, each after a confirm. The screen's alert handler
 * calls forget() or deleteAccount() when the confirm's destructive button is pressed.
 */
trait LeavesDevnull
{
    public const string SignOutAlert = 'sign-out';

    public const string DeleteAccountAlert = 'delete-account';

    /**
     * @template TResult
     *
     * @param  Closure(Client): TResult  $call
     * @return TResult|null
     */
    abstract protected function attempt(Closure $call): mixed;

    public function confirmSignOut(): void
    {
        Dialog::alert(
            'Sign out?',
            'This deletes the key from this phone. The phone stays listed on dev-null.sh until you remove it there, and signing in again makes a new key.',
            [
                ['label' => 'Cancel', 'style' => 'cancel'],
                ['label' => 'Sign out', 'style' => 'destructive'],
            ],
        )->id(self::SignOutAlert);
    }

    public function confirmDeleteAccount(): void
    {
        Dialog::alert(
            'Delete your account?',
            'Your profile, commits, replies, stars and follows are removed from /dev/null. There is no undo.',
            [
                ['label' => 'Cancel', 'style' => 'cancel'],
                ['label' => 'Delete', 'style' => 'destructive'],
            ],
        )->id(self::DeleteAccountAlert);
    }

    /**
     * Signs out: the key goes from this phone, and with it who you are and
     * every answer kept for you (see Account::forget).
     */
    public function forget(): void
    {
        app(DeviceKey::class)->forget();
        app(Account::class)->forget();

        $this->replace('/onboarding');
    }

    /**
     * Deletes the account on devnull, which takes its devices with it, then
     * signs out. When devnull can't, the error shows and you stay signed in.
     */
    public function deleteAccount(): void
    {
        $deleted = $this->attempt(function (Client $devnull): bool {
            $devnull->deleteAccount();

            return true;
        });

        if ($deleted === true) {
            $this->forget();
        }
    }
}
