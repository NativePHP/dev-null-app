<?php

namespace App\NativeComponents;

use App\Devnull\Account;
use App\Devnull\Data\Commit;
use App\Devnull\Data\Page;
use App\Devnull\Data\TimelineRow;
use App\Devnull\Data\User;
use App\Devnull\Display;
use App\Devnull\LastVisit;
use App\Devnull\ResponseCache;
use App\Devnull\RowCache;
use App\Devnull\RowWindow;
use App\Devnull\ShownTabs;
use App\Devnull\Stash;
use App\Devnull\Timeline;
use App\Icons\Android;
use App\Icons\Ios;
use App\Models\PendingWrite;
use App\NativeComponents\Concerns\DrawsSkeletonFirst;
use App\NativeComponents\Concerns\ExpandsThreads;
use App\NativeComponents\Concerns\HoldsNewCommits;
use App\NativeComponents\Concerns\OpensInTab;
use App\NativeComponents\Concerns\OpensLiveStream;
use App\NativeComponents\Concerns\RefreshesInBackground;
use App\NativeComponents\Concerns\ShowsCommits;
use App\NativeComponents\Concerns\SuggestsWhoToFollow;
use App\NativeComponents\Concerns\TalksToDevnull;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\View\View;
use Native\Mobile\Attributes\Poll;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\Layouts\Builders\NavAction;
use Native\Mobile\Edge\Layouts\Builders\NavBarOptions;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Events\Async\AsyncTaskFailed;
use Native\Mobile\Events\Async\AsyncTaskFinished;

/**
 * The log: the web's three of them, Following (you and the people you follow),
 * Latest (everyone, newest first) and Trending (ranked by stars and replies).
 *
 * EDGE gives PHP no way to scroll a list, so a new key on the list is how it
 * gets back to the top.
 *
 * The Log has no composer of its own. The nav bar's compose button opens one
 * in a sheet (see OpensCommitSheet), which leaves the log where you were in it,
 * and the commit goes on top. A field in the list was redrawn with the whole
 * log every time it synced, and fast typing lost and reordered letters.
 *
 * It opens on the log as it was last time, then asks devnull again in the
 * background, and every 30 seconds while it's showing, as the web polls.
 * Commits newer than anything showing wait at the top of the list in the new
 * commits pill (see HoldsNewCommits), which says how many devnull counted
 * since the newest one showing (?since=). Scrolled down, it pins under the tabs.
 *
 * Trending is ranked, so newer commits don't go on top of it. As on the web,
 * it's ranked when it opens and when you pull to refresh, and never re-ranked
 * under you in between: later answers only change the rows showing in place.
 * The pill offers the commits newer than the ranking, other than yours, and
 * puts them above it under "Just now".
 *
 * The three logs are pages you swipe between, as paged tabs go in native apps.
 * The pager draws the tabs and moves them with your finger, and only says
 * which log it settled on once the swipe is over. Only the log you're on is
 * live. The others show the first few commits kept for them, as they'll open,
 * so there's something under your finger as you swipe. Each page has its own
 * list. A log you leave starts again at the top of a fresh one, as a tab tap
 * opens it, since a list kept while every row changed under its scroll offset
 * drew blank on iOS.
 *
 * The log you're on keeps every commit it has loaded, but only draws those
 * near the screen and the first few (see RowWindow). The list says where
 * you've scrolled to, and the rest go as placeholders the phone draws at their
 * rows' heights, so a log scrolled thousands of commits down costs what one a
 * couple of hundred down does.
 *
 * Every frame the phone gets while the list is moving costs its main thread a
 * pass over the list, and that's where the scrolling stutters. So while it
 * moves, the log only sends what can't wait: rows drawn further on before the
 * screen reaches the end of them, and the next page before the end of the
 * list. The rest waits for the list to come to rest (see scrollEnded()).
 *
 * The pages beside the one you're on are kept small on purpose. EDGE writes
 * each frame into a buffer of fixed size, and a frame that doesn't fit is
 * dropped without a word; the frames after it then refer to rows the phone
 * never got, which leaves holes in the list.
 *
 * The log you're on fetches its next page in the background as soon as it
 * knows the cursor, and holds it in memory until you scroll near the end.
 * It used to be asked for on the UI thread once you got there, and PHP sat
 * waiting on devnull while the phone kept scrolling, so you reached the
 * loading row and stayed on it. Now the page goes in a little before the end
 * (see NextPageWithin), or when the end comes, so it's usually there before
 * you are. Its images come down with it. Once you've scrolled, pages go in
 * further ahead whenever the list is at rest (see NextPageAtRestWithin), so a
 * fling rarely needs one on the way. A held page only follows on from
 * the list it was cut from, so it's let go when that list is replaced rather
 * than added to: a fresh ranking, another log, pull to refresh, or the pill
 * replacing the list. Cursor pages are never kept in the response cache.
 */
class Log extends NativeComponent
{
    use DrawsSkeletonFirst;
    use ExpandsThreads;
    use HoldsNewCommits;
    use OpensInTab;
    use OpensLiveStream;
    use RefreshesInBackground;
    use ShowsCommits;
    use SuggestsWhoToFollow;
    use TalksToDevnull;

    /**
     * Every frame goes in full. EDGE's subtree reuse sometimes refers to rows
     * the phone never got once a list has grown or reordered, and the phone
     * drops them, which drew the log blank under a pull to refresh. EDGE's own
     * answer for a screen whose lists change often is to send it in full.
     */
    protected bool $forceFullFrames = true;

    public const array Logs = ['following', 'latest', 'trending'];

    /**
     * The web's tab labels.
     */
    public const array Labels = ['following' => 'Following', 'latest' => 'Latest', 'trending' => 'Trending'];

    /**
     * The log that's ranked rather than newest first.
     */
    public const string Ranked = 'trending';

    /**
     * How many kept commits the log beside yours shows, enough for the top of
     * the screen while you swipe to it. The log two swipes away shows skeleton
     * rows until you're beside it: every row sent is one the phone builds, and
     * on a slow Android phone the off-screen pages were a third of the frame.
     */
    public const int PreviewCommits = 3;

    /**
     * How near the end of the log a next page fetched ahead goes in while the
     * list moves, in timeline rows below the last one on screen. The list says
     * which rows are on screen every 20 rows, and asks for more 5 rows from its
     * end (see log-page), so anything under 25 could let the end come before a
     * report does. 40 leaves room for a report that lands late. A commit's
     * thread replies count as one timeline row, so in list rows it's never less.
     */
    public const int NextPageWithin = 40;

    /**
     * How near the end of the log pages go in while the list is at rest, in
     * timeline rows below the last one on screen, one after another as they
     * land. A frame then can't make the scrolling stutter, and the rows are
     * there for the next fling, which only needs a page on the way once it
     * comes within NextPageWithin.
     */
    public const int NextPageAtRestWithin = 80;

    public string $log = 'following';

    /**
     * The list key of each log you're not on. The log you're on uses $listKey.
     *
     * @var array<string, int>
     */
    public array $listKeys = [];

    /**
     * Trending's commits newer than its ranking, put above it by the pill, newest first.
     *
     * @var list<Commit>
     */
    public array $latestCommits = [];

    /**
     * The newest commit when Trending was ranked, or the newest put above it
     * since. Newer commits are counted from it.
     */
    public ?string $rankedSince = null;

    /**
     * A few commits from people worth following, for a Following log with nobody in it.
     *
     * @var list<Commit>
     */
    public array $suggestions = [];

    /**
     * Whether the log has had commits to show, kept or fresh. Until then it shows skeleton rows.
     */
    public bool $loaded = false;

    /**
     * The newest commit time you'd been shown in this log when the screen opened.
     * The last-visit marker stays put against it while the screen is open, as on the web.
     */
    public ?string $lastVisitAt = null;

    /**
     * The log's next page, fetched before you scroll to it: the log and cursor
     * it was asked for, the list it was cut from (see $listGeneration), and the
     * page itself.
     *
     * @var array{log: string, cursor: string, generation: int, page: Page<Commit>}|null
     */
    protected ?array $heldPage = null;

    /**
     * The next page's fetch that's still out, by log, cursor and list, and when it was sent.
     *
     * @var array{log: string, cursor: string, generation: int, sentAt: float}|null
     */
    protected ?array $fetchingNextPage = null;

    /**
     * Which list the next cursor points into. It goes up whenever the list is
     * replaced rather than added to, so a page cut from the old list is never
     * put under the new one, even when the cursor reads the same, as it may
     * from one ranking of Trending to the next.
     */
    protected int $listGeneration = 0;

    /**
     * The end of the list came while its next page was on its way. The page goes in when it lands.
     */
    protected bool $waitingForNextPage = false;

    /**
     * Whether the list is moving: it has said which rows are on screen since
     * it last came to rest. Null until it's first scrolled, so opening the log
     * doesn't fetch pages ahead that you may never scroll to.
     */
    protected ?bool $listScrolling = null;

    /**
     * Each log's rows from the last frame, so a render only builds the rows that changed.
     *
     * @var array<string, RowCache>
     */
    private array $rowCaches = [];

    /**
     * Which rows of each log are drawn.
     *
     * @var array<string, RowWindow>
     */
    private array $rowWindows = [];

    /**
     * Each log's rows from its last frame, laid out again only when its commits
     * or the day change: for a log thousands of commits long, laying them all
     * out cost more than drawing the rows near the screen.
     *
     * @var array<string, array{0: string, 1: list<TimelineRow>}>
     */
    private array $timelines = [];

    /**
     * The time the last frame was drawn at.
     */
    private ?CarbonImmutable $drawnAt = null;

    /**
     * The frame about to be drawn follows a background task landing.
     */
    private bool $taskLanded = false;

    /**
     * The frame about to be drawn changes nothing on screen, mid-scroll.
     */
    private bool $keepsFrame = false;

    /**
     * The log is the first screen the app opens on, so it sends what was
     * left in the outbox when the app last closed (see SyncsOutbox).
     */
    public function mount(): void
    {
        $this->log = app(ShownTabs::class)->get($this->me()?->id, 'log', self::Logs, 'following');
        $this->lastVisitAt = $this->lastVisitSeenAt();
        $this->sendLeftovers();
        $this->fillInAfterFirstFrame('open');
    }

    /**
     * Back from a commit or a profile: take any change made there from the
     * cache at once, then ask devnull. An answer that landed while another
     * screen was on top was dropped, the next page's among them, so that's
     * asked for again, and goes in when it lands if the end of the list was
     * waiting for it.
     */
    public function onResume(): void
    {
        $this->forgetRefreshes();
        $this->fetchingNextPage = null;
        $this->forgetDeleted();
        $this->forgetHidden();
        $this->showKept();
        $this->revalidate();
        $this->prefetchNextPage();
        $this->openWhoToFollow(again: true);
    }

    /**
     * The web's poll for new commits.
     */
    #[Poll(30000)]
    public function checkForNewCommits(): void
    {
        // Times catch up when the list comes to rest instead.
        $this->keepsFrame = $this->listScrolling === true;
        $this->revalidate();
    }

    /**
     * The bar shows the logo instead (see TabsLayout). This names it for VoiceOver and the back menu.
     */
    public function navTitle(): string
    {
        return 'devnull';
    }

    public function navigationOptions(): ?NavBarOptions
    {
        return NavBarOptions::make()->action(
            NavAction::make('compose')
                ->icon(ios: Ios::SquareAndPencil, android: Android::Edit)
                ->a11yLabel('New commit')
                ->press('composeInSheet'),
        );
    }

    /**
     * Shows a log, from a link such as the empty Following log's Trending, and
     * the pager follows. The log you're on goes back to its top.
     */
    public function show(string $log): void
    {
        if (! in_array($log, self::Logs, true)) {
            return;
        }

        if ($log === $this->log) {
            $this->listKey++;

            return;
        }

        $this->switchTo($log);
    }

    /**
     * The pager settling on a log, after a swipe or a tab tap. The log it's already on changes nothing.
     */
    public function showPage(int $index): void
    {
        $log = self::Logs[$index] ?? null;

        if ($log !== null && $log !== $this->log) {
            $this->switchTo($log);
        }
    }

    /**
     * A tap on the tab of the log you're on, which the pager sends instead of moving: back to the top of it.
     */
    public function backToTop(int $index): void
    {
        $this->show(self::Logs[$index] ?? '');
    }

    /**
     * The log you go to keeps the list its first few commits were drawn in,
     * which can't have been scrolled, and fills it. The log you leave starts
     * again at the top of a fresh one.
     */
    private function switchTo(string $log): void
    {
        app(ShownTabs::class)->remember($this->me()?->id, 'log', $log);
        $this->listKeys[$this->log] = $this->listKey + 1;
        $this->log = $log;
        $this->listKey = $this->listKeys[$log] ?? 0;
        $this->openAfresh();
    }

    /**
     * Opens the log afresh: what was kept for it, then asked again.
     */
    private function openAfresh(): void
    {
        $this->commits = [];
        $this->threads = [];
        $this->latestCommits = [];
        $this->suggestions = [];
        $this->rankedSince = null;
        $this->nextCursor = null;
        $this->loaded = false;
        $this->error = null;
        $this->refreshFailed = false;
        $this->forgetNewCommits();
        $this->replacedList();
        $this->lastVisitAt = $this->lastVisitSeenAt();
        $this->showKept();
        $this->open();
    }

    /**
     * A log you're not on: the first few commits kept for it, as it will
     * open, or skeleton rows with nothing kept, or while it's two swipes away.
     *
     * @return array<string, mixed>
     */
    private function previewState(string $log): array
    {
        $beside = abs(array_search($log, self::Logs, true) - array_search($this->log, self::Logs, true)) === 1;
        $json = $beside && ! $this->skeletonFrame ? $this->cached('log/'.$log) : null;
        $kept = is_array($json['data'] ?? null) ? $json['data'] : [];
        $page = $json === null ? null : Page::fromArray(['data' => array_slice($kept, 0, self::PreviewCommits)] + $json, Commit::fromArray(...));

        return [
            'commits' => $page->items ?? [],
            // Rows past the preview are left out, with a skeleton row for them, as for the next page.
            'nextCursor' => count($kept) > self::PreviewCommits ? 'preview' : $page?->nextCursor,
            'threads' => [],
            'latestCommits' => [],
            'suggestions' => array_values(array_map(Commit::fromArray(...), array_filter(is_array($json['suggestions'] ?? null) ? $json['suggestions'] : [], is_array(...)))),
            'loaded' => $page !== null,
            'lastVisitAt' => $this->lastVisitSeenAt($log),
            'error' => null,
        ];
    }

    public function isRanked(): bool
    {
        return $this->log === self::Ranked;
    }

    /**
     * Pull to refresh. You're at the top, so anything new goes straight in.
     */
    public function load(): void
    {
        if ($this->isRanked()) {
            $this->rank();

            return;
        }

        $log = $this->log;
        $this->refresh('log/'.$log, [], fn (array $json) => $this->takeFresh($log, $json, showNewer: true), again: true);
        $this->refreshThreads();
        $this->refreshUnreadNotifications();
    }

    /**
     * The pill: the new commits go on top, and they're the newest you've seen now.
     */
    public function showNewCommits(): void
    {
        if ($this->newCommits === []) {
            return;
        }

        if ($this->isRanked()) {
            $this->putLatestAboveRanking();
        } else {
            $this->putNewCommitsOnTop();
            $this->rememberLastVisit();
        }

        $this->listKey++;
        $this->prefetchNextPage();
    }

    /**
     * Asks devnull what changed since the newest commit showing. Trending
     * stays as it was ranked, and only learns what's newer than the ranking.
     */
    protected function revalidate(): void
    {
        $log = $this->log;
        $since = $this->isRanked() ? $this->rankedSince : $this->newestShownId();

        $this->refresh('log/'.$log, ['since' => $since], fn (array $json) => $this->isRanked()
            ? $this->takeRankedUpdate($log, $json)
            : $this->takeFresh($log, $json));
        $this->refreshThreads();
        $this->refreshUnreadNotifications();
    }

    /**
     * Opening a log: Trending is ranked afresh, as a page load does on the web. The others refresh behind what's kept.
     */
    private function open(): void
    {
        $this->isRanked() ? $this->rank() : $this->revalidate();
        $this->openWhoToFollow();
    }

    /**
     * Ranks Trending afresh: the fresh ranking replaces what's showing, and
     * what was put above it goes, as reloading the page does on the web.
     */
    private function rank(): void
    {
        $log = $this->log;

        $this->refresh('log/'.$log, [], fn (array $json) => $this->takeRanking($log, $json), again: true);
        $this->refreshThreads();
        $this->refreshUnreadNotifications();
    }

    protected function showsContent(): bool
    {
        return $this->loaded;
    }

    /**
     * The end of the list, which the list says 5 rows before it gets there. The
     * next page is usually here already (see prefetchNextPage), and goes in at
     * once. One still on its way goes in when it lands, with the loading row
     * showing until then. Otherwise it's asked for now, and a failure prints
     * its error as any other.
     */
    public function loadMore(): void
    {
        if ($this->nextCursor === null) {
            return;
        }

        if (($held = $this->heldNextPage()) !== null) {
            $this->appendPage($held);

            return;
        }

        if ($this->fetchesNextPage()) {
            $this->waitingForNextPage = true;

            return;
        }

        $this->loadNextPageNow();
    }

    /**
     * The list's rows on screen as you scroll, by position: the rows near them
     * are drawn, and the rest go as placeholders. The list is still moving, so
     * the frame stays as it was unless the screen nears the end of the rows
     * drawn, or the end of the list nears with a next page here to put in, so
     * the phone has it before you reach the end rather than when you do. This
     * never asks devnull for one.
     */
    public function showRows(int $first, int $last): void
    {
        $window = $this->rowWindow($this->log);
        $window->show($first, $last);
        $this->listScrolling = true;

        $pageDue = $this->heldNextPage() !== null && $window->rowsBelowScreen() <= self::NextPageWithin;

        if (! $pageDue && ! $window->nearsEdge()) {
            $this->keepsFrame = true;

            return;
        }

        $this->catchUp();
    }

    /**
     * The list come to rest, with these rows on screen: what waited while it
     * moved goes in now, when a frame can't make it stutter.
     */
    public function scrollEnded(int $first, int $last): void
    {
        $this->rowWindow($this->log)->show($first, $last);
        $this->listScrolling = false;
        $this->catchUp();
    }

    /**
     * Draws the rows around the screen, and puts in a next page that's here if
     * the end of the list is near enough. A frame is going anyway, so as much
     * goes in it as can, and the next has further to wait.
     */
    private function catchUp(): void
    {
        $window = $this->rowWindow($this->log);
        $window->drawAroundScreen();

        if (($held = $this->heldNextPage()) !== null && $window->rowsBelowScreen() <= self::NextPageAtRestWithin) {
            $this->appendPage($held);
        }
    }

    /**
     * Asks devnull for the next page on the UI thread, which holds up
     * everything else until it answers. Only for when no page is here or on
     * its way.
     */
    private function loadNextPageNow(): void
    {
        $page = $this->attempt(fn ($devnull) => $devnull->log($this->log, $this->nextCursor));

        if ($page !== null) {
            $this->appendPage($page);
        }
    }

    /**
     * Puts a page under the list, then fetches the one after it. A ranked
     * commit can move to a later page while you scroll, so each one is only
     * listed once.
     *
     * @param  Page<Commit>  $page
     */
    private function appendPage(Page $page): void
    {
        $shown = array_flip(array_map(fn (Commit $commit): string => $commit->id, [...$this->commits, ...$this->latestCommits]));

        $this->commits = [...$this->commits, ...array_values(array_filter($page->items, fn (Commit $commit): bool => ! isset($shown[$commit->id])))];
        $this->nextCursor = $page->nextCursor;
        $this->heldPage = null;
        $this->waitingForNextPage = false;
        $this->prefetchNextPage();
    }

    /**
     * Fetches the log's next page in the background, to hold until the list
     * needs it (see holdNextPage). With a page for this cursor already held or
     * on its way it does nothing, so anything that may have moved the cursor
     * can call it. Only the log you're on fetches ahead: the pages beside it
     * show a few kept commits and nothing more.
     *
     * It's sent even when a fetch for the same cursor is still out, which
     * refresh() would otherwise skip: that one may be for a list replaced
     * since, whose answer is let go, and the end of the list would wait on
     * nothing.
     *
     * The page's images come down with it, so its rows are drawn from the
     * device from the start, and the page lands once rather than again when
     * its images do (see FetchInBackground). A held page isn't drawn, so the
     * frame it lands in matches the one before, which NativePHP doesn't send
     * to the phone (see render()).
     */
    private function prefetchNextPage(): void
    {
        $log = $this->log;
        $cursor = $this->nextCursor;
        $generation = $this->listGeneration;

        if ($cursor === null || $this->heldNextPage() !== null || $this->fetchesNextPage()) {
            return;
        }

        $this->fetchingNextPage = ['log' => $log, 'cursor' => $cursor, 'generation' => $generation, 'sentAt' => microtime(true)];

        $this->refresh(
            'log/'.$log,
            ['cursor' => $cursor],
            fn (array $json) => $this->holdNextPage($log, $cursor, $generation, $json),
            again: true,
            quiet: true,
            failed: fn () => $this->nextPageFailed($log, $cursor, $generation),
            withImages: true,
        );
    }

    /**
     * The next page landing. A page for a cursor or a list that's gone since
     * is let go. Otherwise it's held for the end of the list, or goes straight
     * in if the end of the list is waiting for it, or if the list has come to
     * rest near enough the end to be topped up.
     *
     * @param  array<string, mixed>  $json
     */
    private function holdNextPage(string $log, string $cursor, int $generation, array $json): void
    {
        if (! $this->isNextPage($log, $cursor, $generation)) {
            return;
        }

        $page = Page::fromArray($json, Commit::fromArray(...));

        $this->fetchingNextPage = null;
        $this->heldPage = ['log' => $log, 'cursor' => $cursor, 'generation' => $generation, 'page' => $page];

        if ($this->waitingForNextPage
            || ($this->listScrolling === false && $this->rowWindow($log)->rowsBelowScreen() <= self::NextPageAtRestWithin)) {
            $this->appendPage($page);
        }
    }

    /**
     * The next page didn't come. If the end of the list is waiting for it,
     * it's asked for on the UI thread instead, which prints the error if that
     * fails too. Otherwise it's forgotten, and the end of the list asks then.
     */
    private function nextPageFailed(string $log, string $cursor, int $generation): void
    {
        if (! $this->isNextPage($log, $cursor, $generation)) {
            return;
        }

        $this->fetchingNextPage = null;

        if ($this->waitingForNextPage) {
            $this->waitingForNextPage = false;
            $this->loadNextPageNow();
        }
    }

    /**
     * Whether a page asked for with this cursor still follows on from the list showing.
     */
    private function isNextPage(string $log, string $cursor, int $generation): bool
    {
        return $log === $this->log && $cursor === $this->nextCursor && $generation === $this->listGeneration;
    }

    /**
     * The next page, if it's here.
     *
     * @return Page<Commit>|null
     */
    private function heldNextPage(): ?Page
    {
        $held = $this->heldPage;

        return $held !== null && $this->isNextPage($held['log'], $held['cursor'], $held['generation']) ? $held['page'] : null;
    }

    /**
     * Whether the next page is on its way. A fetch out for longer than its 30
     * second timeout counts as lost, as refresh() counts it, so the end of the
     * list never waits on an answer that was dropped.
     */
    private function fetchesNextPage(): bool
    {
        $fetching = $this->fetchingNextPage;

        return $fetching !== null
            && $this->isNextPage($fetching['log'], $fetching['cursor'], $fetching['generation'])
            && microtime(true) - $fetching['sentAt'] < 30;
    }

    /**
     * The list the next cursor points into was replaced rather than added to:
     * a fresh ranking, another log, pull to refresh, or the pill putting new
     * commits in place of the list. Whatever was fetched for the old list's
     * next page is let go, and the end of the list stops waiting for it.
     */
    protected function replacedList(): void
    {
        $this->listGeneration++;
        $this->heldPage = null;
        $this->fetchingNextPage = null;
        $this->waitingForNextPage = false;
        $this->listScrolling = null;
    }

    /**
     * Takes commits out of the next page held for the log too, so they don't come back with it.
     *
     * @param  Closure(Commit): bool  $keep
     */
    private function keepInHeldPage(Closure $keep): void
    {
        if ($this->heldPage !== null) {
            $page = $this->heldPage['page'];
            $this->heldPage['page'] = new Page(array_values(array_filter($page->items, $keep)), $page->nextCursor);
        }
    }

    /**
     * Follows someone the empty Following log suggested, then loads the log, which now has them in it.
     */
    public function followSuggested(string $username): void
    {
        $followed = $this->attempt(function ($devnull) use ($username): bool {
            $devnull->follow($username);

            return true;
        });

        if ($followed === true) {
            $this->load();
        }
    }

    /**
     * Puts a commit you just made in the compose sheet at the top of the log,
     * staged until devnull has it (see SyncsOutbox).
     */
    protected function showCommitted(Commit $commit): void
    {
        $this->madeHere[] = $commit->id;
        $this->loaded = true;
        $this->keepStagedOnTop();

        // Trending has no top to put it on, so it goes above the ranking, as on the web.
        if ($this->isRanked()) {
            $this->listKey++;
        }
    }

    /**
     * devnull has the commit: the real one takes the staged one's place.
     */
    protected function showCommitWent(PendingWrite $write, Commit $commit): void
    {
        $staged = PendingWrite::stagedId($write->client_id);
        $swap = fn (Commit $shown): Commit => $shown->id === $staged ? $commit : $shown;

        $this->madeHere[] = $commit->id;
        $this->commits = array_map($swap, $this->commits);
        $this->latestCommits = array_map($swap, $this->latestCommits);
    }

    /**
     * devnull turned the commit down: the staged one leaves the log.
     */
    protected function showCommitTurnedDown(PendingWrite $write): void
    {
        $this->keepStagedOnTop();
    }

    /**
     * The compose button is the log's, so a new commit devnull turned down opens here again.
     */
    protected function opensComposer(): bool
    {
        return true;
    }

    /**
     * Your commits still on their way stay at the top of the log you're on,
     * or above Trending's ranking, newest first, through every fresh page, and
     * when you come back to the log. Ones that went or were turned down while
     * the log wasn't showing leave it, and a commit that went comes in with
     * the log's next answer.
     */
    private function keepStagedOnTop(): void
    {
        $staged = $this->stagedCommits(null);
        $settled = fn (Commit $commit): bool => ! $commit->isStaged();

        foreach ($staged as $commit) {
            $this->madeHere[] = $commit->id;
        }

        if ($this->isRanked()) {
            $this->latestCommits = [...$staged, ...array_values(array_filter($this->latestCommits, $settled))];
            $this->commits = array_values(array_filter($this->commits, $settled));

            return;
        }

        $this->commits = [...$staged, ...array_values(array_filter($this->commits, $settled))];
    }

    /**
     * The newest commit showing that devnull has, which it's asked what came after.
     */
    private function newestShownId(): ?string
    {
        foreach ($this->commits as $commit) {
            if (! $commit->isStaged()) {
                return $commit->id;
            }
        }

        return null;
    }

    /**
     * @return iterable<Commit>
     */
    protected function shownCommits(): iterable
    {
        return [...$this->latestCommits, ...$this->commits, ...$this->suggestions, ...$this->threadCommits()];
    }

    protected function updateShownCommits(Closure $update): void
    {
        $this->latestCommits = array_map($update, $this->latestCommits);
        $this->commits = array_map($update, $this->commits);
        $this->threads = array_map(fn (array $replies): array => array_map($update, $replies), $this->threads);
    }

    /**
     * The log as it was kept last time, if there is one. Coming back to it, it's
     * laid over what's showing like any fresh page, so a change made elsewhere
     * shows at once.
     */
    private function showKept(): void
    {
        $json = $this->cached('log/'.$this->log);

        if ($json === null) {
            return;
        }

        $this->isRanked()
            ? $this->takeRankedUpdate($this->log, $json, kept: true)
            : $this->takeFresh($this->log, $json);
    }

    /**
     * A fresh ranking for Trending, which replaces what's showing. A ranking
     * that was showing goes, in a fresh list at the top. Skeleton rows, or the
     * kept ranking a swipe brought in, stay in theirs, still at the top.
     *
     * Its next page is fetched ahead, unless $prefetch says it's a ranking
     * kept from last time, which only stands in until devnull ranks afresh.
     *
     * @param  array<string, mixed>  $json
     */
    private function takeRanking(string $log, array $json, bool $prefetch = true): void
    {
        if ($log !== $this->log) {
            return;
        }

        if ($this->loaded) {
            $this->listKey++;
        }

        $this->replacedList();

        $page = Page::fromArray($json, Commit::fromArray(...));

        $this->commits = $page->items;
        $this->nextCursor = $page->nextCursor;
        $this->latestCommits = [];
        $this->threads = [];
        $this->rankedSince = is_string($json['newest_commit_id'] ?? null) ? $json['newest_commit_id'] : null;
        $this->forgetNewCommits();
        $this->keepStagedOnTop();
        $this->loaded = true;

        if ($prefetch) {
            $this->prefetchNextPage();
        }
    }

    /**
     * What Trending learns between rankings: rows already showing change in
     * place, without moving, and the commits newer than the ranking wait for
     * the pill. With nothing showing yet, the answer is the ranking, and one
     * $kept from last time only stands in until devnull ranks afresh (see
     * open()), so its next page isn't fetched.
     *
     * @param  array<string, mixed>  $json
     */
    private function takeRankedUpdate(string $log, array $json, bool $kept = false): void
    {
        if ($log !== $this->log) {
            return;
        }

        // Nothing to keep in place yet, so this is the ranking.
        if (! $this->loaded || ($this->commits === [] && $this->latestCommits === [])) {
            $this->takeRanking($log, $json, prefetch: ! $kept);

            return;
        }

        $fresh = [];

        foreach (Page::fromArray($json, Commit::fromArray(...))->items as $commit) {
            $fresh[$commit->id] = $commit;
        }

        $this->updateShownCommits(fn (Commit $commit): Commit => $fresh[$commit->id] ?? $commit);

        $shown = array_flip(array_map(fn (Commit $commit): string => $commit->id, $this->latestCommits));
        $latest = array_values(array_filter(
            array_map(Commit::fromArray(...), array_filter(is_array($json['latest_commits'] ?? null) ? $json['latest_commits'] : [], is_array(...))),
            fn (Commit $commit): bool => ! isset($shown[$commit->id]),
        ));
        $count = is_int($json['new_commits_count'] ?? null) ? $json['new_commits_count'] : 0;

        $this->newCommits = $count > 0 ? $latest : [];
        $this->newCommitsCount = $count > 0 && $latest !== [] ? $count : null;
        $this->prefetchNextPage();
    }

    /**
     * The pill on Trending: the newer commits go above the ranking, and the newest of them is where counting starts again.
     */
    private function putLatestAboveRanking(): void
    {
        $newer = array_flip(array_map(fn (Commit $commit): string => $commit->id, $this->newCommits));

        $this->latestCommits = [...$this->newCommits, ...array_values(array_filter($this->latestCommits, fn (Commit $commit): bool => ! isset($newer[$commit->id])))];
        $this->rankedSince = self::newestOf($this->newCommits)?->id ?? $this->rankedSince;
        $this->forgetNewCommits();
    }

    /**
     * The newest of some commits, by when they were made, then by id, as the web picks it.
     *
     * @param  list<Commit>  $commits
     */
    private static function newestOf(array $commits): ?Commit
    {
        $newest = null;

        foreach ($commits as $commit) {
            if ($newest === null
                || ($commit->createdAt?->getTimestamp() ?? 0) > ($newest->createdAt?->getTimestamp() ?? 0)
                || ($commit->createdAt?->equalTo($newest->createdAt) && $commit->id > $newest->id)) {
                $newest = $commit;
            }
        }

        return $newest;
    }

    /**
     * Commits deleted while you were on their page leave the log. A ranked
     * log can't tell from a fresh page that one went, so it asks the cache.
     */
    private function forgetDeleted(): void
    {
        $deleted = array_flip(app(ResponseCache::class)->deletedIds());

        if ($deleted === []) {
            return;
        }

        $shown = fn (Commit $commit): bool => ! isset($deleted[$commit->id]);

        $this->commits = array_values(array_filter($this->commits, $shown));
        $this->latestCommits = array_values(array_filter($this->latestCommits, $shown));
        $this->keepInHeldPage($shown);
    }

    /**
     * Takes out the commits of anyone you muted or blocked since, as devnull
     * leaves them out.
     */
    private function forgetHidden(): void
    {
        $hidden = array_flip(app(ResponseCache::class)->takeHiddenAuthorIds());

        if ($hidden === []) {
            return;
        }

        $shown = fn (Commit $commit): bool => ! isset($hidden[$commit->author->id]);

        $this->commits = array_values(array_filter($this->commits, $shown));
        $this->latestCommits = array_values(array_filter($this->latestCommits, $shown));
        $this->keepInHeldPage($shown);
    }

    /**
     * Lays a first page of the log over what's showing. Rows already there change
     * in place; newer ones wait for the pill, unless the log had nothing to show
     * or you pulled to refresh. A pull to refresh starts the next page afresh
     * too, as everything else under it is.
     *
     * @param  array<string, mixed>  $json
     */
    private function takeFresh(string $log, array $json, bool $showNewer = false): void
    {
        // An answer for the other log, which you've left.
        if ($log !== $this->log) {
            return;
        }

        if ($showNewer) {
            $this->replacedList();
        }

        $this->layFreshCommits($json, showNewer: $showNewer || ! $this->loaded);
        $this->keepStagedOnTop();
        $this->suggestions = array_values(array_map(Commit::fromArray(...), array_filter(is_array($json['suggestions'] ?? null) ? $json['suggestions'] : [], is_array(...))));
        $this->loaded = true;
        $this->rememberLastVisit();
        $this->prefetchNextPage();
    }

    /**
     * The newest commit showing is the newest you've seen, as the web keeps it on
     * every load and after the pill. Commits waiting in the pill aren't seen yet.
     */
    private function rememberLastVisit(): void
    {
        if (! $this->isRanked() && ($viewer = $this->me()) !== null) {
            app(LastVisit::class)->remember($viewer->id, $this->log, $this->commits);
        }
    }

    private function lastVisitSeenAt(?string $log = null): ?string
    {
        $viewer = $this->me();

        return $viewer === null ? null : app(LastVisit::class)->seenAt($viewer->id, $log ?? $this->log);
    }

    /**
     * A background task landing draws its frame at the time the frame before
     * it was drawn at (see render()).
     *
     * @param  array<string, mixed>  $event
     */
    protected function dispatchNativeEvent(array $event): void
    {
        $name = is_string($event['event'] ?? null) ? $event['event'] : '';

        $this->taskLanded = in_array(str_starts_with($name, 'native:') ? substr($name, 7) : $name, [AsyncTaskFinished::class, AsyncTaskFailed::class], true);

        parent::dispatchNativeEvent($event);
    }

    /**
     * Draws the log at a time the whole frame shares. A frame drawn because a
     * background task landed keeps the time of the frame before it, so its
     * relative times, polls' time left and day markers read as they did.
     *
     * NativePHP draws a frame after every event, and only sends the phone a
     * frame that differs from the last one. A next page that lands is held
     * rather than drawn, so its frame would match the last one, except that a
     * row's "4m" could have turned "5m" in between. With a dozen recent rows
     * on screen that happened often, and every frame the phone gets costs its
     * main thread a pass over the whole list. A scroll, a tap or the poll
     * every 30 seconds draws at the time it happens, so times stay as fresh
     * as they were. While the list moves, a report of the rows on screen or
     * the poll that changes nothing else keeps the time too, and the times
     * catch up when it comes to rest.
     */
    public function render(): Element
    {
        $this->drawnAt = ($this->taskLanded || $this->keepsFrame) && $this->drawnAt !== null ? $this->drawnAt : CarbonImmutable::now();
        $this->taskLanded = $this->keepsFrame = false;

        return CarbonImmutable::withTestNow($this->drawnAt, fn (): Element => $this->fromView($this->frame()));
    }

    private function frame(): View
    {
        $viewer = $this->me();

        foreach (self::Logs as $log) {
            $this->rowCache($log)->startFrame();
        }

        $pages = array_map(fn (string $log): array => $log === $this->log
            ? $this->page($log, [
                'commits' => $this->commits,
                'latestCommits' => $this->latestCommits,
                'threads' => $this->threads,
                'suggestions' => $this->suggestions,
                'nextCursor' => $this->nextCursor,
                'loaded' => $this->loaded,
                'lastVisitAt' => $this->lastVisitAt,
                'error' => $this->error,
            ], $viewer, $this->listKey)
            : $this->page($log, $this->previewState($log), $viewer, $this->listKeys[$log] ?? 0), self::Logs);

        $showing = $pages[array_search($this->log, self::Logs, true)];

        return view('native.log', [
            'myColour' => $this->myColour(),
            'viewer' => $viewer,
            'pasteis' => app(Account::class)->pasteis(),
            'stashed' => app(Stash::class)->names($viewer?->id),
            'newCommitsLabel' => $this->newCommitsLabel(),
            'pages' => $pages,
            'pageIndex' => array_search($this->log, self::Logs, true),
            'tabs' => array_map(fn (string $log): string => self::Labels[$log], self::Logs),
            'rows' => $showing['rows'],
        ]);
    }

    /**
     * What a log's page shows, from the state it's in.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function page(string $log, array $state, ?User $viewer, int $listKey): array
    {
        /** @var list<Commit> $commits */
        $commits = $state['commits'];
        /** @var list<Commit> $latestCommits */
        $latestCommits = $state['latestCommits'];
        // Only the log you're on has who to follow: the others show their first few commits.
        $showsWhoToFollow = $log === $this->log && $this->showsWhoToFollow();
        $rows = $this->timeline($log, $latestCommits, $commits, $log === self::Ranked ? null : LastVisit::firstSeenId($commits, $state['lastVisitAt'], $viewer?->id), $showsWhoToFollow);

        return [
            'log' => $log,
            'showing' => $log === $this->log,
            'listKey' => $listKey,
            'rowCache' => $this->rowCache($log),
            // The other logs show their first few commits, as they'll open.
            'rowWindow' => $log === $this->log ? $this->rowWindow($log)->frame($rows, $listKey) : null,
            'loaded' => $state['loaded'],
            'error' => $state['error'],
            'threads' => $state['threads'],
            'suggestions' => $state['suggestions'],
            'nextCursor' => $state['nextCursor'],
            'isEmpty' => $commits === [] && $latestCommits === [],
            'lastCommit' => $commits[array_key_last($commits) ?? -1] ?? $latestCommits[array_key_last($latestCommits) ?? -1] ?? null,
            'rows' => $rows,
            'whoToFollow' => $showsWhoToFollow ? $this->whoToFollow() : null,
        ];
    }

    /**
     * A log's rows on the rail: a feed, or Trending's ranking under what was put above it.
     *
     * @param  list<Commit>  $latestCommits
     * @param  list<Commit>  $commits
     * @return list<TimelineRow>
     */
    private function timeline(string $log, array $latestCommits, array $commits, ?string $firstSeenId, bool $showsWhoToFollow = false): array
    {
        $whoToFollowAfter = $showsWhoToFollow ? self::WhoToFollowAfter : null;
        // Commits are readonly, so the same ones lay out the same way until the day changes.
        $key = implode(',', [Display::dayKey(CarbonImmutable::now()), $firstSeenId, $whoToFollowAfter, count($latestCommits), ...array_map(spl_object_id(...), [...$latestCommits, ...$commits])]);

        if (($this->timelines[$log][0] ?? null) !== $key) {
            $latest = array_flip(array_map(fn (Commit $commit): string => $commit->id, $latestCommits));

            $this->timelines[$log] = [$key, $log === self::Ranked
                ? Timeline::ranked(
                    $latestCommits,
                    // The web lists a commit put above the ranking only there.
                    array_values(array_filter($commits, fn (Commit $commit): bool => ! isset($latest[$commit->id]))),
                    $whoToFollowAfter,
                )
                : Timeline::feed($commits, firstSeenId: $firstSeenId, whoToFollowAfter: $whoToFollowAfter)];
        }

        return $this->timelines[$log][1];
    }

    private function rowCache(string $log): RowCache
    {
        return $this->rowCaches[$log] ??= new RowCache;
    }

    private function rowWindow(string $log): RowWindow
    {
        return $this->rowWindows[$log] ??= new RowWindow;
    }
}
