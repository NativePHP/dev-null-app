<?php

namespace App\Devnull\Data;

/**
 * A page of a guild's members as `GET /guilds/{handle}/members` answers it,
 * with the id of the person moderating it, when someone is.
 */
final readonly class GuildMembers
{
    /**
     * @param  Page<Connection>  $people
     */
    public function __construct(public Page $people, public ?string $moderatorId) {}

    /**
     * @param  array<string, mixed>  $json
     */
    public static function fromArray(array $json): self
    {
        $moderatorId = $json['moderator_id'] ?? null;

        return new self(
            people: Page::fromArray($json, Connection::fromArray(...)),
            moderatorId: is_string($moderatorId) ? $moderatorId : null,
        );
    }
}
