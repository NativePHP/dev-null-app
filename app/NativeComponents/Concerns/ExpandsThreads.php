<?php

namespace App\NativeComponents\Concerns;

use App\Devnull\Data\Commit;
use App\Devnull\Data\Page;

/**
 * "› N replies" under a commit in a feed opens its replies in place, the way
 * the web's thread toggle does, and "Hide replies" closes them again. One
 * level deep: a reply's own replies open its page.
 *
 * The app asks for replies oldest first, five at a time (see
 * Client::RepliesPerPage). A thread opened in place shows its first five,
 * and "Show more" under them opens the rest on the commit's page, with its
 * replies showing.
 *
 * Under a commit devnull sends with its newest reply, "Show more" opens the
 * rest in place, newest first as on the web, so they're turned round here,
 * and a thread longer than a page opens the commit's page instead.
 *
 * A thread opened before opens at once from the cache, and refreshes behind.
 */
trait ExpandsThreads
{
    /**
     * The replies of each commit opened in place, by the commit's id.
     *
     * @var array<string, list<Commit>>
     */
    public array $threads = [];

    /**
     * Threads opened from a preview with "Show more", which read newest first.
     *
     * @var list<string>
     */
    public array $newestFirst = [];

    /**
     * Threads opened in place with more replies than they show.
     *
     * @var list<string>
     */
    public array $threadsWithMore = [];

    public function toggleReplies(string $id): void
    {
        if (array_key_exists($id, $this->threads)) {
            $this->closeThread($id);

            return;
        }

        $kept = $this->cached('commits/'.$id.'/replies');

        if ($kept === null) {
            $this->loadThread($id);

            return;
        }

        $this->takeThread($id, $kept);
        $this->refreshThread($id);
    }

    /**
     * "Show more" under the newest reply: the rest of the thread, in place.
     */
    public function showMoreReplies(string $id): void
    {
        if (array_key_exists($id, $this->threads)) {
            return;
        }

        if (! in_array($id, $this->newestFirst, true)) {
            $this->newestFirst[] = $id;
        }

        $kept = $this->cached('commits/'.$id.'/replies');

        if ($kept === null) {
            $this->loadThread($id);

            return;
        }

        $this->takeThread($id, $kept);
        $this->refreshThread($id);
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
            if (array_key_exists($id, $this->threads)) {
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
     * @param  Page<Commit>  $page
     */
    private function openThread(string $id, Page $page): void
    {
        // A thread whose last reply went closes, so its commit gets its hairline back.
        if ($page->items === []) {
            $this->closeThread($id);

            return;
        }

        if (! in_array($id, $this->newestFirst, true)) {
            $this->threads[$id] = $page->items;
            $this->threadsWithMore = array_values(array_diff($this->threadsWithMore, [$id]));

            if ($page->hasMore()) {
                $this->threadsWithMore[] = $id;
            }

            return;
        }

        // More than a page: the newest replies aren't on it, so the commit's page shows them.
        if ($page->hasMore()) {
            $this->closeThread($id);
            $this->newestFirst = array_values(array_diff($this->newestFirst, [$id]));
            $this->openReplies($id);

            return;
        }

        $this->threads[$id] = array_reverse($page->items);
    }

    private function closeThread(string $id): void
    {
        unset($this->threads[$id]);
        $this->threadsWithMore = array_values(array_diff($this->threadsWithMore, [$id]));
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
