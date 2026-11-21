<?php

namespace Tests\Support;

use Native\Mobile\UI\Caret;

/**
 * Whether the installed mobile-ui has a multiline field that fills its height and reports where its caret is drawn
 * (`h-full` and `@caretChange`, from NativePHP/mobile-ui#227). Without it the composer sheet's field is as
 * tall as its lines and the mention list sits under the first line, so the tests of both skip.
 */
final class TextFieldFill
{
    public static function supported(): bool
    {
        return class_exists(Caret::class);
    }

    public static function skipUnlessSupported(): void
    {
        if (! self::supported()) {
            test()->markTestSkipped('Needs a mobile-ui whose multiline field fills and reports its caret (NativePHP/mobile-ui#227)');
        }
    }
}
