<?php

namespace App\NativeComponents;

use App\Devnull\Account;
use App\Devnull\AppleSignIn;
use App\Devnull\Client;
use App\Devnull\Data\User;
use App\Devnull\DeviceKey;
use App\Devnull\Exceptions\ApiException;
use App\Devnull\Exceptions\KeychainUnavailable;
use App\Devnull\SignIn;
use App\NativeComponents\Concerns\SignsInWithApple;
use App\NativeComponents\Layouts\TabsLayout;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Native\Mobile\Attributes\Poll;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Facades\Browser;
use Native\Mobile\Facades\Device;
use SensitiveParameter;

/**
 * This phone has its own SSH key. Approve it once on dev-null.sh and you're signed in.
 *
 * The screen is the logo and the sign-in buttons, nothing else. A short error
 * shows above the buttons only after an attempt goes wrong. When a 401 or a
 * lost key sends someone here, the reason isn't shown: the buttons are enough.
 *
 * Sign in on dev-null.sh opens it in the system's auth browser, which
 * shares Safari's cookies, so someone already signed in there only taps
 * Approve. dev-null.sh sends the browser back to devnull://auth/callback with
 * a code, and the app trades it for the user with a signed request, proving
 * with PKCE that this phone started the sign-in.
 *
 * On iOS, Sign in with Apple is the other way in, for an Apple ID already
 * linked to a devnull account from settings. It never makes an account:
 * devnull's accounts are GitHub ones.
 */
class Onboarding extends NativeComponent
{
    use SignsInWithApple;

    public const string NotStartedHere = "fatal: that sign-in didn't start on this phone, so it was ignored.";

    public string $fingerprint = '';

    public ?string $error = null;

    /**
     * Kept apart from $error, so a failed Apple sign-in doesn't turn the
     * dev-null.sh button into "Try again".
     */
    public ?string $appleError = null;

    private string $deviceName = '';

    /**
     * Set when nothing sent this phone here, yet it already had a key: only
     * the stored sign-in may be gone, so the first tick asks devnull who it is.
     */
    private bool $signInOnTick = false;

    public function mount(DeviceKey $deviceKey): void
    {
        $sentWithReason = $this->data('message') !== null;

        try {
            $existing = $deviceKey->existing();
            $key = $existing ?? $deviceKey->current();
        } catch (KeychainUnavailable $exception) {
            $this->error = $exception->getMessage();

            return;
        }

        $this->fingerprint = $key->fingerprint();

        if (config('app.debug')) {
            Log::info('devnull device key', ['public_key' => $key->openSsh(), 'fingerprint' => $this->fingerprint]);
        }

        $this->signInOnTick = $existing !== null && ! $sentWithReason && ! app(SignIn::class)->hasCallback();

        $this->finishSignIn();
    }

    /**
     * Back from the browser: the callback screen left the answer and popped.
     */
    public function onResume(): void
    {
        $this->finishSignIn();
    }

    /**
     * Opens dev-null.sh with a fresh verifier and state. Dismissing the sheet
     * sends nothing back, so the screen simply stays as it is.
     */
    public function signIn(): void
    {
        if ($this->fingerprint === '') {
            return;
        }

        $this->clearMessages();

        $attempt = app(SignIn::class)->begin();

        if (! Browser::auth($attempt->authorizeUrl((string) config('devnull.url'), $this->fingerprint, $this->deviceName()))) {
            $this->error = 'fatal: could not open dev-null.sh to sign in.';
        }
    }

    /**
     * Opens Apple's sheet. Its answer arrives as an event (see SignsInWithApple).
     */
    public function signInWithApple(): void
    {
        if ($this->fingerprint === '' || ! AppleSignIn::isAvailable()) {
            return;
        }

        $this->clearMessages();
        $this->startAppleSignIn();
    }

    #[Poll(1000)]
    public function tick(): void
    {
        if (! $this->signInOnTick) {
            return;
        }

        $this->signInOnTick = false;
        $this->signInWithExistingKey();
    }

    public function render(): View
    {
        return view('native.onboarding', ['logo' => TabsLayout::logo(), 'offersApple' => AppleSignIn::isAvailable()]);
    }

    /**
     * Trades Apple's identity token for the account its Apple ID is linked
     * to, which approves this phone's key. With no account linked, devnull's
     * own message says to sign in with GitHub and link Apple in settings.
     */
    protected function useAppleToken(#[SensitiveParameter] string $identityToken, #[SensitiveParameter] string $nonce): void
    {
        try {
            $user = app(Client::class)->signInWithApple($identityToken, $nonce, $this->deviceName());
        } catch (ApiException $exception) {
            $this->appleError = $exception->firstError();

            return;
        } catch (KeychainUnavailable $exception) {
            $this->appleError = $exception->getMessage();

            return;
        }

        app(Account::class)->remember($user);
        $this->keepProfile($user);
        $this->replace('/log');
    }

    protected function appleFailed(string $message): void
    {
        $this->appleError = $message;
    }

    private function clearMessages(): void
    {
        $this->error = null;
        $this->appleError = null;
        $this->signInOnTick = false;
    }

    /**
     * Checks the browser's answer against the attempt this phone started,
     * then trades the code for the user. Whatever happens, that attempt is
     * spent: a retry starts a new one.
     */
    private function finishSignIn(): void
    {
        $taken = app(SignIn::class)->take();

        if ($taken === null) {
            return;
        }

        [$callback, $attempt] = $taken;

        if ($attempt === null || ! $attempt->answers($callback['state'] ?? '')) {
            $this->error = self::NotStartedHere;

            return;
        }

        $error = $callback['error'] ?? '';

        // Cancelling on dev-null.sh isn't a failure, so it says nothing.
        if ($error === 'access_denied') {
            return;
        }

        if ($error !== '') {
            $this->error = $callback['error_description'] ?? "fatal: dev-null.sh could not sign you in ({$error}).";

            return;
        }

        $code = $callback['code'] ?? '';

        if ($code === '') {
            $this->error = 'fatal: dev-null.sh sent no code back.';

            return;
        }

        try {
            $user = app(Client::class)->redeemSignIn($code, $attempt->verifier());
        } catch (ApiException $exception) {
            $this->error = $exception->firstError();

            return;
        } catch (KeychainUnavailable $exception) {
            $this->error = $exception->getMessage();

            return;
        }

        app(Account::class)->remember($user);
        $this->keepProfile($user);
        $this->replace('/log');
    }

    /**
     * A key devnull already knows and approved signs in with nothing to do,
     * as after the app's stored sign-in was lost. Any other answer leaves
     * onboarding as it is, without an error: there was nothing to ask for.
     */
    private function signInWithExistingKey(): void
    {
        try {
            $current = app(Client::class)->me();
        } catch (ApiException|KeychainUnavailable) {
            return;
        }

        if ($current->user->username === '') {
            return;
        }

        app(Account::class)->remember($current->user);
        app(Account::class)->rememberUnreadNotifications($current->unreadNotificationsCount);
        app(Account::class)->rememberPasteis($current->pasteis());
        app(Account::class)->rememberRemotes($current->remotes);
        $this->keepProfile($current->user);
        $this->replace('/log');
    }

    /**
     * Keeps your profile on the phone as you sign in (see Account::profile()),
     * so it shows when devnull can't be reached later. Signing in doesn't wait
     * on it working.
     */
    private function keepProfile(User $user): void
    {
        try {
            app(Account::class)->rememberProfile(app(Client::class)->profileJson($user->username));
        } catch (ApiException|KeychainUnavailable) {
            //
        }
    }

    /**
     * Names the phone on devnull after its model, so it's clear what to remove later.
     */
    private function deviceName(): string
    {
        if ($this->deviceName === '') {
            $info = json_decode((string) Device::getInfo(), true);
            $model = is_array($info) && is_string($info['model'] ?? null) ? trim($info['model']) : '';

            $this->deviceName = 'devnull on '.($model === '' ? 'a phone' : $model);
        }

        return $this->deviceName;
    }
}
