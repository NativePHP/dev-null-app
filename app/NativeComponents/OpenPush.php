<?php

namespace App\NativeComponents;

use App\Devnull\PushTap;

/**
 * What a tap on a push from devnull opens, whether it launched the app or the app was running. It checks the sign-in
 * as the first screen does, then swaps everything for the push's tab, which opens the push's screen on top so back
 * lands on the tab (see Concerns\OpensPushedScreen).
 */
class OpenPush extends Launch
{
    public const string Path = '/push';

    /**
     * A phone can still be signed in with no stored sign-in, as on Android after an update clears the app's cache.
     * Onboarding then signs back in with the phone's key by itself, and comes back here to open the push.
     */
    protected function signedOut(): void
    {
        $this->replace('/onboarding', ['then' => self::Path.'?'.http_build_query($this->query() ?? [], encoding_type: PHP_QUERY_RFC3986)]);
    }

    protected function openApp(): void
    {
        $tap = PushTap::from($this->query() ?? []);

        $this->replace($tap->tab, $tap->path === null ? [] : ['open' => $tap->path]);
    }
}
