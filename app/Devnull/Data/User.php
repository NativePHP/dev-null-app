<?php

namespace App\Devnull\Data;

use App\Devnull\Lane;
use Carbon\CarbonImmutable;

/**
 * A devnull user, as the API sends a commit's author, a notification's actors,
 * a profile and you. Only the username is sure to be there.
 */
final readonly class User
{
    public function __construct(
        public string $id,
        public string $username,
        public string $name,
        public ?string $avatarUrl = null,
        public ?string $bio = null,
        public int|string|null $lane = null,
        public ?string $company = null,
        public ?string $location = null,
        public ?string $website = null,
        public ?string $githubUrl = null,
        public ?string $htmlUrl = null,
        public ?string $feedUrl = null,
        public ?CarbonImmutable $createdAt = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $username = (string) ($data['handle'] ?? '');
        $id = $data['id'] ?? null;
        $name = $data['name'] ?? null;

        return new self(
            id: is_scalar($id) && (string) $id !== '' ? (string) $id : $username,
            username: $username,
            name: is_string($name) && $name !== '' ? $name : $username,
            avatarUrl: is_string($data['avatar_url'] ?? null) ? $data['avatar_url'] : null,
            bio: self::text($data['bio'] ?? null),
            lane: Lane::from($data['lane'] ?? null),
            company: self::text($data['company'] ?? null),
            location: self::text($data['location'] ?? null),
            website: self::text($data['website'] ?? null),
            githubUrl: self::text($data['github_url'] ?? null),
            htmlUrl: self::text($data['html_url'] ?? null),
            feedUrl: self::text($data['feed_url'] ?? null),
            createdAt: Commit::date($data['created_at'] ?? null),
        );
    }

    /**
     * The user in the API's shape, so it can be kept and read back.
     *
     * @return array{id: string, handle: string, name: string, avatar_url: ?string, bio: ?string, lane: int|string|null, company: ?string, location: ?string, website: ?string, github_url: ?string, html_url: ?string, feed_url: ?string, created_at: ?string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'handle' => $this->username,
            'name' => $this->name,
            'avatar_url' => $this->avatarUrl,
            'bio' => $this->bio,
            'lane' => $this->lane,
            'company' => $this->company,
            'location' => $this->location,
            'website' => $this->website,
            'github_url' => $this->githubUrl,
            'html_url' => $this->htmlUrl,
            'feed_url' => $this->feedUrl,
            'created_at' => $this->createdAt?->toIso8601ZuluString(),
        ];
    }

    /**
     * The lane the user picked on the web, a palette lane or a custom
     * `#rrggbb` colour, or the one devnull gave them. Hashing the id is only
     * for a server too old to send it.
     */
    public function lane(): int|string
    {
        return $this->lane ?? Lane::for($this->id);
    }

    public function handle(): string
    {
        return '@'.$this->username;
    }

    /**
     * Their GitHub profile. Everyone on devnull signed in with GitHub, so it can be
     * worked out when the API leaves it out.
     */
    public function gitHubProfileUrl(): string
    {
        return $this->githubUrl ?? 'https://github.com/'.rawurlencode($this->username);
    }

    /**
     * Their website, as GitHub has it, which may leave off the scheme.
     */
    public function websiteUrl(): ?string
    {
        if ($this->website === null) {
            return null;
        }

        return preg_match('#^https?://#i', $this->website) === 1 ? $this->website : 'https://'.$this->website;
    }

    /**
     * Their website as the web prints it, without the scheme.
     */
    public function websiteLabel(): ?string
    {
        return $this->website === null ? null : rtrim((string) preg_replace('#^https?://#i', '', $this->website), '/');
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
