<?php

namespace App\NativeComponents;

use App\Devnull\Client;
use App\Devnull\Data\Commit;
use App\Devnull\Data\Page;
use App\Devnull\Data\ScheduledCommit;
use App\Devnull\ErrorCode;
use App\Devnull\Exceptions\ApiException;
use App\Devnull\Timezone;
use App\NativeComponents\Concerns\OpensInTab;
use App\NativeComponents\Concerns\RefreshesInBackground;
use App\NativeComponents\Concerns\TalksToDevnull;
use Carbon\CarbonImmutable;
use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Facades\Browser;
use Native\Mobile\Facades\Dialog;

/**
 * The commits you scheduled, as the web's Scheduled commits page lists them,
 * which only you see: the ones still waiting first, then the ones submitted,
 * each by when it was due, with its time in the phone's timezone (see
 * Timezone). It opens from Scheduled commits → in the composer's "Submit at",
 * beneath the tab the composer was in.
 *
 * One still waiting can be canceled, as on the web, which doesn't confirm it
 * either, and edited here: a sheet with its body and time, which saves both.
 * One that was submitted says what became of it, and opens its commit while
 * there is one.
 *
 * It opens on the list as it was last time, then asks devnull again behind
 * it, and again whenever it comes back to the top.
 */
class ScheduledCommits extends NativeComponent
{
    use OpensInTab;
    use RefreshesInBackground;
    use TalksToDevnull;

    /**
     * What the web says once one is canceled.
     */
    public const string Canceled = 'Scheduled commit canceled.';

    /**
     * What the web says of a time the timezone skips.
     */
    public const string SkippedTime = 'This time does not exist in your timezone. Choose another time.';

    /**
     * @var list<ScheduledCommit>
     */
    public array $scheduled = [];

    /**
     * The next page's number, or null when this is all of them.
     */
    public ?string $nextPage = null;

    public bool $loaded = false;

    /**
     * The one being canceled, while devnull is asked.
     */
    public ?string $canceling = null;

    /**
     * Long bodies opened in place with "Show more", by id.
     *
     * @var list<string>
     */
    public array $unfolded = [];

    /**
     * The one the edit sheet is for, while it's up, with the body and the
     * time (the picker's wall clock in the phone's timezone) being edited.
     */
    public ?string $editing = null;

    /**
     * Whether the edit sheet is up. It keeps what it's editing while it slides away, until iOS says it's gone.
     */
    public bool $editOpen = false;

    public string $editBody = '';

    public string $editAt = '';

    /**
     * Why devnull didn't take the edit, shown in the sheet.
     */
    public ?string $editError = null;

    public function mount(): void
    {
        if (($kept = $this->cached(Client::ScheduledCommitsPath)) !== null) {
            $this->take($kept);
        }

        $this->revalidate();
    }

    public function onResume(): void
    {
        $this->forgetRefreshes();
        $this->revalidate();
    }

    public function navTitle(): string
    {
        return 'Scheduled commits';
    }

    protected function revalidate(): void
    {
        $this->refresh(Client::ScheduledCommitsPath, [], fn (array $json) => $this->take($json));
    }

    protected function showsContent(): bool
    {
        return $this->loaded;
    }

    /**
     * The web's Cancel: it goes from the list once devnull has canceled it.
     * When it was submitted or canceled already, the web's toast says so, and
     * the list asks again to show what became of it.
     */
    public function cancel(string $id): void
    {
        if ($this->canceling !== null || $this->find($id)?->isWaiting() !== true) {
            return;
        }

        $this->canceling = $id;

        $canceled = $this->attempt(function (Client $devnull) use ($id): bool {
            try {
                $devnull->cancelScheduledCommit($id);
            } catch (ApiException $exception) {
                return $exception->is(ErrorCode::ScheduledCommitSubmitted) ? false : throw $exception;
            }

            return true;
        });

        $this->canceling = null;

        if ($canceled === false) {
            Dialog::toast((string) ErrorCode::ScheduledCommitSubmitted->message());
            $this->retry();
        }

        if ($canceled === true) {
            $this->scheduled = array_values(array_filter($this->scheduled, fn (ScheduledCommit $scheduled): bool => $scheduled->id !== $id));
            Dialog::toast(self::Canceled);
        }
    }

    /**
     * Opens the edit sheet on one still waiting, with its body and its time.
     */
    public function edit(string $id): void
    {
        $scheduled = $this->find($id);

        if ($scheduled === null || ! $scheduled->isWaiting()) {
            return;
        }

        $this->editing = $id;
        $this->editOpen = true;
        $this->editBody = $scheduled->body ?? '';
        $this->editAt = $scheduled->scheduledAt->setTimezone(app(Timezone::class)->here())->format('Y-m-d\TH:i');
        $this->editError = null;
    }

    /**
     * Saves the body and the time. The sheet goes once devnull has them, and
     * the list shows them; otherwise it says why and keeps them.
     */
    public function saveEdit(): void
    {
        $id = $this->editing;
        $at = $this->editTime();
        $body = trim($this->editBody);

        if ($id === null || $at === null) {
            return;
        }

        $screenError = $this->error;
        $saved = $this->attempt(fn (Client $devnull): ScheduledCommit => $devnull->updateScheduledCommit($id, $body, $at));

        if ($saved === null) {
            $this->editError = $this->error;
            $this->error = $screenError;

            return;
        }

        $this->scheduled = array_map(fn (ScheduledCommit $scheduled): ScheduledCommit => $scheduled->id === $saved->id ? $saved : $scheduled, $this->scheduled);
        $this->editOpen = false;
    }

    /**
     * iOS says the edit sheet has gone, saved or swiped away. What wasn't saved is dropped.
     */
    public function editSheetClosed(): void
    {
        $this->editOpen = false;
        $this->editing = null;
        $this->editBody = '';
        $this->editAt = '';
        $this->editError = null;
    }

    /**
     * A time picked in the edit sheet: the web says when the phone's timezone skips it.
     */
    public function updatedEditAt(): void
    {
        $this->editError = $this->editTime() === null ? self::SkippedTime : null;
    }

    public function showMore(string $id): void
    {
        if (! in_array($id, $this->unfolded, true)) {
            $this->unfolded[] = $id;
        }
    }

    /**
     * A link in one's body, by its place among the body's links: a commit on
     * dev/null here in the app, anything else in the browser, as in a feed.
     */
    public function openLink(string $id, int $index): void
    {
        $link = $this->find($id)?->links()[$index] ?? null;

        if ($link?->commit !== null) {
            $this->openCommit($link->commit);

            return;
        }

        if ($link !== null && in_array(parse_url($link->href, PHP_URL_SCHEME), ['http', 'https'], true)) {
            Browser::inApp($link->href);
        }
    }

    /**
     * The commit one became, once it was submitted.
     */
    public function openCommit(string $id): void
    {
        $this->openInTab('/commits/'.$id);
    }

    /**
     * The next page, under the ones showing.
     */
    public function loadMore(): void
    {
        if ($this->nextPage === null) {
            return;
        }

        $page = $this->attempt(fn (Client $devnull): Page => $devnull->scheduledCommits($this->nextPage));

        if ($page !== null) {
            $this->scheduled = [...$this->scheduled, ...$page->items];
            $this->nextPage = $page->nextCursor;
        }
    }

    public function render(): View
    {
        $editing = $this->editing === null ? null : $this->find($this->editing);

        return view('native.scheduled-commits', [
            'myColour' => $this->myColour(),
            'timezone' => app(Timezone::class)->here(),
            'now' => CarbonImmutable::now(),
            'editingCommit' => $editing,
            'canSave' => $editing !== null && $this->editTime() !== null
                && ($editing->imageCount > 0 && trim($this->editBody) === '' || Commit::fits($this->editBody)),
        ]);
    }

    /**
     * @param  array<string, mixed>  $json
     */
    private function take(array $json): void
    {
        $page = Page::fromArray($json, ScheduledCommit::fromArray(...));

        $this->scheduled = $page->items;
        $this->nextPage = $page->nextCursor;
        $this->loaded = true;
    }

    private function find(string $id): ?ScheduledCommit
    {
        foreach ($this->scheduled as $scheduled) {
            if ($scheduled->id === $id) {
                return $scheduled;
            }
        }

        return null;
    }

    /**
     * The time picked in the edit sheet, or null when the phone's timezone skips it.
     */
    private function editTime(): ?CarbonImmutable
    {
        if ($this->editAt === '') {
            return null;
        }

        $at = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $this->editAt, app(Timezone::class)->here());

        return $at instanceof CarbonImmutable && $at->format('Y-m-d\TH:i') === $this->editAt ? $at : null;
    }
}
