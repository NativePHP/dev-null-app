<?php

namespace App\Devnull\Data;

final readonly class Profile
{
    /**
     * @param  array<string, string>  $bioProfiles  the handles their bio and company tag that have a page on devnull, by lowercase handle
     * @param  array<string, string>  $bioProfileTypes  what each of those is, by lowercase handle, when devnull says: user, organization or unclaimed
     */
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
        public ?int $pasteisReceived = null,
        public array $bioProfiles = [],
        public array $bioProfileTypes = [],
    ) {}

    /**
     * Reads a `GET /users/{username}` response: the user and counts in `data`,
     * and alongside it whether you follow or mute them, their pinned commit,
     * their streaks, their activity, their achievements and how many pastéis
     * they have been given (null when devnull doesn't say), and which handles
     * their bio and company tag have a page on devnull.
     *
     * @param  array<string, mixed>  $json
     */
    public static function fromArray(array $json): self
    {
        $data = is_array($json['data'] ?? null) ? $json['data'] : $json;
        $counts = is_array($data['counts'] ?? null) ? $data['counts'] : [];
        $pinned = $json['pinned_commit'] ?? $data['pinned_commit'] ?? null;
        $received = $json['pasteis_received'] ?? $data['pasteis_received'] ?? null;

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
            pasteisReceived: is_int($received) ? max(0, $received) : null,
            bioProfiles: self::handles($json['bio_profiles'] ?? $data['bio_profiles'] ?? null),
            bioProfileTypes: self::handles($json['bio_profile_types'] ?? $data['bio_profile_types'] ?? null),
        );
    }

    /**
     * Where a handle tagged in their bio or company opens: 'organization' or 'user' for a page on devnull the app has a
     * screen for, or null for GitHub. devnull sends which handles have a page (bio_profiles) but not yet what each is,
     * so a page of unknown kind opens as a user, whose screen hands over to the organization's when devnull has no
     * such user (see Profile::refused()). An unclaimed developer's page has no screen in the app, so that goes to
     * GitHub, as do handles with no page at all, as on the web.
     */
    public function bioMentionScreen(string $handle): ?string
    {
        $key = mb_strtolower($handle);

        if (! isset($this->bioProfiles[$key])) {
            return null;
        }

        return match ($this->bioProfileTypes[$key] ?? 'user') {
            'organization' => 'organization',
            'user' => 'user',
            default => null,
        };
    }

    /**
     * A JSON object of strings by lowercase handle. PHP sends an empty one as [] and Laravel's (object) cast as {}.
     *
     * @return array<string, string>
     */
    private static function handles(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $handles = [];

        foreach ($value as $key => $handle) {
            if (is_string($key) && is_string($handle) && $handle !== '') {
                $handles[mb_strtolower($key)] = $handle;
            }
        }

        return $handles;
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
