<?php

namespace Tests\Support;

use Native\Mobile\UI\MenuItem;

/**
 * Whether the installed mobile-ui draws a subtitle under a menu row's label (`MenuItem::subtitle()`, from
 * NativePHP/mobile-ui#228). Without it the Post as menu puts the handle in the label, "Simon (@simon-hamp)",
 * so the tests of the subtitle skip.
 */
final class MenuSubtitle
{
    public static function supported(): bool
    {
        return class_exists(MenuItem::class);
    }

    public static function skipUnlessSupported(): void
    {
        if (! self::supported()) {
            test()->markTestSkipped('Needs a mobile-ui that draws menu row subtitles (NativePHP/mobile-ui#228)');
        }
    }

    public static function skipIfSupported(): void
    {
        if (self::supported()) {
            test()->markTestSkipped('The installed mobile-ui draws menu row subtitles, so the menu never falls back to one label');
        }
    }
}
