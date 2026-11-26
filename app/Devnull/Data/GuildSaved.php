<?php

namespace App\Devnull\Data;

/**
 * What devnull did with a guild you created or edited: the guild as it now
 * stands, or, when its moderation held the guild or the change for an admin,
 * the web's message saying so, and no guild.
 */
final readonly class GuildSaved
{
    public function __construct(
        public ?Guild $guild,
        public ?string $heldMessage = null,
    ) {}

    /**
     * Whether it waits for an admin to approve it before it goes live.
     */
    public function isHeld(): bool
    {
        return $this->heldMessage !== null;
    }
}
