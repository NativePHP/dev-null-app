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

    protected function openApp(): void
    {
        $tap = PushTap::from($this->query() ?? []);

        $this->replace($tap->tab, $tap->path === null ? [] : ['open' => $tap->path]);
    }
}
