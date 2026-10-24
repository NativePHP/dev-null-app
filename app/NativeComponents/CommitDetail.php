<?php

namespace App\NativeComponents;

use App\Devnull\Account;
use App\Devnull\Data\Commit;
use App\Devnull\Data\CommitThread;
use App\Devnull\Data\Page;
use App\Devnull\Display;
use App\Devnull\RowCache;
use App\Devnull\Stash;
use App\Devnull\Timeline;
use App\Icons\Lucide;
use App\NativeComponents\Concerns\OpensInTab;
use App\NativeComponents\Concerns\RefreshesInBackground;
use App\NativeComponents\Concerns\ShowsCommits;
use App\NativeComponents\Concerns\TalksToDevnull;
use Closure;
use Illuminate\View\View;
use Native\Mobile\Edge\Layouts\Builders\NavAction;
use Native\Mobile\Edge\Layouts\Builders\NavBarOptions;
use Native\Mobile\Edge\NativeComponent;

/**
 * One commit: its ancestors above, its replies below, and a reply composer.
 * A part of a series has the earlier parts above it instead, and the later
 * parts below, with its replies between, as on the web. Deleted ancestors,
 * parts and replies show as tombstones.
 *
 * The commit opens at the top, with what it replies to behind "↑ N earlier
 * commits" and its replies behind "› N replies", each opened in place.
 *
 * It opens on the commit as it was last time, or opened from a row, on what
 * the row knew of it, then asks devnull for the rest behind it and fills it in.
 *
 * The reply is stashed as you type, per commit, as on the web (see Stash), so
 * leaving the page keeps it, and it's in the reply bar again, marked as
 * restored, when you come back to the commit.
 *
 * The reply bar has no room for photos or a preview, so its expand button
 * opens the reply in the composer sheet, which has them (see replyInSheet()).
 * The draft goes with it, and back to the bar if the sheet closes unsent.
 */
class CommitDetail extends NativeComponent
{
    use OpensInTab;
    use RefreshesInBackground;
    use ShowsCommits;
    use TalksToDevnull;

    public string $id = '';

    public ?Commit $commit = null;

    /** @var list<Commit> */
    public array $ancestors = [];

    /**
     * The parts of its series after the commit, in order.
     *
     * @var list<Commit>
     */
    public array $series = [];

    /** @var list<Commit> */
    public array $replies = [];

    public ?string $repliesCursor = null;

    /**
     * Whether the replies have been kept or fetched. Until then they show as loading.
     */
    public bool $repliesLoaded = false;

    /**
     * The replies of deleted replies that were opened in place, by the deleted reply's id.
     *
     * @var array<string, list<Commit>>
     */
    public array $tombstoneReplies = [];

    public string $reply = '';

    public bool $sendingReply = false;

    /**
     * Whether the reply was stashed when the page opened, which the bar marks until you empty it or drop it.
     */
    public bool $replyRestored = false;

    /**
     * Whether the ancestors show above the commit, rather than "↑ N earlier commits".
     */
    public bool $earlierShown = false;

    /**
     * Whether the replies show under the commit, rather than "› N replies".
     */
    public bool $repliesShown = false;

    /**
     * Opened from a Reply action, so the reply composer takes the focus.
     */
    public bool $focusReply = false;

    /**
     * Keys the page's scroll view. EDGE can't scroll it from PHP, so a new key
     * mounts it again at the top.
     */
    public int $scrollKey = 0;

    /**
     * The replies from the last frame, so a render only builds the replies that changed, as the Log does its rows.
     */
    private ?RowCache $replyRows = null;

    /**
     * Every frame goes in full, as on the Log (see Log::$forceFullFrames). A
     * tap on the reply field mounts the thread's scroll view again under a new
     * key, and EDGE's subtree reuse sent its unchanged rows as references to
     * nodes the phone dropped with the old one, so the thread went blank as
     * you started typing.
     */
    protected bool $forceFullFrames = true;

    public function mount(): void
    {
        $this->focusReply = $this->data('reply', false) === true;
        $this->reply = app(Stash::class)->get($this->me()?->id, $this->replyStashName());
        $this->replyRestored = $this->reply !== '';
        $this->showKept();
        $this->revalidate();
    }

    /**
     * A reply opened from here may have been deleted, or replied to, in the meantime.
     */
    public function onResume(): void
    {
        $this->forgetRefreshes();
        $this->showKept();
        $this->revalidate();
    }

    public function navTitle(): string
    {
        return 'Commit';
    }

    public function navigationOptions(): ?NavBarOptions
    {
        $options = NavBarOptions::make()->subtitle($this->commit?->shortHash ?? Display::shortHash($this->id));

        if ($this->isOwn()) {
            $options = $options->action(
                NavAction::make('delete')
                    ->icon(ios: Lucide::Trash2->ios(), android: Lucide::Trash2->android())
                    ->a11yLabel('Delete commit')
                    ->destructive()
                    ->press("confirmDelete('{$this->id}')"),
            );
        }

        return $options;
    }

    /**
     * Asks again for the commit, its replies and any deleted reply opened in place.
     */
    public function load(): void
    {
        $this->revalidate();
    }

    protected function revalidate(): void
    {
        $this->refresh($this->threadPath(), [], fn (array $json) => $this->takeThread($json));
        $this->refresh($this->threadPath().'/replies', [], fn (array $json) => $this->takeReplies($json));

        foreach (array_keys($this->tombstoneReplies) as $id) {
            $id = (string) $id;

            $this->refresh('commits/'.rawurlencode($id).'/replies', [], function (array $json) use ($id): void {
                if (array_key_exists($id, $this->tombstoneReplies)) {
                    $this->tombstoneReplies[$id] = Page::fromArray($json, Commit::fromArray(...))->items;
                }
            });
        }
    }

    protected function showsContent(): bool
    {
        return $this->commit !== null;
    }

    /**
     * "↑ N earlier commits": the ancestors open above the commit.
     */
    public function showEarlier(): void
    {
        $this->earlierShown = true;
    }

    /**
     * Tapping into the reply bar brings the commit being answered to the top:
     * the earlier commits fold away again and the page starts over from the top.
     */
    public function replyTapped(): void
    {
        $this->earlierShown = false;
        $this->scrollKey++;
    }

    /**
     * "› N replies" under the commit opens them in place, and "Hide replies" folds them away.
     */
    public function toggleReplies(): void
    {
        $this->repliesShown = ! $this->repliesShown;
    }

    /**
     * A deleted reply has no page of its own, so its replies open in place below it.
     */
    public function toggleTombstone(string $id): void
    {
        if (array_key_exists($id, $this->tombstoneReplies)) {
            unset($this->tombstoneReplies[$id]);

            return;
        }

        $this->loadTombstoneReplies($id);
    }

    public function loadMoreReplies(): void
    {
        if ($this->repliesCursor === null) {
            return;
        }

        $page = $this->attempt(fn ($devnull) => $devnull->replies($this->id, $this->repliesCursor));

        if ($page !== null) {
            // A reply you posted is shown at the end before its page loads, so it moves to where the page has it.
            $loaded = array_flip(array_map(fn (Commit $reply): string => $reply->id, $page->items));

            $this->replies = [...array_values(array_filter($this->replies, fn (Commit $reply): bool => ! isset($loaded[$reply->id]))), ...$page->items];
            $this->repliesCursor = $page->nextCursor;
        }
    }

    public function sendReply(): void
    {
        $body = trim($this->reply);

        if ($this->sendingReply || ! Commit::fits($body)) {
            return;
        }

        $this->sendingReply = true;

        try {
            $reply = $this->attempt(fn ($devnull) => $devnull->createCommit($body, $this->id));
        } finally {
            $this->sendingReply = false;
        }

        if ($reply === null) {
            return;
        }

        $this->reply = '';
        $this->updatedReply();
        $this->replies = [...$this->replies, $reply];
        $this->repliesShown = true;
        $this->openCommit($reply->id);
    }

    /**
     * Stashes the reply as it changes. An empty field forgets it, and isn't restored any more.
     */
    public function updatedReply(): void
    {
        app(Stash::class)->put($this->me()?->id, $this->replyStashName(), $this->reply);

        if ($this->reply === '') {
            $this->replyRestored = false;
        }
    }

    /**
     * The web's drop, beside a restored reply: it's thrown away, and the field starts again empty.
     */
    public function dropReplyStash(): void
    {
        $this->reply = '';
        $this->updatedReply();
    }

    /**
     * A reply sent from the composer sheet goes under the commit, as one sent from the reply bar does.
     */
    protected function showCommitted(Commit $commit): void
    {
        if ($commit->parentId !== $this->id) {
            return;
        }

        $this->reply = '';
        $this->replyRestored = false;
        $this->replies = [...$this->replies, $commit];
        $this->repliesShown = true;
    }

    /**
     * The reply bar takes back the draft it handed the sheet, as it was left there.
     */
    protected function commitSheetGone(string $view): void
    {
        if ($view === 'reply') {
            $this->reply = app(Stash::class)->get($this->me()?->id, $this->replyStashName());
            $this->replyRestored = false;
        }
    }

    /**
     * The commit this page is about takes you back where you came from. Any
     * other of yours leaves the page as it would coming back to it.
     */
    protected function showDeleted(string $id): void
    {
        if ($id === $this->id) {
            $this->back();

            return;
        }

        $this->onResume();
    }

    protected function updateShownCommits(Closure $update): void
    {
        $this->commit = $this->commit === null ? null : $update($this->commit);
        $this->ancestors = array_map($update, $this->ancestors);
        $this->series = array_map($update, $this->series);
        $this->replies = array_map($update, $this->replies);
        $this->tombstoneReplies = array_map(fn (array $replies): array => array_map($update, $replies), $this->tombstoneReplies);
    }

    /**
     * @return iterable<Commit>
     */
    protected function shownCommits(): iterable
    {
        return [...($this->commit === null ? [] : [$this->commit]), ...$this->ancestors, ...$this->series, ...$this->replies, ...array_merge([], ...array_values($this->tombstoneReplies))];
    }

    /**
     * The commit and its replies as they were kept last time. Opened from a row
     * with nothing kept, it starts from what the row knew: the commit, and the
     * commit it replies to when the row had that too.
     */
    private function showKept(): void
    {
        if (($thread = $this->cached($this->threadPath())) !== null) {
            $this->takeThread($thread);
        } elseif ($this->commit === null && ($commit = $this->data('commit')) instanceof Commit && $commit->id === $this->id) {
            $this->commit = $commit;
            $this->ancestors = $commit->parent === null ? [] : [$commit->parent];
        }

        if (($replies = $this->cached($this->threadPath().'/replies')) !== null) {
            $this->takeReplies($replies);
        }
    }

    /**
     * @param  array<string, mixed>  $json
     */
    private function takeThread(array $json): void
    {
        $thread = CommitThread::fromArray($json);

        $this->commit = $thread->commit;
        $this->ancestors = $thread->ancestors;
        $this->series = $thread->series;
    }

    /**
     * A fresh first page of replies. Pages loaded after it stay, past what it holds.
     *
     * @param  array<string, mixed>  $json
     */
    private function takeReplies(array $json): void
    {
        $page = Page::fromArray($json, Commit::fromArray(...));
        $fresh = array_flip(array_map(fn (Commit $reply): string => $reply->id, $page->items));

        $later = $this->repliesCursor !== null
            ? array_values(array_filter(array_slice($this->replies, count($page->items)), fn (Commit $reply): bool => ! isset($fresh[$reply->id])))
            : [];

        $this->replies = [...$page->items, ...$later];
        $this->repliesCursor = $later === [] ? $page->nextCursor : $this->repliesCursor;
        $this->repliesLoaded = true;
    }

    private function replyStashName(): string
    {
        return 'reply-'.$this->id;
    }

    private function threadPath(): string
    {
        return 'commits/'.rawurlencode($this->id);
    }

    private function loadTombstoneReplies(string $id): void
    {
        $page = $this->attempt(fn ($devnull) => $devnull->replies($id));

        if ($page !== null) {
            $this->tombstoneReplies[$id] = $page->items;
        }
    }

    public function isOwn(): bool
    {
        return $this->commit !== null && $this->commit->author->username === $this->me()?->username;
    }

    public function render(): View
    {
        $earlier = $this->earlierShown ? [] : $this->ancestors;
        $viewer = $this->me();
        $this->replyRows ??= new RowCache;
        $this->replyRows->startFrame();

        return view('native.commit-detail', [
            'replyRows' => $this->replyRows,
            'myColour' => $this->myColour(),
            'viewer' => $viewer,
            'stashed' => app(Stash::class)->names($viewer?->id),
            'pasteis' => app(Account::class)->pasteis(),
            'rows' => $this->commit === null ? [] : Timeline::thread(
                [...($this->earlierShown ? $this->ancestors : []), $this->commit, ...$this->series],
                laneAbove: $earlier === [] ? null : end($earlier)->lane(),
            ),
            'earlierCount' => $this->commit === null ? 0 : count($earlier),
            'earlierLane' => $earlier === [] ? null : end($earlier)->lane(),
            'replyCount' => max($this->commit->repliesCount ?? 0, count($this->replies)),
        ]);
    }
}
