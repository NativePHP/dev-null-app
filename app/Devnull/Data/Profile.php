<?php

namespace App\Devnull\Data;

final readonly class Profile
{
    public function __construct(
        public User $user,
        public int $commitsCount = 0,
        public int $followersCount = 0,
        public int $followingCount = 0,
        public bool $isFollowing = false,
        public bool $followsYou = false,
        public ?Commit $pinnedCommit = null,
        public Streaks $streaks = new Streaks,
        public Activity $activity = new Activity,
        public bool $isMuting = false,
        public ?Achievements $achievements = null,
    ) {}

    /**
     * Reads a `GET /users/{username}` response: the user and counts in `data`,
     * and alongside it whether you follow or mute them, their pinned commit,
     * their streaks, their activity and their achievements.
     *
     * @param  array<string, mixed>  $json
     */
    public static function fromArray(array $json): self
    {
        $data = is_array($json['data'] ?? null) ? $json['data'] : $json;
        $counts = is_array($data['counts'] ?? null) ? $data['counts'] : [];
        $pinned = $json['pinned_commit'] ?? $data['pinned_commit'] ?? null;

        return new self(
            user: User::fromArray(is_array($data['user'] ?? null) ? $data['user'] : $data),
            commitsCount: (int) ($data['commits_count'] ?? $counts['commits'] ?? 0),
            followersCount: (int) ($data['followers_count'] ?? $counts['followers'] ?? 0),
            followingCount: (int) ($data['following_count'] ?? $counts['following'] ?? 0),
            isFollowing: (bool) ($json['is_following'] ?? $data['is_following'] ?? false),
            followsYou: (bool) ($json['follows_you'] ?? $data['follows_you'] ?? false),
            pinnedCommit: is_array($pinned) ? Commit::fromArray($pinned) : null,
            streaks: Streaks::fromArray($json['streaks'] ?? $data['streaks'] ?? null),
            activity: Activity::fromArray($json['activity'] ?? $data['activity'] ?? null),
            isMuting: ($json['is_muting'] ?? $data['is_muting'] ?? false) === true,
            achievements: Achievements::fromArray($json['achievements'] ?? $data['achievements'] ?? null),
        );
    }

    public function withFollowing(bool $isFollowing): self
    {
        $change = $isFollowing === $this->isFollowing ? 0 : ($isFollowing ? 1 : -1);

        return new self(...[...get_object_vars($this), 'followersCount' => max(0, $this->followersCount + $change), 'isFollowing' => $isFollowing]);
    }

    public function withMuting(bool $isMuting): self
    {
        return new self(...[...get_object_vars($this), 'isMuting' => $isMuting]);
    }

    public function withPinnedCommit(?Commit $pinnedCommit): self
    {
        return new self(...[...get_object_vars($this), 'pinnedCommit' => $pinnedCommit]);
    }
}
