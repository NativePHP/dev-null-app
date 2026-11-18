<?php

namespace App\NativeComponents\Concerns;

use Native\Mobile\Attributes\On;
use Native\Mobile\Events\System\AppearanceChanged;

/**
 * A screen that draws something in the colours of the current appearance
 * itself, which EDGE won't switch for it, such as the fade above a composer
 * bar (see Display::composerFade()). Theme colours have a dark companion the
 * phone picks on its own, but a gradient's stops don't, so the screen draws
 * again when the phone goes light or dark.
 */
trait RedrawsOnAppearanceChange
{
    #[On(AppearanceChanged::class)]
    public function appearanceChanged(): void {}
}
