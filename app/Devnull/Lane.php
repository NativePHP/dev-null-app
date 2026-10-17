<?php

namespace App\Devnull;

/**
 * An author's lane on the commit graph.
 *
 * Users carry their lane (0 to 5) in the API, picked by them on the web or
 * assigned by devnull. The hash below is the web's old `laneFor()`, kept only
 * for a server that doesn't send one.
 *
 * The colours themselves are `lane-0` to `lane-5` in the native-ui theme, so
 * `bg-theme-lane-{n}` picks the light or dark lift automatically. Each has an
 * `on-lane-{n}` for a button's label.
 */
final class Lane
{
    public const int Count = 6;

    /**
     * Rail Gray, which the graph draws for a deleted commit since it has no author.
     */
    public const string Rail = 'rail';

    /**
     * Reads a lane from the API, or null when it's missing or out of range.
     */
    public static function from(mixed $value): ?int
    {
        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            return null;
        }

        $lane = (int) $value;

        return $lane >= 0 && $lane < self::Count ? $lane : null;
    }

    /**
     * The theme token a lane is drawn in, which is also a button variant: your
     * lane's is what the tab bar, the main buttons and the pager tab underlines
     * wear. Commit Blue (primary) before devnull has said whose phone this is.
     */
    public static function token(?int $lane): string
    {
        return $lane === null ? 'primary' : 'lane-'.$lane;
    }

    public static function for(string $id): int
    {
        $hash = 0;

        foreach (mb_str_split($id) as $character) {
            $hash = ($hash * 31 + self::firstUtf16CodeUnit($character)) & 0xFFFFFFFF;
        }

        return $hash % self::Count;
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
