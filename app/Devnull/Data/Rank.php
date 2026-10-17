<?php

namespace App\Devnull\Data;

/**
 * One of the Unix ranks a profile climbs through by XP, as the web's
 * achievements dialog lists them.
 */
final readonly class Rank
{
    public function __construct(
        public string $rank,
        public int $level,
        public int $minXp = 0,
        public string $description = '',
        public bool $achieved = false,
    ) {}

    /**
     * Reads one of the profile's `achievements.ranks`, or null when it isn't one.
     */
    public static function fromArray(mixed $data): ?self
    {
        if (! is_array($data) || ! is_string($data['rank'] ?? null) || ! is_int($data['level'] ?? null) || ! is_int($data['min_xp'] ?? null)) {
            return null;
        }

        return new self(
            rank: $data['rank'],
            level: $data['level'],
            minXp: $data['min_xp'],
            description: is_string($data['description'] ?? null) ? $data['description'] : '',
            achieved: ($data['achieved'] ?? false) === true,
        );
    }
}
