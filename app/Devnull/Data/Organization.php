<?php

namespace App\Devnull\Data;

/**
 * An organization's page, as `GET /organizations/{handle}` sends it: the
 * organization with the fields a user has, how many follow it, its admins,
 * first one first, and whether you follow it.
 */
final readonly class Organization
{
    /**
     * @param  list<User>  $admins
     */
    public function __construct(
        public User $organization,
        public int $followersCount = 0,
        public array $admins = [],
        public bool $isFollowing = false,
    ) {}

    /**
     * @param  array<string, mixed>  $json
     */
    public static function fromArray(array $json): self
    {
        $data = is_array($json['data'] ?? null) ? $json['data'] : [];
        $admins = is_array($data['admins'] ?? null) ? $data['admins'] : [];

        return new self(
            organization: User::fromArray(['type' => 'organization', ...$data]),
            followersCount: max(0, (int) ($data['followers_count'] ?? 0)),
            admins: array_values(array_map(User::fromArray(...), array_filter($admins, fn (mixed $admin): bool => is_array($admin) && is_string($admin['handle'] ?? null) && $admin['handle'] !== ''))),
            isFollowing: ($json['is_following'] ?? $data['is_following'] ?? false) === true,
        );
    }

    /**
     * Followed or unfollowed here before devnull answers, with the count moved to match, as the web does.
     */
    public function withFollowing(bool $isFollowing): self
    {
        $change = $isFollowing === $this->isFollowing ? 0 : ($isFollowing ? 1 : -1);

        return new self($this->organization, max(0, $this->followersCount + $change), $this->admins, $isFollowing);
    }
}
