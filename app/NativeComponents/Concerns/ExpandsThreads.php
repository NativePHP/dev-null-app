<?php

namespace App\NativeComponents\Concerns;

use App\Devnull\Beat;
use App\Devnull\Client;
use App\Devnull\Data\Commit;
use App\Devnull\Data\Page;
use App\Devnull\SelfThread;
use App\Devnull\ThreadRows;

/**
 * Replies in a feed stay hidden until you ask for them. "N replies", on its
 * own line under a commit's actions, opens its replies in place, oldest first,
 * ten at a time (Client::RepliesPerPage), with "Show more" under the last for
 * the next ten. Tapped again ("Hide replies") it closes them. devnull's API
 * sends 50 replies a page whatever `limit` asks for, so the replies it sent
 * are kept and shown ten at a time ($threadShown), and the next page is only
 * asked for once they're all showing.
 *
 * A reply opened in place has the same button under its own actions, which
 * opens its replies in place too, one step in. A reply that deep opens its
 * replies on its page. Every thread is kept by the id of the commit or reply
 * it answers, so a reply's replies sit in $threads beside its commit's.
 *
 * EDGE can't animate a row coming into a list or going out of it, so replies
 * fade: a page that opens is drawn see-through ($fadedRows) and drawn again
 * as it should be once a Beat comes back, which every row in a thread
 * animates to. Closing fades its rows out the same way, then takes them out.
 *
 * A thread opened before opens at once from the cache, and refreshes behind.
 *
 * The reply that carries on a thread its author wrote as replies to
 * themselves isn't one of the replies, as on the web: it's the next part of
 * that thread, which the commit's "Show thread" opens (see SelfThread).
 * devnull's count of the replies already leaves it out.
 */
trait ExpandsThreads
{
    /**
     * The replies opened in place, by the id of the commit or reply they answer.
     *
     * @var array<string, list<Commit>>
     */
    public array $threads = [];

    /**
     * Where the next page of each open thread starts, by the same id, for those with more.
     *
     * @var array<string, string>
     */
    public array $threadCursors = [];

    /**
     * How many of each open thread's replies show, by the same id.
     *
     * @var array<string, int>
     */
    public array $threadShown = [];

    /**
     * Open threads that have had a page after their first.
     *
     * @var list<string>
     */
    public array $pagedThreads = [];

    /**
     * Rows drawn see-through: a page about to fade in, or a thread fading out.
     * A reply's row goes by its id, a thread's "Show more" by "more-" and the
     * thread's id.
     *
     * @var list<string>
     */
    public array $fadedRows = [];

    /**
     * Threads fading out, still drawn until they've faded.
     *
     * @var list<string>
     */
    public array $closingThreads = [];

    /**
     * Commits whose replies, opened, showed that their author carried them on as a thread, so they show "Show thread".
     *
     * @var list<string>
     */
    public array $threadsContinued = [];

    public function toggleReplies(string $id): void
    {
        if (array_key_exists($id, $this->threads)) {
            $this->fadeOut($id);

            return;
        }

        $kept = $this->cached('commits/'.$id.'/replies');

        if ($kept === null) {
            $this->loadThread($id);
        } else {
            $this->takeThread($id, $kept);
            $this->refreshThread($id);
        }

        if (array_key_exists($id, $this->threads)) {
            $this->threadShown[$id] = min(Client::RepliesPerPage, count($this->threads[$id]));
            $this->fadeIn($this->rowsOf($id, $this->shownReplies($id)));
        }
    }

    /**
     * "Show more" under an open thread: its next page, under what's showing.
     */
    public function showMoreReplies(string $id): void
    {
        if (! $this->isOpenThread($id)) {
            return;
        }

        $shown = $this->threadShown[$id] ?? count($this->threads[$id]);

        if ($shown >= count($this->threads[$id]) && ! $this->loadNextPage($id)) {
            return;
        }

        $this->threadShown[$id] = min($shown + Client::RepliesPerPage, count($this->threads[$id]));
        $this->fadeIn($this->rowsOf($id, array_slice($this->threads[$id], $shown, $this->threadShown[$id] - $shown)));
    }

    /**
     * The replies of an open thread that show.
     *
     * @return list<Commit>
     */
    public function shownReplies(string $id): array
    {
        return array_slice($this->threads[$id] ?? [], 0, $this->threadShown[$id] ?? null);
    }

    /**
     * Whether an open thread has replies past those that show, kept or still to ask for.
     */
    public function hasMoreReplies(string $id): bool
    {
        return isset($this->threadCursors[$id]) || count($this->shownReplies($id)) < count($this->threads[$id] ?? []);
    }

    /**
     * Asks for the page after an open thread's replies, under them. False when there's none or it didn't come.
     */
    private function loadNextPage(string $id): bool
    {
        $cursor = $this->threadCursors[$id] ?? null;

        if ($cursor === null) {
            return false;
        }

        $page = $this->attempt(fn ($devnull) => $devnull->replies($id, $cursor));

        if ($page === null || ! $this->isOpenThread($id)) {
            return false;
        }

        $loaded = array_flip(array_map(fn (Commit $reply): string => $reply->id, $this->threads[$id]));
        $added = array_values(array_filter($page->items, fn (Commit $reply): bool => ! isset($loaded[$reply->id])));

        $this->threads[$id] = [...$this->threads[$id], ...$added];
        $this->keepCursor($id, $page->nextCursor);
        $this->pagedThreads = array_values(array_unique([...$this->pagedThreads, $id]));

        return $added !== [];
    }

    /**
     * Whether a thread shows, and isn't on its way out.
     */
    public function isOpenThread(string $id): bool
    {
        return array_key_exists($id, $this->threads) && ! in_array($id, $this->closingThreads, true);
    }

    /**
     * Closes every thread at once, for a list that starts again.
     */
    protected function forgetThreads(): void
    {
        $this->threads = $this->threadCursors = $this->threadShown = $this->pagedThreads = [];
        $this->fadedRows = $this->closingThreads = [];
    }

    /**
     * Asks again for every open thread, so a reply made or deleted elsewhere shows up.
     */
    protected function refreshThreads(): void
    {
        foreach (array_keys($this->threads) as $id) {
            $this->refreshThread((string) $id);
        }
    }

    private function refreshThread(string $id): void
    {
        $this->refresh('commits/'.$id.'/replies', [], function (array $json) use ($id): void {
            // Closed while it was on its way.
            if ($this->isOpenThread($id)) {
                $this->takeThread($id, $json);
            }
        });
    }

    /**
     * @param  array<string, mixed>  $json
     */
    private function takeThread(string $id, array $json): void
    {
        $this->openThread($id, Page::fromArray($json, Commit::fromArray(...)));
    }

    /**
     * A thread's first page, over what it showed. Pages loaded after the first
     * stay, past what it holds, with their own cursor.
     *
     * @param  Page<Commit>  $page
     */
    private function openThread(string $id, Page $page): void
    {
        $page = $this->withoutContinuation($id, $page);

        // A thread whose last reply went closes, so its commit gets its hairline back.
        if ($page->items === []) {
            $this->closeThread($id);

            return;
        }

        // With only the first page loaded, the fresh one is the whole thread: a reply that's gone goes.
        if (in_array($id, $this->pagedThreads, true)) {
            $fresh = array_flip(array_map(fn (Commit $reply): string => $reply->id, $page->items));
            $later = array_values(array_filter(array_slice($this->threads[$id], count($page->items)), fn (Commit $reply): bool => ! isset($fresh[$reply->id])));
            $this->threads[$id] = [...$page->items, ...$later];

            return;
        }

        $this->threads[$id] = $page->items;
        $this->keepCursor($id, $page->nextCursor);
    }

    private function keepCursor(string $id, ?string $cursor): void
    {
        unset($this->threadCursors[$id]);

        if ($cursor !== null) {
            $this->threadCursors[$id] = $cursor;
        }
    }

    /**
     * Takes a thread out, and the replies opened in it.
     */
    private function closeThread(string $id): void
    {
        foreach ($this->threads[$id] ?? [] as $reply) {
            $this->closeThread($reply->id);
        }

        unset($this->threads[$id], $this->threadCursors[$id], $this->threadShown[$id]);
        $this->pagedThreads = array_values(array_diff($this->pagedThreads, [$id]));
        $this->closingThreads = array_values(array_diff($this->closingThreads, [$id]));
    }

    /**
     * Draws rows see-through for a frame, then as they are, which they fade to.
     *
     * @param  list<string>  $rows
     */
    private function fadeIn(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $this->fadedRows = array_values(array_unique([...$this->fadedRows, ...$rows]));

        $shown = fn () => $this->fadedRows = array_values(array_diff($this->fadedRows, $rows));

        Beat::dispatch(Beat::Step)->finished($shown)->failed($shown);
    }

    /**
     * Fades a thread's rows out, and the replies opened in it, then takes them out.
     */
    private function fadeOut(string $id): void
    {
        if (in_array($id, $this->closingThreads, true)) {
            return;
        }

        $rows = $this->rowsInside($id);
        $this->closingThreads[] = $id;
        $this->fadedRows = array_values(array_unique([...$this->fadedRows, ...$rows]));

        $closed = function () use ($id, $rows): void {
            $this->closeThread($id);
            $this->fadedRows = array_values(array_diff($this->fadedRows, $rows));
        };

        Beat::dispatch(ThreadRows::Fade)->finished($closed)->failed($closed);
    }

    /**
     * The page without the reply that carries the commit's thread on, which marks the commit as carried on instead.
     *
     * @param  Page<Commit>  $page
     * @return Page<Commit>
     */
    private function withoutContinuation(string $id, Page $page): Page
    {
        $commit = $this->shownCommit($id);
        $next = $commit === null ? null : SelfThread::next($commit, $page->items);

        if ($next === null) {
            return $page;
        }

        if (! in_array($id, $this->threadsContinued, true)) {
            $this->threadsContinued[] = $id;
        }

        return new Page(SelfThread::others($commit, $page->items), $page->nextCursor);
    }

    /**
     * The rows of $replies in a thread, and its "Show more" when it has more.
     *
     * @param  list<Commit>  $replies
     * @return list<string>
     */
    private function rowsOf(string $id, array $replies): array
    {
        return [
            ...array_map(fn (Commit $reply): string => $reply->id, $replies),
            ...($this->hasMoreReplies($id) ? ['more-'.$id] : []),
        ];
    }

    /**
     * Every row a thread draws, the replies opened inside it included.
     *
     * @return list<string>
     */
    private function rowsInside(string $id): array
    {
        $rows = $this->rowsOf($id, $this->shownReplies($id));

        foreach ($this->shownReplies($id) as $reply) {
            if (array_key_exists($reply->id, $this->threads)) {
                $rows = [...$rows, ...$this->rowsInside($reply->id)];
            }
        }

        return $rows;
    }

    /**
     * @return list<Commit>
     */
    protected function threadCommits(): array
    {
        return array_merge([], ...array_values($this->threads));
    }

    private function loadThread(string $id): void
    {
        $page = $this->attempt(fn ($devnull) => $devnull->replies($id));

        if ($page !== null) {
            $this->openThread($id, $page);
        }
    }
}
