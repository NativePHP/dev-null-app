<?php

namespace App\Devnull\Data;

use App\Devnull\Display;

/**
 * Someone who to follow suggests, as the web lists them: with whether you
 * follow them now, whether they follow you, and the counts that say why
 * they're suggested.
 */
final readonly class SuggestedUser
{
    public string $id;

    public function __construct(
        public User $user,
        public int $followersCount = 0,
        public int $followedByFollowingCount = 0,
        public bool $followsYou = false,
        public bool $isFollowed = false,
    ) {
        $this->id = $user->id;
    }

    /**
     * Reads one of `GET /me/suggested-users`.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            user: User::fromArray($data),
            followersCount: is_int($data['followers_count'] ?? null) ? $data['followers_count'] : 0,
            followedByFollowingCount: is_int($data['followed_by_following_count'] ?? null) ? $data['followed_by_following_count'] : 0,
            followsYou: ($data['follows_you'] ?? false) === true,
            isFollowed: ($data['is_followed'] ?? false) === true,
        );
    }

    public function withFollowed(bool $isFollowed): self
    {
        return new self($this->user, $this->followersCount, $this->followedByFollowingCount, $this->followsYou, $isFollowed);
    }

    /**
     * Why they're suggested, as the web words it: how many people you follow
     * follow them, or else how many follow them at all.
     */
    public function reason(): ?string
    {
        return match (true) {
            $this->followedByFollowingCount > 0 => 'Followed by '.Display::compactCount($this->followedByFollowingCount).' you follow',
            $this->followersCount === 1 => '1 follower',
            $this->followersCount > 1 => Display::compactCount($this->followersCount).' followers',
            default => null,
        };
    }
}
