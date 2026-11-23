<?php

namespace App\Devnull\Data;

use App\Devnull\ImageCache;

/**
 * A guild is a public group around a hashtag.
 *
 * Guilds include: its #hashtag, display name, description and emoji,
 * how many joined it, whether the current user has joined or muted it,
 * and if it is paused. A guild is paused if it has no moderator.
 *
 * A commit posted in a guild carries a smaller copy of it (handle, name and
 * emoji), and an older commit may only carry the handle, so everything but
 * the handle is optional and the name defaults to the hashtag.
 *
 * A guild can have an uploaded icon in place of its emoji ($iconUrl). Its URL
 * is signed and expires, so the icon is drawn only while the link is good or
 * a copy is on the device (see iconSrc). Otherwise it shows a people glyph,
 * never the default emoji stored underneath (see partials/guild-mark).
 */
final readonly class Guild
{
    public function __construct(
        public string $handle,
        public string $name,
        public string $description = '',
        public string $emoji = '',
        public int $memberCount = 0,
        public bool $isJoined = false,
        public bool $isMuted = false,
        public bool $isPaused = false,
        public ?string $htmlUrl = null,
        public ?string $iconUrl = null,
    ) {}

    /**
     * The guild, or null when there's no handle to know it by.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): ?self
    {
        $handle = $data['handle'] ?? null;

        if (! is_string($handle) || $handle === '') {
            return null;
        }

        $name = $data['name'] ?? null;

        return new self(
            handle: $handle,
            name: is_string($name) && $name !== '' ? $name : '#'.$handle,
            description: is_string($data['description'] ?? null) ? $data['description'] : '',
            emoji: is_string($data['emoji'] ?? null) ? $data['emoji'] : '',
            memberCount: max(0, (int) ($data['member_count'] ?? 0)),
            isJoined: ($data['is_joined'] ?? false) === true,
            isMuted: ($data['is_muted'] ?? false) === true,
            isPaused: ($data['is_paused'] ?? false) === true,
            htmlUrl: is_string($data['html_url'] ?? null) ? $data['html_url'] : null,
            iconUrl: is_string($data['icon_url'] ?? null) && $data['icon_url'] !== '' ? $data['icon_url'] : null,
        );
    }

    /**
     * Its hashtag, as commits write it and search takes it.
     */
    public function hashtag(): string
    {
        return '#'.$this->handle;
    }

    /**
     * The guild once you've joined or left it: its members counted to match, and unmuted by joining, as devnull does.
     */
    public function withJoined(bool $isJoined): self
    {
        $change = $isJoined === $this->isJoined ? 0 : ($isJoined ? 1 : -1);

        return new self(...[
            ...get_object_vars($this),
            'memberCount' => max(0, $this->memberCount + $change),
            'isJoined' => $isJoined,
            'isMuted' => $isJoined ? false : $this->isMuted,
        ]);
    }

    /**
     * What an image element gets for the icon, or null when it can't be drawn:
     * there is no icon, or its signed link has expired and no copy was
     * downloaded while it was good. A link with no expiry counts as good.
     */
    public function iconSrc(): ?string
    {
        if ($this->iconUrl === null) {
            return null;
        }

        parse_str((string) parse_url($this->iconUrl, PHP_URL_QUERY), $query);
        $expires = $query['expires'] ?? null;

        if (is_numeric($expires) && (int) $expires <= time() && ! ImageCache::has($this->iconUrl)) {
            return null;
        }

        return ImageCache::src($this->iconUrl);
    }
}
