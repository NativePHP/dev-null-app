<?php

namespace App\Devnull;

use App\Devnull\Data\DraftImage;
use App\Devnull\Exceptions\InvalidDraftImage;
use App\Models\PendingWrite;

/**
 * The photos attached to the commits and Remotes messages you're writing,
 * taken with the camera or picked from your library, held in the app's own
 * storage until they're sent.
 *
 * A photo is checked as devnull checks it before it's attached, so you hear at
 * once, in the web's words, when one won't do: up to three, JPEG, PNG or WebP,
 * at most 10 MB and 6000 pixels a side. devnull scales it down, re-encodes it
 * and drops its metadata, so the phone sends it as it is.
 *
 * The camera and the library hand over copies made for the app, so a photo is
 * moved here, and one that won't do is deleted, so none are left lying about.
 * A photo stays as long as a stashed draft, or a commit or a message in the
 * outbox, holds it. One nothing holds any more goes after a day.
 */
final class DraftImages
{
    public const int MaxImages = 3;

    /**
     * devnull's 10 MB, counted as it counts them: 10,000 KiB.
     */
    public const int MaxBytes = 10_000 * 1024;

    public const int MaxDimension = 6000;

    public const int MaxDescriptionLength = 1000;

    public const string Unsupported = 'Only JPEG, PNG, or WebP images up to 10 MB.';

    public const string TooLarge = 'Images must be JPEG, PNG, or WebP files of at most 6000 pixels per side.';

    public const string TooMany = 'Up to 3 images per commit.';

    public const string Unreadable = "fatal: couldn't read that photo. Try another.";

    /**
     * What the composer says when iOS or Android won't let the app use the camera or the photos.
     */
    public const string CameraDenied = "devnull can't use the camera or your photos. Allow it in Settings.";

    /**
     * How long a photo nothing holds any more is kept.
     */
    private const int KeptForSeconds = 86_400;

    /**
     * @var array<int, string>
     */
    private const array Types = [
        IMAGETYPE_JPEG => 'image/jpeg',
        IMAGETYPE_PNG => 'image/png',
        IMAGETYPE_WEBP => 'image/webp',
    ];

    /**
     * Moves a photo the camera took or the library handed over into the app's
     * storage, as an image for a draft.
     *
     * @throws InvalidDraftImage
     */
    public function keep(string $path): DraftImage
    {
        $size = is_file($path) ? filesize($path) : false;
        $info = $size === false ? false : @getimagesize($path);

        try {
            if ($size === false || $info === false) {
                throw new InvalidDraftImage(self::Unreadable);
            }

            if (! isset(self::Types[$info[2]]) || $size > self::MaxBytes) {
                throw new InvalidDraftImage(self::Unsupported);
            }

            if ($info[0] > self::MaxDimension || $info[1] > self::MaxDimension) {
                throw new InvalidDraftImage(self::TooLarge);
            }
        } catch (InvalidDraftImage $exception) {
            @unlink($path);

            throw $exception;
        }

        $this->prune();

        $kept = $this->directory().'/'.bin2hex(random_bytes(16)).image_type_to_extension($info[2]);

        if (! @rename($path, $kept) && ! (@copy($path, $kept) && @unlink($path))) {
            throw new InvalidDraftImage(self::Unreadable);
        }

        return new DraftImage($kept, self::Types[$info[2]], $info[0], $info[1]);
    }

    /**
     * Deletes the photos of a draft that was sent, or that you took off it.
     *
     * @param  list<DraftImage>  $images
     */
    public function discard(array $images): void
    {
        foreach ($images as $image) {
            if (str_starts_with($image->path, $this->directory().'/')) {
                @unlink($image->path);
            }
        }
    }

    private function directory(): string
    {
        $directory = storage_path('app/draft-images');

        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }

        return $directory;
    }

    private function prune(): void
    {
        $held = array_flip($this->held());

        foreach (glob($this->directory().'/*') ?: [] as $file) {
            if (! isset($held[$file]) && filemtime($file) < time() - self::KeptForSeconds) {
                @unlink($file);
            }
        }
    }

    /**
     * The paths of the photos your stashed drafts, and the commits and messages in the outbox, hold.
     *
     * @return list<string>
     */
    private function held(): array
    {
        $images = [
            ...app(Stash::class)->images(app(Account::class)->user()?->id),
            ...app(Outbox::class)->pending(PendingWrite::Commit, withFailed: true)->flatMap(fn (PendingWrite $write): array => $write->images())->all(),
            ...app(Outbox::class)->pending(PendingWrite::Message, withFailed: true)->flatMap(fn (PendingWrite $write): array => $write->images())->all(),
        ];

        return array_map(fn (DraftImage $image): string => $image->path, $images);
    }
}
