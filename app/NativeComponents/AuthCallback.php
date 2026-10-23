<?php

namespace App\NativeComponents;

use App\Devnull\Account;
use App\Devnull\SignIn;
use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

/**
 * Where dev-null.sh sends the browser back to: devnull://auth/callback.
 *
 * NativePHP opens a deep link as a screen pushed over whatever is showing,
 * which during a sign-in is onboarding. This screen hands the answer to
 * onboarding and goes straight back there, so the stack stays as it was.
 * A signed-in phone ignores it: nothing asked for it.
 */
class AuthCallback extends NativeComponent
{
    public function mount(Account $account, SignIn $signIn): void
    {
        if ($account->user() === null) {
            $signIn->receive($this->callbackQuery());
        }

        if ($this->nativeRouter?->isRootScreen() ?? true) {
            $this->replace('/onboarding');

            return;
        }

        $this->back();
    }

    public function render(): View
    {
        return view('native.auth-callback');
    }

    /**
     * NativePHP keeps a deep link's query string on the route it pushed, but
     * gives a screen no way to read it other than the router's current URI.
     *
     * @return array<string, string>
     */
    private function callbackQuery(): array
    {
        parse_str((string) parse_url((string) $this->nativeRouter?->currentUri(), PHP_URL_QUERY), $query);

        return array_filter($query, is_string(...));
    }
}
