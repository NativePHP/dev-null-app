<?php

namespace App\Devnull;

use Native\Mobile\UI\Theme;

/**
 * An author's lane on the commit graph.
 *
 * Users carry their lane in the API, picked by them on the web or assigned by
 * devnull: one of the six in the palette (0 to 5), or a custom colour picked
 * with the web's colour picker, as lowercase `#rrggbb`. The hash below is the
 * web's old `laneFor()`, kept only for a server that doesn't send one.
 *
 * The palette's colours are `lane-0` to `lane-5` in the native-ui theme, so
 * `bg-theme-lane-{n}` picks the light or dark lift automatically. Each has an
 * `on-lane-{n}` for a button's label. A custom colour gets a token of its own
 * the first time it's drawn, so views draw any lane through token().
 */
final class Lane
{
    public const int Count = 6;

    /**
     * Rail Gray, which the graph draws for a deleted commit since it has no author.
     */
    public const string Rail = 'rail';

    /**
     * Reads a lane from the API: a palette lane, a custom colour as lowercase
     * `#rrggbb`, or null when it's missing or neither.
     */
    public static function from(mixed $value): int|string|null
    {
        if (is_string($value) && self::isCustom($value)) {
            return strtolower($value);
        }

        return self::palette($value);
    }

    /**
     * Reads a palette lane, 0 to 5, or null when it's missing or out of range.
     * Automatic only ever gives one of these.
     */
    public static function palette(mixed $value): ?int
    {
        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            return null;
        }

        $lane = (int) $value;

        return $lane >= 0 && $lane < self::Count ? $lane : null;
    }

    /**
     * Whether a lane is a custom colour rather than one of the palette's.
     */
    public static function isCustom(int|string|null $lane): bool
    {
        return is_string($lane) && preg_match('/^#[0-9a-f]{6}$/i', $lane) === 1;
    }

    /**
     * The theme token a lane is drawn in, which is also a button variant: your
     * lane's is what the tab bar, the main buttons and the pager tab underlines
     * wear. Commit Blue (primary) before devnull has said whose phone this is,
     * and Rail Gray for a deleted commit's.
     *
     * A custom colour is added to the theme as `lane-{rrggbb}` the first time
     * it's asked for, with an `on-` token for a button's label, so it works
     * wherever a palette lane's token does. Like the web, it draws the same in
     * light and dark mode.
     */
    public static function token(int|string|null $lane): string
    {
        return match (true) {
            $lane === null => 'primary',
            $lane === self::Rail => 'rail',
            is_string($lane) => self::isCustom($lane) ? self::customToken(strtolower($lane)) : 'primary',
            default => 'lane-'.$lane,
        };
    }

    public static function for(string $id): int
    {
        $hash = 0;

        foreach (mb_str_split($id) as $character) {
            $hash = ($hash * 31 + self::firstUtf16CodeUnit($character)) & 0xFFFFFFFF;
        }

        return $hash % self::Count;
    }

    private static function customToken(string $colour): string
    {
        $token = 'lane-'.substr($colour, 1);

        if (! is_string(Theme::get("light.{$token}"))) {
            Theme::merge([
                'light' => [$token => $colour, "on-{$token}" => self::label($colour, (string) Theme::get('light.ink'))],
                'dark' => [$token => $colour, "on-{$token}" => self::label($colour, (string) Theme::get('dark.on-primary'))],
            ]);
        }

        return $token;
    }

    /**
     * White or ink on a colour, whichever reads better, as the palette's
     * `on-lane-{n}` tokens are picked.
     */
    private static function label(string $colour, string $ink): string
    {
        if (preg_match('/^#[0-9a-f]{6}$/i', $ink) !== 1) {
            return '#ffffff';
        }

        return self::contrast('#ffffff', $colour) >= self::contrast($ink, $colour) ? '#ffffff' : $ink;
    }

    /**
     * WCAG 2's contrast ratio between two `#rrggbb` colours.
     */
    private static function contrast(string $first, string $second): float
    {
        [$a, $b] = [self::luminance($first), self::luminance($second)];

        return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
    }

    private static function luminance(string $colour): float
    {
        $channels = array_map(function (string $pair): float {
            $channel = hexdec($pair) / 255;

            return $channel <= 0.03928 ? $channel / 12.92 : (($channel + 0.055) / 1.055) ** 2.4;
        }, str_split(substr($colour, 1, 6), 2));

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    /**
     * JavaScript's `charCodeAt(0)` on one code point: the high surrogate above the BMP.
     */
    private static function firstUtf16CodeUnit(string $character): int
    {
        $codePoint = mb_ord($character, 'UTF-8');

        if ($codePoint === false) {
            return 0;
        }

        return $codePoint > 0xFFFF ? 0xD800 + (($codePoint - 0x10000) >> 10) : $codePoint;
    }
}
