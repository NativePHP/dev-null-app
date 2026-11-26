<?php

namespace App\Devnull\Data;

/**
 * A guild's bulletin board as `GET /guilds/{handle}` answers it: the guild, a
 * page of its commits (30 roots, the pinned one among them), the pinned
 * commit, and whether you moderate it. Its commits are paged by number, which
 * the page carries as its cursor.
 */
final readonly class GuildBoard
{
    /**
     * @param  Page<Commit>  $commits
     */
    public function __construct(
        public Guild $guild,
        public Page $commits,
        public ?Commit $pinned,
        public bool $canModerate = false,
    ) {}

    /**
     * @param  array<string, mixed>  $json
     */
    public static function fromArray(array $json): ?self
    {
        $guild = is_array($json['guild'] ?? null) ? Guild::fromArray($json['guild']) : null;

        if ($guild === null) {
            return null;
        }

        return new self(
            guild: $guild,
            commits: Page::fromArray($json, Commit::fromArray(...)),
            pinned: is_array($json['pinned_commit'] ?? null) ? Commit::fromArray($json['pinned_commit']) : null,
            canModerate: ($json['can_moderate'] ?? false) === true,
        );
    }
}
