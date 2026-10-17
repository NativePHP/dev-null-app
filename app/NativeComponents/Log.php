<?php

namespace App\NativeComponents;

use App\Devnull\Data\Commit;
use App\Devnull\Data\Page;
use App\Devnull\Data\User;
use App\Devnull\LastVisit;
use App\Devnull\ResponseCache;
use App\Devnull\ShownTabs;
use App\Devnull\Timeline;
use App\Icons\Android;
use App\Icons\Ios;
use App\NativeComponents\Concerns\ExpandsThreads;
use App\NativeComponents\Concerns\HoldsNewCommits;
use App\NativeComponents\Concerns\OpensInTab;
use App\NativeComponents\Concerns\RefreshesInBackground;
use App\NativeComponents\Concerns\ShowsCommits;
use App\NativeComponents\Concerns\TalksToDevnull;
use Closure;
use Illuminate\View\View;
use Native\Mobile\Attributes\Poll;
use Native\Mobile\Edge\Layouts\Builders\NavAction;
use Native\Mobile\Edge\Layouts\Builders\NavBarOptions;
use Native\Mobile\Edge\NativeComponent;

/**
 * The log: the web's three of them, Following (you and the people you follow),
 * Latest (everyone, newest first) and Trending (ranked by stars and replies),
 * under a composer you type into where it is, as on the web.
 *
 * EDGE tells PHP nothing about focus, and gives PHP no way to focus a field
 * or scroll a list. So a touch on the field is taken as being in it, a fresh
 * field (a new key) with autofocus is how focus is given, and a new key on the
 * list is how it gets back to the top.
 *
 * It opens on the log as it was last time, then asks devnull again in the
 * background, and every 30 seconds while it's showing, as the web polls.
 * Commits newer than anything showing wait under the composer in the new
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
 * The pages beside the one you're on are kept small on purpose. EDGE writes
 * each frame into a buffer of fixed size, and a frame that doesn't fit is
 * dropped without a word; the frames after it then refer to rows the phone
 * never got, which leaves holes in the list.
 */
class Log extends NativeComponent
{
    use ExpandsThreads;
    use HoldsNewCommits;
    use OpensInTab;
    use RefreshesInBackground;
    use ShowsCommits;
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
     * How many kept commits a log you're not on shows, enough to fill the screen while you swipe to it.
     */
    public const int PreviewCommits = 5;

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
     * The commit you're writing. It stays when the keyboard goes, until it's committed.
     */
    public string $body = '';

    /**
     * Whether the composer shows its budget and Commit button: from the moment you're
     * in it until you commit, or leave it empty and the log reloads.
     */
    public bool $composing = false;

    /**
     * Mounts a fresh field when it changes, so a committed field starts clean.
     */
    public int $composerKey = 0;

    /**
     * Asks the next fresh field to take the focus, until you touch it or type.
     */
    public bool $focusComposer = false;

    public function mount(): void
    {
        $this->log = app(ShownTabs::class)->get($this->me()?->id, 'log', self::Logs, 'following');
        $this->lastVisitAt = $this->lastVisitSeenAt();
        $this->showKept();
        $this->open();
    }

    /**
     * Back from a commit or a profile: take any change made there from the cache at once, then ask devnull.
     */
    public function onResume(): void
    {
        $this->forgetRefreshes();
        $this->closeEmptyComposer();
        $this->forgetDeleted();
        $this->showKept();
        $this->revalidate();
    }

    /**
     * The web's poll for new commits.
     */
    #[Poll(30000)]
    public function checkForNewCommits(): void
    {
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
                ->press('compose'),
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
        $this->lastVisitAt = $this->lastVisitSeenAt();
        $this->showKept();
        $this->open();
    }

    /**
     * A log you're not on: the first few commits kept for it, as it will
     * open, or skeleton rows with nothing kept.
     *
     * @return array<string, mixed>
     */
    private function previewState(string $log): array
    {
        $json = $this->cached('log/'.$log);
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
        $this->closeEmptyComposer();

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
    }

    /**
     * Asks devnull what changed since the newest commit showing. Trending
     * stays as it was ranked, and only learns what's newer than the ranking.
     */
    protected function revalidate(): void
    {
        $log = $this->log;
        $since = $this->isRanked() ? $this->rankedSince : ($this->commits[0]->id ?? null);

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

    public function loadMore(): void
    {
        if ($this->nextCursor === null) {
            return;
        }

        $page = $this->attempt(fn ($devnull) => $devnull->log($this->log, $this->nextCursor));

        if ($page !== null) {
            // A ranked commit can move to a later page while you scroll, so each one is only listed once.
            $shown = array_flip(array_map(fn (Commit $commit): string => $commit->id, [...$this->commits, ...$this->latestCommits]));

            $this->commits = [...$this->commits, ...array_values(array_filter($page->items, fn (Commit $commit): bool => ! isset($shown[$commit->id])))];
            $this->nextCursor = $page->nextCursor;
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
     * The nav bar's compose button: back to the top of the log, into the composer.
     */
    public function compose(): void
    {
        $this->composing = true;
        $this->focusComposer = true;
        $this->listKey++;
    }

    /**
     * A touch on the composer's field puts you in it.
     */
    public function composerFocused(): void
    {
        $this->composing = true;
        $this->focusComposer = false;
    }

    public function updatedBody(): void
    {
        $this->focusComposer = false;
    }

    public function remaining(): int
    {
        return Commit::MaxLength - mb_strlen($this->body);
    }

    public function canCommit(): bool
    {
        $length = mb_strlen(trim($this->body));

        return $length >= Commit::MinLength && $length <= Commit::MaxLength;
    }

    /**
     * Commits the draft and puts it at the top of the log. Pressing Commit has
     * already put the keyboard away. The draft stays if devnull says no.
     */
    public function commit(): void
    {
        if (! $this->canCommit()) {
            return;
        }

        $commit = $this->attempt(fn ($devnull) => $devnull->createCommit(trim($this->body)));

        if ($commit === null) {
            return;
        }

        $this->body = '';
        $this->composing = false;
        $this->focusComposer = false;
        $this->composerKey++;
        $this->showCommitted($commit);
    }

    /**
     * Puts a commit you just made at the top of the log, from the composer or a quote's sheet.
     */
    protected function showCommitted(Commit $commit): void
    {
        $this->madeHere[] = $commit->id;
        $this->loaded = true;

        // Trending has no top to put it on, so it goes above the ranking, as on the web.
        if ($this->isRanked()) {
            $this->latestCommits = [$commit, ...$this->latestCommits];
            $this->listKey++;

            return;
        }

        $this->commits = [$commit, ...$this->commits];
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
     * An empty composer you've left closes again. Nothing says when you leave it,
     * so this waits for the log to reload: on returning to it, or pulling to refresh.
     */
    private function closeEmptyComposer(): void
    {
        if (! $this->composing || $this->body !== '') {
            return;
        }

        $this->composing = false;
        $this->focusComposer = false;
        $this->composerKey++;
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
            ? $this->takeRankedUpdate($this->log, $json)
            : $this->takeFresh($this->log, $json);
    }

    /**
     * A fresh ranking for Trending, which replaces what's showing. A ranking
     * that was showing goes, in a fresh list at the top. Skeleton rows, or the
     * kept ranking a swipe brought in, stay in theirs, still at the top.
     *
     * @param  array<string, mixed>  $json
     */
    private function takeRanking(string $log, array $json): void
    {
        if ($log !== $this->log) {
            return;
        }

        if ($this->loaded) {
            $this->listKey++;
        }

        $page = Page::fromArray($json, Commit::fromArray(...));

        $this->commits = $page->items;
        $this->nextCursor = $page->nextCursor;
        $this->latestCommits = [];
        $this->threads = [];
        $this->rankedSince = is_string($json['newest_commit_id'] ?? null) ? $json['newest_commit_id'] : null;
        $this->forgetNewCommits();
        $this->loaded = true;
    }

    /**
     * What Trending learns between rankings: rows already showing change in
     * place, without moving, and the commits newer than the ranking wait for
     * the pill. With nothing showing yet, the answer is the ranking.
     *
     * @param  array<string, mixed>  $json
     */
    private function takeRankedUpdate(string $log, array $json): void
    {
        if ($log !== $this->log) {
            return;
        }

        // Nothing to keep in place yet, so this is the ranking.
        if (! $this->loaded || ($this->commits === [] && $this->latestCommits === [])) {
            $this->takeRanking($log, $json);

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

        $this->commits = array_values(array_filter($this->commits, fn (Commit $commit): bool => ! isset($deleted[$commit->id])));
        $this->latestCommits = array_values(array_filter($this->latestCommits, fn (Commit $commit): bool => ! isset($deleted[$commit->id])));
    }

    /**
     * Lays a first page of the log over what's showing. Rows already there change
     * in place; newer ones wait for the pill, unless the log had nothing to show
     * or you pulled to refresh.
     *
     * @param  array<string, mixed>  $json
     */
    private function takeFresh(string $log, array $json, bool $showNewer = false): void
    {
        // An answer for the other log, which you've left.
        if ($log !== $this->log) {
            return;
        }

        $this->layFreshCommits($json, showNewer: $showNewer || ! $this->loaded);
        $this->suggestions = array_values(array_map(Commit::fromArray(...), array_filter(is_array($json['suggestions'] ?? null) ? $json['suggestions'] : [], is_array(...))));
        $this->loaded = true;
        $this->rememberLastVisit();
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

    public function render(): View
    {
        $viewer = $this->me();

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
            'viewer' => $viewer,
            'composerOpen' => $this->composing || $this->body !== '',
            'remaining' => $this->remaining(),
            'canCommit' => $this->canCommit(),
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
        $latest = array_flip(array_map(fn (Commit $commit): string => $commit->id, $latestCommits));

        return [
            'log' => $log,
            'showing' => $log === $this->log,
            'listKey' => $listKey,
            'loaded' => $state['loaded'],
            'error' => $state['error'],
            'threads' => $state['threads'],
            'suggestions' => $state['suggestions'],
            'nextCursor' => $state['nextCursor'],
            'isEmpty' => $commits === [] && $latestCommits === [],
            'lastCommit' => $commits[array_key_last($commits) ?? -1] ?? $latestCommits[array_key_last($latestCommits) ?? -1] ?? null,
            'rows' => $log === self::Ranked
                ? Timeline::ranked(
                    $latestCommits,
                    // The web lists a commit put above the ranking only there.
                    array_values(array_filter($commits, fn (Commit $commit): bool => ! isset($latest[$commit->id]))),
                )
                : Timeline::feed(
                    $commits,
                    firstSeenId: LastVisit::firstSeenId($commits, $state['lastVisitAt'], $viewer?->id),
                ),
        ];
    }
}
