<?php

namespace Tests\Support;

use Native\Mobile\Edge\TailwindParser;
use Native\Mobile\System;

/**
 * Whether the installed mobile-air draws iOS 26's scroll edge effect under a bottom bar (scroll-edge-soft) and says
 * which iOS the phone runs (System::osVersion()). Without them the composer bars keep their fade on every iOS, so the
 * tests of the scroll edge effect skip.
 */
final class ScrollEdge
{
    public static function supported(): bool
    {
        return method_exists(System::class, 'rememberOsVersion')
            && array_key_exists('scrollEdge', TailwindParser::parse('scroll-edge-soft'));
    }

    public static function skipUnlessSupported(): void
    {
        if (! self::supported()) {
            test()->markTestSkipped('Needs a mobile-air with scroll-edge-soft on a bottom bar and System::osVersion()');
        }
    }

    /**
     * Run $test as on a phone with this iOS, then forget it again.
     *
     * @template T
     *
     * @param  callable(): T  $test
     * @return T
     */
    public static function onIos(string $version, callable $test): mixed
    {
        self::skipUnlessSupported();

        System::rememberOsVersion($version);

        try {
            return $test();
        } finally {
            System::rememberOsVersion(null);
        }
    }
}
