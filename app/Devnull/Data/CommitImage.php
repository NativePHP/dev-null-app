<?php

namespace App\Devnull\Data;

/**
 * An image attached to a commit, with the description its author gave it, if any.
 */
final readonly class CommitImage
{
    /**
     * The tallest a single image gets, as width over height. The web caps an
     * image's height the same way so a long screenshot doesn't take the feed.
     */
    public const float TallestRatio = 0.75;

    public function __construct(public string $id, public string $url, public int $width = 0, public int $height = 0, public ?string $description = null) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): ?self
    {
        $url = $data['url'] ?? null;

        if (! is_string($url) || $url === '') {
            return null;
        }

        return new self(
            id: (string) ($data['id'] ?? $url),
            url: $url,
            width: is_numeric($data['width'] ?? null) ? max(0, (int) $data['width']) : 0,
            height: is_numeric($data['height'] ?? null) ? max(0, (int) $data['height']) : 0,
            description: is_string($data['description'] ?? null) && trim($data['description']) !== '' ? trim($data['description']) : null,
        );
    }

    /**
     * What a screen reader says for the image: its description, or that it's an
     * attached image, with its place among the commit's others when there are several.
     */
    public function label(int $index = 0, int $count = 1): string
    {
        $place = $count > 1 ? ($index + 1).' of '.$count : null;

        return match (true) {
            $this->description !== null => $this->description.($place === null ? '' : ", image {$place}"),
            $place !== null => "Attached image {$place}",
            default => 'Attached image',
        };
    }

    /**
     * Width over height, so the space is held before the image loads, or if it never does.
     */
    public function ratio(): float
    {
        if ($this->width === 0 || $this->height === 0) {
            return 1.0;
        }

        return round(max(self::TallestRatio, $this->width / $this->height), 4);
    }

    /**
     * Width over height as the image really is, uncapped, for drawing it whole;
     * null when devnull didn't send its size.
     */
    public function naturalRatio(): ?float
    {
        if ($this->width === 0 || $this->height === 0) {
            return null;
        }

        return round($this->width / $this->height, 4);
    }
}
