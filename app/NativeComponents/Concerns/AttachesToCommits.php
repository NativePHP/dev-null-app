<?php

namespace App\NativeComponents\Concerns;

use App\Devnull\Client;
use App\Devnull\Data\DraftImage;
use App\Devnull\Data\PollDraft;
use App\Devnull\DraftImages;
use App\Devnull\Exceptions\ApiException;
use App\Devnull\Exceptions\InvalidDraftImage;
use Closure;
use Native\Mobile\Attributes\On;
use Native\Mobile\Events\Camera\PermissionDenied;
use Native\Mobile\Events\Camera\PhotoTaken;
use Native\Mobile\Events\Gallery\MediaSelected;
use Native\Mobile\Facades\Camera;

/**
 * What the composer sheet adds to a commit, as the web's composer does: photos,
 * a poll, and a preview (see partials/composer-tools).
 *
 * Photos come from the library or the camera, up to three, each with a
 * description for people who can't see it. A new commit or a quote can have a
 * poll instead, of two to four options, open for an hour up to a week. A reply
 * can have photos but not a poll, as on the web.
 *
 * Preview shows the draft as the commit it would become, by you, at once, and
 * takes the body devnull sends back, with its code and mentions, when it comes.
 *
 * A commit with photos goes through the outbox like any other, given longer
 * to upload (see SyncsOutbox). The photos go with it, and are deleted from
 * the phone once devnull has them. Otherwise photos and a poll go with the
 * sheet: closing it drops them, and deletes the photos from the phone.
 */
trait AttachesToCommits
{
    /**
     * @var list<DraftImage>
     */
    public array $sheetImages = [];

    /**
     * The photo whose description is open under the photos, by its place.
     */
    public ?int $describing = null;

    /**
     * The poll's options, or null with no poll.
     *
     * @var list<string>|null
     */
    public ?array $sheetPoll = null;

    /**
     * How long the poll runs, one of PollDraft::Lengths.
     */
    public string $sheetPollLength = PollDraft::DefaultLength;

    /**
     * Whether the sheet shows the draft as the commit it would become, in place of the field.
     */
    public bool $previewing = false;

    /**
     * What devnull sent back for the draft's body, with the body it was for under `draft`.
     *
     * @var array<string, mixed>|null
     */
    public ?array $sheetPreview = null;

    /**
     * @param  array<string, mixed>|null  $payload
     * @param  Closure(array<string, mixed>): void  $done
     * @param  Closure(string, ?ApiException): void  $failed
     */
    abstract protected function writeInBackground(string $method, string $path, ?array $payload, Closure $done, Closure $failed): void;

    /**
     * The web's image button: the photo library, for as many photos as there's still room for.
     */
    public function pickPhotos(): void
    {
        if ($this->canAttachPhotos()) {
            Camera::pickImages('images', true, DraftImages::MaxImages - count($this->sheetImages))->start();
        }
    }

    public function takePhoto(): void
    {
        if ($this->canAttachPhotos()) {
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
        if ($this->isComposing()) {
            $this->sheetError = DraftImages::CameraDenied;
        }
    }

    /**
     * The ✕ on a photo: it comes off the draft, and off the phone.
     */
    public function removePhoto(int $index): void
    {
        if (! isset($this->sheetImages[$index])) {
            return;
        }

        app(DraftImages::class)->discard([$this->sheetImages[$index]]);
        array_splice($this->sheetImages, $index, 1);
        $this->describing = null;
    }

    /**
     * The web's ALT on a photo: opens its description under the photos, or closes it.
     */
    public function describePhoto(int $index): void
    {
        if (isset($this->sheetImages[$index])) {
            $this->describing = $this->describing === $index ? null : $index;
        }
    }

    public function photoDescribed(int $index, string $description = ''): void
    {
        if (isset($this->sheetImages[$index])) {
            $this->sheetImages[$index] = $this->sheetImages[$index]->withDescription($description);
        }
    }

    /**
     * The web's poll button: two empty options, open for a day. A commit has photos or a poll, not both, and a
     * reply never has one.
     */
    public function addPoll(): void
    {
        if ($this->isComposing() && $this->sheetView !== 'reply' && $this->sheetImages === [] && $this->sheetPoll === null) {
            $this->sheetPoll = PollDraft::start();
            $this->sheetPollLength = PollDraft::DefaultLength;
        }
    }

    public function removePoll(): void
    {
        $this->sheetPoll = null;
    }

    public function addPollOption(): void
    {
        if ($this->sheetPoll !== null && count($this->sheetPoll) < PollDraft::MaxOptions) {
            $this->sheetPoll[] = '';
        }
    }

    /**
     * Only the options past the first two can go, as on the web.
     */
    public function removePollOption(int $index): void
    {
        if ($this->sheetPoll !== null && $index >= PollDraft::MinOptions && isset($this->sheetPoll[$index])) {
            array_splice($this->sheetPoll, $index, 1);
        }
    }

    public function pollOptionChanged(int $index, string $option = ''): void
    {
        if ($this->sheetPoll !== null && isset($this->sheetPoll[$index])) {
            $this->sheetPoll[$index] = mb_substr($option, 0, PollDraft::MaxOptionLength);
        }
    }

    /**
     * The web's preview toggle. The draft shows as the commit it would become at once, and devnull is asked for its
     * body, unless it already said for this one. If devnull can't be reached, the body shows as written.
     */
    public function togglePreview(): void
    {
        if (! $this->isComposing()) {
            return;
        }

        $this->previewing = ! $this->previewing;
        $this->describing = null;
        $body = trim($this->sheetBody);

        if (! $this->previewing || $body === '' || ($this->sheetPreview['draft'] ?? null) === $body) {
            return;
        }

        $this->writeInBackground('POST', Client::PreviewPath, ['body' => $body], function (array $json) use ($body): void {
            if (is_array($json['data'] ?? null)) {
                $this->sheetPreview = [...$json['data'], 'draft' => $body];
            }
        }, fn (): null => null);
    }

    /**
     * Whether the sheet is a composer: a new commit, a quote or a reply.
     */
    protected function isComposing(): bool
    {
        return $this->sheetOpen && in_array($this->sheetView, ['compose', 'quote', 'reply'], true);
    }

    /**
     * The poll as devnull takes it, or null with none.
     *
     * @return array{options: list<string>, duration: int}|null
     */
    protected function sheetPollPayload(): ?array
    {
        return $this->sheetPoll === null || $this->sheetView === 'reply' ? null : PollDraft::payload($this->sheetPoll, $this->sheetPollLength);
    }

    /**
     * Lets go of the photos, the poll and the preview. Photos still on the phone are deleted. Ones on their way to
     * devnull have left the sheet by then.
     */
    protected function forgetAttachments(): void
    {
        app(DraftImages::class)->discard($this->sheetImages);

        $this->sheetImages = [];
        $this->describing = null;
        $this->sheetPoll = null;
        $this->sheetPollLength = PollDraft::DefaultLength;
        $this->previewing = false;
        $this->sheetPreview = null;
    }

    private function canAttachPhotos(): bool
    {
        return $this->isComposing() && $this->sheetPoll === null && count($this->sheetImages) < DraftImages::MaxImages;
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

        if ($paths === [] || ! $this->canAttachPhotos()) {
            return;
        }

        $this->sheetError = null;

        foreach ($paths as $path) {
            if (count($this->sheetImages) >= DraftImages::MaxImages) {
                $this->sheetError = DraftImages::TooMany;

                break;
            }

            try {
                $this->sheetImages[] = app(DraftImages::class)->keep($path);
            } catch (InvalidDraftImage $exception) {
                $this->sheetError = $exception->getMessage();
            }
        }
    }
}
