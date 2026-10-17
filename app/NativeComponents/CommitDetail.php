<?php

namespace App\NativeComponents;

use App\Devnull\Data\Commit;
use App\Devnull\Data\CommitThread;
use App\Devnull\Data\Page;
use App\Devnull\Display;
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

    public function mount(): void
    {
        $this->focusReply = $this->data('reply', false) === true;
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
            $this->replies = [...$this->replies, ...$page->items];
            $this->repliesCursor = $page->nextCursor;
        }
    }

    public function sendReply(): void
    {
        $body = trim($this->reply);

        if (mb_strlen($body) < Commit::MinLength || mb_strlen($body) > Commit::MaxLength) {
            return;
        }

        $reply = $this->attempt(fn ($devnull) => $devnull->createCommit($body, $this->id));

        if ($reply === null) {
            return;
        }

        $this->reply = '';
        $this->replies = [...$this->replies, $reply];
        $this->repliesShown = true;
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

        return view('native.commit-detail', [
            'viewer' => $this->me(),
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
