<?php

namespace Tests\Support;

use Native\Mobile\Edge\Layouts\Builders\NavAction;

/**
 * Whether the installed core can give a menu row a subtitle (`NavAction::subtitle()`, from NativePHP/mobile-air#740;
 * mobile-ui draws it since NativePHP/mobile-ui#228). Without it the Post as menu puts the handle in the label,
 * "Simon (@simon-hamp)", so the tests of the subtitle skip.
 */
final class MenuSubtitle
{
    public static function supported(): bool
    {
        return method_exists(NavAction::class, 'subtitle');
    }

    public static function skipUnlessSupported(): void
    {
        if (! self::supported()) {
            test()->markTestSkipped('Needs a core with NavAction::subtitle() (NativePHP/mobile-air#740)');
        }
    }

    public static function skipIfSupported(): void
    {
        if (self::supported()) {
            test()->markTestSkipped('The installed core has NavAction::subtitle(), so the menu never falls back to one label');
        }
    }
}
