<?php

namespace App\Devnull\Data;

/**
 * The player devnull offers for a link preview's link, such as a YouTube
 * video: where the player lives, its shape, and the poster to show until
 * it's played. The web draws it in place of the preview's compact row.
 *
 * devnull may one day send the video's title and channel too. Until it does,
 * the app looks them up itself (see VideoDetails).
 */
final readonly class Embed
{
    private const float Widescreen = 16 / 9;

    public function __construct(
        public string $provider,
        public string $kind,
        public string $src,
        public float $aspectRatio = self::Widescreen,
        public ?string $poster = null,
        public ?int $height = null,
        public ?string $title = null,
        public ?string $author = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): ?self
    {
        $src = $data['src'] ?? null;

        if (! is_string($src) || ! str_starts_with($src, 'https://')) {
            return null;
        }

        return new self(
            provider: self::string($data['provider'] ?? null) ?? 'Video',
            kind: self::string($data['kind'] ?? null) ?? 'video',
            src: $src,
            aspectRatio: self::ratio($data['aspect_ratio'] ?? null),
            poster: self::string($data['poster'] ?? null),
            height: is_int($data['height'] ?? null) ? $data['height'] : null,
            title: self::string($data['title'] ?? null),
            author: self::string($data['author'] ?? null),
        );
    }

    /**
     * Only YouTube's get the poster card for now. The rest keep the compact
     * row: audio and code have no picture worth drawing large, and the row's
     * description says more.
     */
    public function isYouTube(): bool
    {
        return strcasecmp($this->provider, 'YouTube') === 0;
    }

    /**
     * Width over height from CSS's "16 / 9", or widescreen when it isn't one.
     */
    private static function ratio(mixed $value): float
    {
        if (! is_string($value) || preg_match('#^\s*(\d+(?:\.\d+)?)\s*/\s*(\d+(?:\.\d+)?)\s*$#', $value, $parts) !== 1) {
            return self::Widescreen;
        }

        $width = (float) $parts[1];
        $height = (float) $parts[2];

        return $width > 0 && $height > 0 ? $width / $height : self::Widescreen;
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
