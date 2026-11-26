<?php

namespace Tests\Support;

use Native\Mobile\Edge\Elements\ScrollView;

/**
 * Whether the installed mobile-air has layout animation and the auto-scroll token (NativePHP/mobile-air#736).
 * Without it the scroll view's animate-layout, auto-scroll-animated and auto-scroll-token never reach the tree, so
 * the tests that read them skip.
 */
final class LayoutAnimation
{
    public static function supported(): bool
    {
        return method_exists(ScrollView::class, 'autoScrollToken');
    }

    public static function skipUnlessSupported(): void
    {
        if (! self::supported()) {
            test()->markTestSkipped('Needs a mobile-air with layout animation (NativePHP/mobile-air#736)');
        }
    }
}
