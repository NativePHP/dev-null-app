<?php

namespace App\NativeComponents;

use App\Devnull\Account;
use App\Devnull\DeviceKey;
use App\Devnull\Exceptions\KeychainUnavailable;
use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

/**
 * The first screen: straight to the log for a verified device, otherwise onboarding.
 *
 * A verified device whose key has gone, as on a new phone restored from a
 * backup, or when the build's signing team changed and the Keychain with it,
 * is signed out and told why. The log would otherwise make a new key quietly
 * on its first request and land on onboarding with only a 401 to explain it.
 *
 * A dev-null.sh link that started the app comes here first (see WebLink), and
 * goes on to the log to open. A phone that isn't signed in drops it.
 */
class Launch extends NativeComponent
{
    public const string KeyGone = "This phone no longer has the key you signed in with, so you've been signed out. Sign in again and it makes a new one.";

    public function mount(Account $account, DeviceKey $deviceKey): void
    {
        if ($account->user() === null) {
            $this->replace('/onboarding');

            return;
        }

        if ($this->keyIsGone($deviceKey)) {
            $account->forget();
            $this->replace('/onboarding', ['message' => self::KeyGone]);

            return;
        }

        $this->replace('/log', array_filter(['link' => $this->data('link')]));
    }

    public function render(): View
    {
        return view('native.launch');
    }

    /**
     * A locked or failing Keychain isn't a missing key, so that still goes to
     * the log, where the first request says what's wrong.
     */
    private function keyIsGone(DeviceKey $deviceKey): bool
    {
        try {
            return $deviceKey->existing() === null;
        } catch (KeychainUnavailable) {
            return false;
        }
    }
}
