<?php

namespace App\Devnull\Data;

/**
 * A profile's achievements as the web's shelf and dialog show them: the Unix
 * rank and its level, the XP towards the next rank, every rank, and the
 * badges, unlocked or not.
 */
final readonly class Achievements
{
    /**
     * @param  list<Rank>  $ranks
     * @param  list<Badge>  $badges
     */
    public function __construct(
        public int $level = 0,
        public string $rank = '',
        public int $xp = 0,
        public ?int $nextLevelXp = null,
        public int $levelProgress = 0,
        public array $ranks = [],
        public array $badges = [],
    ) {}

    /**
     * Reads the profile's `achievements`, or null when devnull sent none.
     */
    public static function fromArray(mixed $data): ?self
    {
        if (! is_array($data) || ! is_int($data['level'] ?? null) || ! is_string($data['rank'] ?? null) || $data['rank'] === '') {
            return null;
        }

        return new self(
            level: $data['level'],
            rank: $data['rank'],
            xp: is_int($data['xp'] ?? null) ? max(0, $data['xp']) : 0,
            nextLevelXp: is_int($data['next_level_xp'] ?? null) ? $data['next_level_xp'] : null,
            levelProgress: is_int($data['level_progress'] ?? null) ? max(0, min(100, $data['level_progress'])) : 0,
            ranks: array_values(array_filter(array_map(Rank::fromArray(...), is_array($data['ranks'] ?? null) ? $data['ranks'] : []))),
            badges: array_values(array_map(Badge::fromArray(...), array_filter(is_array($data['badges'] ?? null) ? $data['badges'] : [], is_array(...)))),
        );
    }

    /**
     * "Lv. 3 · operator", as the badge beside the handle reads.
     */
    public function label(): string
    {
        return "Lv. {$this->level} · {$this->rank}";
    }

    /** @return list<Badge> */
    public function unlockedBadges(): array
    {
        return array_values(array_filter($this->badges, fn (Badge $badge): bool => $badge->unlocked));
    }

    public function unlockedCount(): int
    {
        return count($this->unlockedBadges());
    }

    /**
     * "120 / 250 XP", or just the XP at the top rank.
     */
    public function xpLabel(): string
    {
        return $this->nextLevelXp === null ? "{$this->xp} XP" : "{$this->xp} / {$this->nextLevelXp} XP";
    }

    /**
     * "130 XP to operator", or the web's line for the top rank.
     */
    public function nextRankLabel(): string
    {
        $next = $this->nextRank();

        return $next === null ? 'Maximum rank (UID 0)' : ($next->minXp - $this->xp).' XP to '.$next->rank;
    }

    /**
     * The rank a level above this one, as the web finds it, or null at the top.
     */
    public function nextRank(): ?Rank
    {
        foreach ($this->ranks as $rank) {
            if ($rank->level === $this->level + 1) {
                return $rank;
            }
        }

        return null;
    }

    /**
     * Where the badge with this id sits on the shelf, or null when there's none.
     */
    public function badgeIndex(string $id): ?int
    {
        foreach ($this->badges as $index => $badge) {
            if ($badge->id === $id) {
                return $index;
            }
        }

        return null;
    }
}
