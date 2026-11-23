<?php

namespace App\NativeComponents\Concerns;

/**
 * A tapped guild opens its own screen (see App\NativeComponents\Guild), in
 * the tab it was tapped in (see OpensInTab, which the screen uses too): from
 * a row of the directory, or the "posted in" pill on a commit.
 */
trait OpensGuilds
{
    public function openGuild(string $handle): void
    {
        $this->openInTab('/guilds/'.$handle);
    }
}
