<?php

namespace Tests\Support;

use Native\Mobile\Edge\TailwindParser;

/**
 * Whether the installed mobile-air reads an opacity suffix on the glass class (glass/80, from
 * NativePHP/mobile-air#735). Without it the composer bars' glass/N isn't parsed at all, so their glass tests skip.
 */
final class GlassOpacity
{
    public static function supported(): bool
    {
        return array_key_exists('glassOpacity', TailwindParser::parse('glass/80'));
    }

    public static function skipUnlessSupported(): void
    {
        if (! self::supported()) {
            test()->markTestSkipped('Needs a mobile-air that reads glass/N (NativePHP/mobile-air#735)');
        }
    }
}
