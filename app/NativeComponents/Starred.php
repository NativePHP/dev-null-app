<?php

namespace App\NativeComponents;

use App\Devnull\Data\Commit;
use App\Devnull\Data\Page;
use App\Devnull\ResponseCache;
use App\Devnull\RowCache;
use App\Devnull\ShownTabs;
use App\Devnull\Stash;
use App\Devnull\Timeline;
use App\NativeComponents\Concerns\ExpandsThreads;
use App\NativeComponents\Concerns\OpensInTab;
use App\NativeComponents\Concerns\RefreshesInBackground;
use App\NativeComponents\Concerns\ShowsCommits;
use App\NativeComponents\Concerns\TalksToDevnull;
use App\NativeComponents\Layouts\TabsLayout;
use Closure;
use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Edge\Transition;

/**
 * The commits you starred, newest first, as the web's Starred page lists them:
 * the Log's rows, with a day marker wherever the day of the commits changes.
 * Only you see them.
 *
 * Unstarring one here takes its row away once devnull says it's done, as on the
 * web. Starring or unstarring anywhere else changes the kept list too (see
 * ResponseCache::commitStarred), and a tab tap mounts this screen afresh, so it
 * shows the next time you open it.
 *
 * It opens on the list as it was last time, then asks devnull behind it. Only
 * you change it, so what devnull sends goes straight in: there's no pill.
 */
class Starred extends NativeComponent
{
    use ExpandsThreads;
    use OpensInTab;
    use RefreshesInBackground;
    use ShowsCommits {
        toggleStar as private toggleShownStar;
        showStar as private showShownStar;
    }
    use TalksToDevnull;

    /**
     * Every frame goes in full, as on the Log (see Log::$forceFullFrames), since rows leave the list from the middle.
     */
    protected bool $forceFullFrames = true;

    /** @var list<Commit> */
    public array $commits = [];

    public ?string $nextCursor = null;

    /**
     * Whether the list has had commits to show, kept or fresh. Until then it shows skeleton rows.
     */
    public bool $loaded = false;

    /**
     * The rows from the last frame, so a render only builds the rows that changed, as on the Log.
     */
    private ?RowCache $rowCache = null;

    public function mount(): void
    {
        $this->showKept();
        $this->revalidate();
    }

    /**
     * Back from a commit or a profile: take any change made there from the cache at once, then ask devnull.
     */
    public function onResume(): void
    {
        $this->forgetRefreshes();
        $this->forgetGone();
        $this->showKept();
        $this->revalidate();
    }

    public function navTitle(): string
    {
        return 'Starred';
    }

    /**
     * Pull to refresh.
     */
    public function load(): void
    {
        $this->refresh('starred', [], fn (array $json) => $this->takeFresh($json), again: true);
        $this->refreshThreads();
        $this->refreshUnreadNotifications();
    }

    protected function revalidate(): void
    {
        $this->refresh('starred', [], fn (array $json) => $this->takeFresh($json));
        $this->refreshThreads();
        $this->refreshUnreadNotifications();
    }

    protected function showsContent(): bool
    {
        return $this->loaded;
    }

    public function loadMore(): void
    {
        if ($this->nextCursor === null) {
            return;
        }

        $page = $this->attempt(fn ($devnull) => $devnull->starred($this->nextCursor));

        if ($page !== null) {
            $this->commits = [...$this->commits, ...self::without($page->items, self::ids($this->commits))];
            $this->nextCursor = $page->nextCursor;
        }
    }

    /**
     * Stars or unstars straight away, as anywhere else. A commit you unstarred
     * leaves the list at once, and comes back if devnull turns the unstar down
     * (see showStar()). When it was the last one showing and there are more,
     * the list starts again from the top, as on the web.
     */
    public function toggleStar(string $id): void
    {
        $this->toggleShownStar($id);

        $row = array_values(array_filter($this->commits, fn (Commit $commit): bool => $commit->id === $id))[0] ?? null;

        // A reply under a starred commit can be unstarred too, and it isn't a row.
        if ($row?->starred !== false) {
            return;
        }

        $this->commits = self::without($this->commits, [$id]);
        unset($this->threads[$id]);

        if ($this->commits === [] && $this->nextCursor !== null) {
            $this->nextCursor = null;
            $this->loaded = false;
            $this->load();
        }
    }

    /**
     * An unstar devnull turned down: the commit comes back into the list, from
     * the kept copy the star went back on, then as devnull lists it.
     */
    protected function showStar(string $id, bool $starred, ?Commit $fresh = null): void
    {
        $this->showShownStar($id, $starred, $fresh);

        if ($starred && $fresh === null && $this->shownCommit($id) === null) {
            $this->showKept();
            $this->revalidate();
        }
    }

    /**
     * The empty list's Explore Trending: the Log, open on Trending, as its tab opens it.
     */
    public function exploreTrending(): void
    {
        app(ShownTabs::class)->remember($this->me()?->id, 'log', Log::Ranked);

        $this->replace(TabsLayout::Tabs[0])->transition(Transition::None);
    }

    /**
     * @return iterable<Commit>
     */
    protected function shownCommits(): iterable
    {
        return [...$this->commits, ...$this->threadCommits()];
    }

    protected function updateShownCommits(Closure $update): void
    {
        $this->commits = array_map($update, $this->commits);
        $this->threads = array_map(fn (array $replies): array => array_map($update, $replies), $this->threads);
    }

    private function showKept(): void
    {
        if (($json = $this->cached('starred')) !== null) {
            $this->takeFresh($json);
        }
    }

    /**
     * Commits unstarred or deleted on this phone leave the list, wherever they
     * are in it. A kept first page only knows about its own.
     */
    private function forgetGone(): void
    {
        $cache = app(ResponseCache::class);

        $this->commits = self::without($this->commits, [...$cache->unstarredIds(), ...$cache->deletedIds()]);
    }

    /**
     * A fresh first page replaces the top of the list. The pages loaded below
     * it stay, from the last commit the fresh page also has, unless it's the
     * whole list. Starring a commit again moves it to the top, so rows are
     * matched from the end of the page, where the order hasn't changed.
     *
     * @param  array<string, mixed>  $json
     */
    private function takeFresh(array $json): void
    {
        $page = Page::fromArray($json, Commit::fromArray(...));
        $shownAt = array_flip(self::ids($this->commits));
        $later = [];

        foreach ($page->nextCursor === null ? [] : array_reverse($page->items) as $commit) {
            if (isset($shownAt[$commit->id])) {
                $later = self::without(array_slice($this->commits, $shownAt[$commit->id] + 1), self::ids($page->items));

                break;
            }
        }

        $this->commits = [...$page->items, ...$later];
        $this->nextCursor = $later === [] ? $page->nextCursor : $this->nextCursor;
        $this->loaded = true;
    }

    /**
     * @param  list<Commit>  $commits
     * @param  list<string>  $ids
     * @return list<Commit>
     */
    private static function without(array $commits, array $ids): array
    {
        $leaving = array_flip($ids);

        return array_values(array_filter($commits, fn (Commit $commit): bool => ! isset($leaving[$commit->id])));
    }

    /**
     * @param  list<Commit>  $commits
     * @return list<string>
     */
    private static function ids(array $commits): array
    {
        return array_map(fn (Commit $commit): string => $commit->id, $commits);
    }

    public function render(): View
    {
        $lastCommit = $this->commits[array_key_last($this->commits) ?? -1] ?? null;
        $viewer = $this->me();
        $this->rowCache ??= new RowCache;
        $this->rowCache->startFrame();

        return view('native.starred', [
            'rowCache' => $this->rowCache,
            'viewer' => $viewer,
            'stashed' => app(Stash::class)->names($viewer?->id),
            'myColour' => $this->myColour(),
            'rows' => Timeline::feed($this->commits),
            'lastCommit' => $lastCommit,
        ]);
    }
}
