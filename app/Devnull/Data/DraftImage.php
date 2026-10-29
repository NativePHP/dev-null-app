<?php

namespace App\Devnull\Data;

use App\Devnull\DraftImages;

/**
 * An image attached to a commit you're writing. It's kept in the app's own
 * storage (see DraftImages) until the commit is sent or the image is taken
 * off, with the description you gave it for people who can't see it.
 */
final readonly class DraftImage
{
    public function __construct(public string $path, public string $mimeType, public int $width, public int $height, public string $description = '') {}

    /**
     * The image as the stash and the outbox keep it, or null when its file has gone from the phone.
     */
    public static function fromArray(mixed $image): ?self
    {
        if (! is_array($image) || ! is_string($image['path'] ?? null) || ! is_file($image['path'])) {
            return null;
        }

        return new self($image['path'], (string) ($image['mime_type'] ?? ''), (int) ($image['width'] ?? 0), (int) ($image['height'] ?? 0), (string) ($image['description'] ?? ''));
    }

    /**
     * @return array{path: string, mime_type: string, width: int, height: int, description: string}
     */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'mime_type' => $this->mimeType,
            'width' => $this->width,
            'height' => $this->height,
            'description' => $this->description,
        ];
    }

    public function withDescription(string $description): self
    {
        return new self(...[...get_object_vars($this), 'description' => mb_substr($description, 0, DraftImages::MaxDescriptionLength)]);
    }

    public function isDescribed(): bool
    {
        return trim($this->description) !== '';
    }

    /**
     * The image as a commit shows it, drawn from the file on this phone, for the preview.
     */
    public function asCommitImage(int $index): CommitImage
    {
        return new CommitImage('draft-'.$index, $this->path, $this->width, $this->height, $this->isDescribed() ? trim($this->description) : null);
    }
}
