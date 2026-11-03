<?php

namespace App\Devnull\NataNullers;

use Native\Mobile\Skia\SkiaServiceProvider;

/**
 * Nata Nu//ers, the Easter egg: catch falling pastéis de nata, jump the
 * cyclists, birds, the odd wild Nuno and an octocat.
 *
 * It's drawn with the paid Skia plugin, which `composer setup:free` takes out,
 * so everything that leads to the game asks available() first. The
 * `devnull.nata_nullers` setting can hide it from a build that has the plugin. Score cards in
 * the feed still show without it; they just don't offer a game.
 */
final class Game
{
    public const string Route = '/nata-nullers';

    /**
     * The name as written, with /dev/null's slashes for the ls.
     */
    public const string Name = 'Nata Nu//ers';

    /**
     * The name as said: Nata Nullers. Screen readers get this, not slashes.
     */
    public const string SpokenName = 'Nata Nullers';

    public static function available(): bool
    {
        return config('devnull.nata_nullers', true) !== false && class_exists(SkiaServiceProvider::class);
    }
}
