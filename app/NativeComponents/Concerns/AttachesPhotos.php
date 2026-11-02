<?php

namespace App\NativeComponents\Concerns;

use App\Devnull\Data\DraftImage;
use App\Devnull\DraftImages;
use App\Devnull\Exceptions\InvalidDraftImage;
use Native\Mobile\Attributes\On;
use Native\Mobile\Events\Camera\PermissionDenied;
use Native\Mobile\Events\Camera\PhotoTaken;
use Native\Mobile\Events\Gallery\MediaSelected;
use Native\Mobile\Facades\Camera;

/**
 * Photos on a draft, as the web's image button attaches them, from the
 * library or the camera: up to three, each checked as devnull checks it (see
 * DraftImages), each with a description for people who can't see it. The ✕
 * on one takes it off the draft and off the phone (see partials/composer-photos).
 *
 * The commit composer and a Remotes thread's message bar both attach photos
 * this way. Each keeps them where its draft is and says what happens when
 * they change, through attachedPhotos(), photosChanged(), takesPhotos() and
 * photoFailed().
 */
trait AttachesPhotos
{
    /**
     * The photo whose description is open under the photos, by its place.
     */
    public ?int $describing = null;

    /**
     * The draft's photos as they are now.
     *
     * @return list<DraftImage>
     */
    abstract protected function attachedPhotos(): array;

    /**
     * The draft's photos changed: one added, taken off or described.
     *
     * @param  list<DraftImage>  $photos
     */
    abstract protected function photosChanged(array $photos): void;

    /**
     * Whether the draft can take photos at all right now.
     */
    abstract protected function takesPhotos(): bool;

    /**
     * Why the photos just picked or taken didn't all make it, in the web's words, or null when they did.
     */
    abstract protected function photoFailed(?string $error): void;

    /**
     * The web's image button: the photo library, for as many photos as there's still room for.
     */
    public function pickPhotos(): void
    {
        if ($this->hasRoomForPhotos()) {
            Camera::pickImages('images', true, DraftImages::MaxImages - count($this->attachedPhotos()))->start();
        }
    }

    public function takePhoto(): void
    {
        if ($this->hasRoomForPhotos()) {
            Camera::getPhoto()->start();
        }
    }

    #[On(PhotoTaken::class)]
    public function photoTaken(string $path = ''): void
    {
        $this->attachPhotos([$path]);
    }

    /**
     * @param  list<mixed>  $files
     */
    #[On(MediaSelected::class)]
    public function photosPicked(array $files = []): void
    {
        $this->attachPhotos(array_map(fn (mixed $file): string => is_array($file) && is_string($file['path'] ?? null) ? $file['path'] : '', $files));
    }

    #[On(PermissionDenied::class)]
    public function cameraDenied(): void
    {
        if ($this->takesPhotos()) {
            $this->photoFailed(DraftImages::CameraDenied);
        }
    }

    /**
     * The ✕ on a photo: it comes off the draft, and off the phone.
     */
    public function removePhoto(int $index): void
    {
        $photos = $this->attachedPhotos();

        if (! isset($photos[$index])) {
            return;
        }

        app(DraftImages::class)->discard([$photos[$index]]);
        array_splice($photos, $index, 1);
        $this->describing = null;
        $this->photosChanged($photos);
    }

    /**
     * The web's ALT on a photo: opens its description under the photos, or closes it.
     */
    public function describePhoto(int $index): void
    {
        if (isset($this->attachedPhotos()[$index])) {
            $this->describing = $this->describing === $index ? null : $index;
        }
    }

    public function photoDescribed(int $index, string $description = ''): void
    {
        $photos = $this->attachedPhotos();

        if (isset($photos[$index])) {
            $photos[$index] = $photos[$index]->withDescription($description);
            $this->photosChanged($photos);
        }
    }

    private function hasRoomForPhotos(): bool
    {
        return $this->takesPhotos() && count($this->attachedPhotos()) < DraftImages::MaxImages;
    }

    /**
     * Keeps the photos the camera or the library handed over, as many as there's room for, and says why any didn't
     * make it, in the web's words.
     *
     * @param  list<string>  $paths
     */
    private function attachPhotos(array $paths): void
    {
        $paths = array_values(array_filter($paths, fn (string $path): bool => $path !== ''));

        if ($paths === [] || ! $this->hasRoomForPhotos()) {
            return;
        }

        $photos = $this->attachedPhotos();
        $error = null;

        foreach ($paths as $path) {
            if (count($photos) >= DraftImages::MaxImages) {
                $error = DraftImages::TooMany;

                break;
            }

            try {
                $photos[] = app(DraftImages::class)->keep($path);
            } catch (InvalidDraftImage $exception) {
                $error = $exception->getMessage();
            }
        }

        $this->photoFailed($error);
        $this->photosChanged($photos);
    }
}
