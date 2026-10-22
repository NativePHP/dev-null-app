<?php

namespace Devnull\CommitRow;

use InvalidArgumentException;
use Native\Mobile\Edge\TailwindParser;
use Native\Mobile\UI\Theme;

/**
 * A colour as the row draws it: one value for light mode and one for dark.
 *
 * Both are wire hex (#RRGGBB, or #AARRGGBB with alpha), as the native colour
 * parsers read them. On the wire a pair is a list of the two strings.
 */
final class ColorPair
{
    /**
     * @var array<string, self>
     */
    private static array $tokens = [];

    public function __construct(
        public readonly string $light,
        public readonly string $dark,
    ) {}

    /**
     * A theme token's two values, as a `text-theme-*` or `bg-theme-*` class
     * would set them.
     */
    public static function theme(string $token): self
    {
        if (isset(self::$tokens[$token])) {
            return self::$tokens[$token];
        }

        $theme = Theme::all();
        $light = $theme['light'][$token] ?? null;
        $dark = $theme['dark'][$token] ?? null;

        if (! is_string($light) || ! is_string($dark)) {
            throw new InvalidArgumentException("The theme has no colour token [{$token}].");
        }

        // Tokens are only ever added at runtime (custom lane colours), never
        // changed, so a token's pair can be kept once it exists.
        return self::$tokens[$token] = new self($light, $dark);
    }

    /**
     * Any colour a class could name (#hex, a palette name, an opacity
     * modifier), the same in both modes unless a dark one is given.
     */
    public static function of(string $light, ?string $dark = null): self
    {
        $resolvedLight = TailwindParser::resolveColorValue($light);
        $resolvedDark = TailwindParser::resolveColorValue($dark ?? $light);

        if ($resolvedLight === null || $resolvedDark === null) {
            throw new InvalidArgumentException("[{$light}] isn't a colour.");
        }

        return new self($resolvedLight, $resolvedDark);
    }

    /**
     * @return list<string>
     */
    public function toWire(): array
    {
        return [$this->light, $this->dark];
    }
}
