<?php

namespace App\NativeComponents;

use App\Devnull\Client;
use App\Devnull\Data\Page;
use App\Devnull\Data\ScheduledCommit;
use App\Devnull\Exceptions\ApiException;
use App\Devnull\SiteLink;
use App\Devnull\Timezone;
use App\Devnull\Toasts;
use App\NativeComponents\Concerns\OpensInTab;
use App\NativeComponents\Concerns\RefreshesInBackground;
use App\NativeComponents\Concerns\TalksToDevnull;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Facades\Browser;

/**
 * The commits you scheduled, as the web's Scheduled commits page lists them,
 * which only you see: the ones still waiting first, then the ones submitted,
 * each by when it was due, with its time in the phone's timezone (see
 * Timezone). It opens from Scheduled commits → in the composer's "Submit at",
 * beneath the tab the composer was in.
 *
 * One still waiting can be committed now, before its time, or canceled, as on
 * the web, which confirms neither. A row committed now keeps what was sent and
 * stays where it was, as the web keeps it, and says it was submitted. When
 * devnull got there first, it says so in the web's words, and the list asks
 * again to show what became of it.
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
     * What the web says once one is canceled, once one is committed now, and when devnull had submitted or canceled
     * it already.
     */
    public const string Canceled = 'Scheduled commit canceled.';

    public const string Submitted = 'Commit submitted. It goes through the usual review process.';

    public const string AlreadySubmitted = 'This commit was already submitted or canceled. Refresh to see its status.';

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
     * The one being committed now or canceled while devnull is asked, and which ('commit' or 'cancel').
     */
    public ?string $busy = null;

    public ?string $busyWith = null;

    /**
     * Long bodies opened in place with "Show more", by id.
     *
     * @var list<string>
     */
    public array $unfolded = [];

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
     * The web's Commit now: it goes for review now instead of at its time.
     * The row keeps what was sent, where it was, and says it was submitted.
     */
    public function commitNow(string $id): void
    {
        $sent = $this->find($id);
        $submitted = $this->whileBusy($id, 'commit', fn (Client $devnull): ScheduledCommit => $devnull->submitScheduledCommit($id));

        if ($submitted instanceof ScheduledCommit && $sent !== null) {
            $this->scheduled = array_map(fn (ScheduledCommit $scheduled): ScheduledCommit => $scheduled->id === $id ? $sent->submittedAs($submitted) : $scheduled, $this->scheduled);
            Toasts::say(self::Submitted);
        }
    }

    /**
     * The web's Cancel: it goes from the list once devnull has canceled it.
     */
    public function cancel(string $id): void
    {
        $canceled = $this->whileBusy($id, 'cancel', function (Client $devnull) use ($id): bool {
            $devnull->cancelScheduledCommit($id);

            return true;
        });

        if ($canceled === true) {
            $this->scheduled = array_values(array_filter($this->scheduled, fn (ScheduledCommit $scheduled): bool => $scheduled->id !== $id));
            Toasts::say(self::Canceled);
        }
    }

    public function showMore(string $id): void
    {
        if (! in_array($id, $this->unfolded, true)) {
            $this->unfolded[] = $id;
        }
    }

    /**
     * A link in one's body, by its place among the body's links: a page on
     * dev/null in the app's own screen for it, anything else in the browser,
     * as in a feed (see SiteLink).
     */
    public function openLink(string $id, int $index): void
    {
        $link = $this->find($id)?->links()[$index] ?? null;
        $screen = $link?->commit !== null ? '/commits/'.$link->commit : SiteLink::screen($link?->href);

        if ($screen !== null) {
            $this->openInTab($screen);
        } elseif (SiteLink::isWebUrl($link?->href)) {
            Browser::inApp((string) $link?->href);
        }
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
        return view('native.scheduled-commits', [
            'myColour' => $this->myColour(),
            'timezone' => app(Timezone::class)->here(),
            'now' => CarbonImmutable::now(),
        ]);
    }

    /**
     * Asks devnull to commit or cancel one still waiting, one at a time. When
     * devnull had submitted or canceled it already, it answers 409, which the
     * web's toast says, and the list asks again to show what became of it.
     *
     * @template TResult
     *
     * @param  Closure(Client): TResult  $call
     * @return TResult|false|null
     */
    private function whileBusy(string $id, string $action, Closure $call): mixed
    {
        if ($this->busy !== null || $this->find($id)?->isWaiting() !== true) {
            return null;
        }

        $this->busy = $id;
        $this->busyWith = $action;

        $result = $this->attempt(function (Client $devnull) use ($call): mixed {
            try {
                return $call($devnull);
            } catch (ApiException $exception) {
                return $exception->status === 409 ? false : throw $exception;
            }
        });

        $this->busy = null;
        $this->busyWith = null;

        if ($result === false) {
            Toasts::say(self::AlreadySubmitted);
            $this->retry();
        }

        return $result;
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
}
