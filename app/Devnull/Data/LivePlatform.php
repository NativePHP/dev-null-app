<?php

namespace App\Devnull\Data;

/**
 * Where someone streams, as devnull names it.
 */
enum LivePlatform: string
{
    case Twitch = 'twitch';
    case YouTube = 'youtube';
}
