<?php

namespace App\NativeComponents\Concerns;

/**
 * A tapped guild opens its own screen (see App\NativeComponents\Guild), in
 * the tab it was tapped in (see OpensInTab, which the screen uses too): from
 * a row of the directory, the "posted in" pill on a commit, or a hashtag that
 * names a guild in a commit or a message.
 */
trait OpensGuilds
{
    public function openGuild(string $handle): void
    {
        $this->openInTab('/guilds/'.$handle);
    }
}
