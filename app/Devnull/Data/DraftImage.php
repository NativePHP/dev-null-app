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
