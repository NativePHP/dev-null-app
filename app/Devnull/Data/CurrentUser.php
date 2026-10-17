<?php

namespace App\Devnull\Data;

use App\Devnull\Lane;
use Carbon\CarbonImmutable;

/**
 * A `GET /me` answer: who this device is signed in as, how many of their
 * notifications are still unread, and whether an Apple ID is linked to them.
 *
 * It also has what only you see in the web's profile settings: the lane you
 * chose (null for Automatic) and the one Automatic gives you, your GitHub
 * Sponsors page, and when your profile was last synced from GitHub (null when
 * it only was when you signed in). It has whether pastéis mode is on, how
 * many pastéis you can still give today, and how many others have given you.
 */
final readonly class CurrentUser
{
    public function __construct(
        public User $user,
        public int $unreadNotificationsCount = 0,
        public bool $appleLinked = false,
        public ?int $preferredLane = null,
        public int $automaticLane = 0,
        public ?string $sponsorsUrl = null,
        public ?CarbonImmutable $githubSyncedAt = null,
        public bool $pasteisMode = false,
        public Pasteis $pasteisToday = new Pasteis,
        public int $pasteisReceived = 0,
    ) {}

    /**
     * @param  array<string, mixed>  $json
     */
    public static function fromArray(array $json): self
    {
        $data = is_array($json['data'] ?? null) ? $json['data'] : [];
        $unread = $json['unread_notifications_count'] ?? $data['unread_notifications_count'] ?? 0;
        $user = User::fromArray($data);
        $automaticLane = Lane::from($data['automatic_lane'] ?? null) ?? Lane::for($user->id);
        $sponsorsUrl = $data['sponsors_url'] ?? null;

        return new self(
            user: $user,
            unreadNotificationsCount: is_int($unread) ? max(0, $unread) : 0,
            appleLinked: ($json['apple_linked'] ?? false) === true,
            // Without devnull's word on it, your lane is Automatic when it's the one Automatic would give you.
            preferredLane: array_key_exists('preferred_lane', $data)
                ? Lane::from($data['preferred_lane'])
                : ($user->lane() === $automaticLane ? null : $user->lane()),
            automaticLane: $automaticLane,
            sponsorsUrl: is_string($sponsorsUrl) && preg_match('#^https://#i', $sponsorsUrl) === 1 ? $sponsorsUrl : null,
            githubSyncedAt: Commit::date($data['github_synced_at'] ?? null),
            pasteisMode: ($data['pasteis_mode'] ?? false) === true,
            pasteisToday: Pasteis::fromKept(['remaining' => $data['pasteis_remaining'] ?? null, 'resets_at' => $data['pasteis_resets_at'] ?? null]),
            pasteisReceived: is_int($data['pasteis_received'] ?? null) ? max(0, $data['pasteis_received']) : 0,
        );
    }

    /**
     * You as this phone remembers you, until devnull says the rest.
     */
    public static function remembered(User $user): self
    {
        return self::fromArray(['data' => $user->toArray()]);
    }

    /**
     * Your pastéis for today, or null while pastéis mode is off.
     */
    public function pasteis(): ?Pasteis
    {
        return $this->pasteisMode ? $this->pasteisToday : null;
    }

    public function withPasteisMode(bool $pasteisMode): self
    {
        return new self(...[...get_object_vars($this), 'pasteisMode' => $pasteisMode]);
    }

    /**
     * Your GitHub Sponsors page as the web prints it, without the scheme.
     */
    public function sponsorsLabel(): ?string
    {
        return $this->sponsorsUrl === null ? null : (string) preg_replace('#^https://#i', '', $this->sponsorsUrl);
    }
}
