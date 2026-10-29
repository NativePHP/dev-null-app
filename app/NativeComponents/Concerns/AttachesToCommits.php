<?php

namespace App\NativeComponents\Concerns;

use App\Devnull\Client;
use App\Devnull\Data\DraftImage;
use App\Devnull\Data\PollDraft;
use App\Devnull\DraftImages;
use App\Devnull\Exceptions\ApiException;
use Closure;

/**
 * What the composer sheet adds to a commit, as the web's composer does: photos,
 * a poll, and a preview (see partials/composer-tools).
 *
 * Photos come from the library or the camera, up to three, each with a
 * description for people who can't see it (see AttachesPhotos). A new
 * commit or a quote can have a poll instead, of two to four options, open
 * for an hour up to a week. A reply can have photos but not a poll, as on
 * the web.
 *
 * Preview shows the draft as the commit it would become, by you, at once, and
 * takes the body devnull sends back, with its code and mentions, when it comes.
 *
 * A commit with photos goes through the outbox like any other, given longer
 * to upload (see SyncsOutbox). The photos go with it, and are deleted from
 * the phone once devnull has them. Until it's sent, the photos and the poll
 * are stashed with the draft (see Stash), so closing the sheet keeps them for
 * next time. Drop throws them away with it, deleting the photos.
 */
trait AttachesToCommits
{
    use AttachesPhotos;

    /**
     * @var list<DraftImage>
     */
    public array $sheetImages = [];

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
     * Keeps the draft in the stash as it is now, its photos and poll with it.
     */
    abstract protected function stashSheetDraft(): void;

    /**
     * The web's poll button: two empty options, open for a day. A commit has photos or a poll, not both, and a
     * reply never has one.
     */
    public function addPoll(): void
    {
        if ($this->isComposing() && $this->sheetView !== 'reply' && $this->sheetImages === [] && $this->sheetPoll === null) {
            $this->sheetPoll = PollDraft::start();
            $this->sheetPollLength = PollDraft::DefaultLength;
            $this->stashSheetDraft();
        }
    }

    public function removePoll(): void
    {
        $this->sheetPoll = null;
        $this->stashSheetDraft();
    }

    public function addPollOption(): void
    {
        if ($this->sheetPoll !== null && count($this->sheetPoll) < PollDraft::MaxOptions) {
            $this->sheetPoll[] = '';
            $this->stashSheetDraft();
        }
    }

    /**
     * Only the options past the first two can go, as on the web.
     */
    public function removePollOption(int $index): void
    {
        if ($this->sheetPoll !== null && $index >= PollDraft::MinOptions && isset($this->sheetPoll[$index])) {
            array_splice($this->sheetPoll, $index, 1);
            $this->stashSheetDraft();
        }
    }

    public function pollOptionChanged(int $index, string $option = ''): void
    {
        if ($this->sheetPoll !== null && isset($this->sheetPoll[$index])) {
            $this->sheetPoll[$index] = mb_substr($option, 0, PollDraft::MaxOptionLength);
            $this->stashSheetDraft();
        }
    }

    public function updatedSheetPollLength(): void
    {
        $this->stashSheetDraft();
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
     * Lets go of the photos, the poll and the preview. The photos stay on the phone for the stashed draft, unless
     * they're thrown away with it ($discardPhotos).
     */
    protected function forgetAttachments(bool $discardPhotos = false): void
    {
        if ($discardPhotos) {
            app(DraftImages::class)->discard($this->sheetImages);
        }

        $this->sheetImages = [];
        $this->describing = null;
        $this->sheetPoll = null;
        $this->sheetPollLength = PollDraft::DefaultLength;
        $this->previewing = false;
        $this->sheetPreview = null;
    }

    /**
     * @return list<DraftImage>
     */
    protected function attachedPhotos(): array
    {
        return $this->sheetImages;
    }

    /**
     * @param  list<DraftImage>  $photos
     */
    protected function photosChanged(array $photos): void
    {
        $this->sheetImages = $photos;
        $this->stashSheetDraft();
    }

    /**
     * A commit has photos or a poll, not both.
     */
    protected function takesPhotos(): bool
    {
        return $this->isComposing() && $this->sheetPoll === null;
    }

    protected function photoFailed(?string $error): void
    {
        $this->sheetError = $error;
    }
}
