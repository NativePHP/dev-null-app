<?php

namespace App\NativeComponents;

use App\Devnull\Data\Commit;
use App\Devnull\Data\Page;
use App\Devnull\Data\Profile as ProfileData;
use App\Devnull\Data\User;
use App\Devnull\ErrorCode;
use App\Devnull\Exceptions\ApiException;
use App\Devnull\ShownTabs;
use App\Devnull\Timeline;
use App\Icons\Lucide;
use App\NativeComponents\Concerns\ExpandsThreads;
use App\NativeComponents\Concerns\HoldsNewCommits;
use App\NativeComponents\Concerns\OpensInTab;
use App\NativeComponents\Concerns\RefreshesInBackground;
use App\NativeComponents\Concerns\ShowsCommits;
use App\NativeComponents\Concerns\TalksToDevnull;
use Closure;
use Illuminate\View\View;
use Native\Mobile\Edge\Layouts\Builders\NavAction;
use Native\Mobile\Edge\Layouts\Builders\NavBarOptions;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Facades\Browser;

/**
 * A developer's profile: who they are on GitHub, their counts and activity,
 * their achievements and Unix rank, then their commits (with the pinned one
 * on top) or their replies.
 *
 * Across a block devnull shows neither of two people the other's profile, as
 * on the web: the one who blocked is told so, the other only that it isn't
 * available. devnull says which by its code (see ErrorCode).
 *
 * The Profile tab shows your own, with no username in the route. Like the web,
 * your own profile swaps Follow for "Edit on GitHub", and its nav bar leads to
 * your account.
 *
 * It opens on the profile as it was last time, or opened from a commit, on
 * what the commit knew of its author, then asks devnull again behind it. The
 * header changes in place; newer commits wait in the pill (see HoldsNewCommits).
 *
 * Commits and Replies are pages you swipe between, as on the Log (see Log),
 * under a header that scrolls up with the page you're on until the tabs pin
 * under the nav bar. Only the tab you're on is live; the other shows the first
 * few commits kept for it. Each has its own list, and the one you leave starts
 * again at the top of a fresh one.
 */
class Profile extends NativeComponent
{
    use ExpandsThreads;
    use HoldsNewCommits;
    use OpensInTab {
        isShowing as isShowingPath;
    }
    use RefreshesInBackground;
    use ShowsCommits;
    use TalksToDevnull;

    /**
     * Every frame goes in full, as on the Log (see Log::$forceFullFrames). A tab
     * tapped again goes back to the top in a list with a new key and the same
     * rows, which EDGE's subtree reuse would otherwise never send.
     */
    protected bool $forceFullFrames = true;

    public string $username = '';

    public const array Tabs = ['commits', 'replies'];

    /**
     * The web's tab labels.
     */
    public const array Labels = ['commits' => 'Commits', 'replies' => 'Replies'];

    /**
     * How many kept commits the tab you're not on shows, enough to fill the screen while you swipe to it.
     */
    public const int PreviewCommits = 5;

    public ?ProfileData $profile = null;

    /**
     * The header only has what a commit row knew of them: no counts, no Follow,
     * no activity, until devnull answers.
     */
    public bool $sketched = false;

    public string $tab = 'commits';

    /**
     * The list key of the tab you're not on. The tab you're on uses $listKey.
     *
     * @var array<string, int>
     */
    public array $listKeys = [];

    /**
     * Whether the tab has had commits to show, kept or fresh. Until then it shows skeleton rows.
     */
    public bool $commitsLoaded = false;

    /**
     * Why devnull won't show this profile, when it won't: user_blocked or user_unavailable.
     */
    public ?string $unavailable = null;

    public function mount(): void
    {
        if ($this->username === '') {
            $this->username = $this->me()?->username ?? '';
        }

        if ($this->isMe()) {
            $this->tab = app(ShownTabs::class)->get($this->me()?->id, 'profile', self::Tabs, 'commits');
        }

        $this->showKept();
        $this->revalidate();
    }

    /**
     * Back from a commit or another profile: take any change made there from the cache at once, then ask devnull.
     */
    public function onResume(): void
    {
        $this->forgetRefreshes();
        $this->showKept();
        $this->revalidate();
    }

    public function navTitle(): string
    {
        return '@'.$this->username;
    }

    public function navigationOptions(): ?NavBarOptions
    {
        if (! $this->isMe()) {
            return null;
        }

        return NavBarOptions::make()->action(
            NavAction::make('account')
                ->icon(ios: Lucide::Settings2->ios(), android: Lucide::Settings2->android())
                ->a11yLabel('Account')
                ->press('openAccount'),
        );
    }

    /**
     * Pull to refresh. You're at the top, so new commits go straight in.
     */
    public function load(): void
    {
        $this->refreshProfile(showNewer: true);
    }

    /**
     * The pager settling on a tab, after a swipe or a tab tap. The tab it's already on changes nothing.
     */
    public function showPage(int $index): void
    {
        $tab = self::Tabs[$index] ?? null;

        if ($tab !== null && $tab !== $this->tab) {
            $this->switchTo($tab);
        }
    }

    /**
     * A tap on the tab you're on, which the pager sends instead of moving: back to the top of it.
     */
    public function backToTop(int $index): void
    {
        if ((self::Tabs[$index] ?? null) === $this->tab) {
            $this->listKey++;
        }
    }

    /**
     * The tab you go to keeps the list its first few commits were drawn in,
     * and fills it. The tab you leave starts again at the top of a fresh one.
     */
    private function switchTo(string $tab): void
    {
        if ($this->isMe()) {
            app(ShownTabs::class)->remember($this->me()?->id, 'profile', $tab);
        }

        $this->listKeys[$this->tab] = $this->listKey + 1;
        $this->tab = $tab;
        $this->listKey = $this->listKeys[$tab] ?? 0;
        $this->commits = [];
        $this->threads = [];
        $this->nextCursor = null;
        $this->commitsLoaded = false;
        $this->forgetNewCommits();

        if (($kept = $this->cached($this->commitsPath(), $this->commitsQuery())) !== null) {
            $this->takeCommits($tab, $kept);
        }

        $this->refreshCommits();
    }

    public function loadMore(): void
    {
        if ($this->nextCursor === null) {
            return;
        }

        $page = $this->attempt(fn ($devnull) => $devnull->userCommits($this->username, $this->tab, $this->nextCursor));

        if ($page !== null) {
            $this->commits = [...$this->commits, ...$page->items];
            $this->nextCursor = $page->nextCursor;
        }
    }

    /**
     * Follows or unfollows straight away, and puts it back if the server says no.
     */
    public function toggleFollow(): void
    {
        if ($this->profile === null || $this->isMe()) {
            return;
        }

        $before = $this->profile;
        $this->profile = $before->withFollowing(! $before->isFollowing);

        $done = $this->attempt(function ($devnull) use ($before): bool {
            $before->isFollowing
                ? $devnull->unfollow($this->username)
                : $devnull->follow($this->username);

            return true;
        });

        if ($done !== true) {
            $this->profile = $before;
        }
    }

    public function isMe(): bool
    {
        return $this->username !== '' && $this->me()?->username === $this->username;
    }

    /**
     * The Profile tab shows your own profile at /profile, so your own profile
     * under the tab counts as this screen too.
     */
    protected function isShowing(string $uri): bool
    {
        return $this->isShowingPath($uri)
            || ($this->isShowingPath($this->tabRoot()) && '/'.trim($uri, '/') === $this->tabRoot().'/users/'.$this->username);
    }

    public function openAccount(): void
    {
        $this->navigate('/profile/device');
    }

    public function editOnGitHub(): void
    {
        Browser::inApp('https://github.com/settings/profile');
    }

    /**
     * The website on their GitHub profile. Taps name the link rather than carry
     * a URL, so the URL always comes from what devnull sent.
     */
    public function openWebsite(): void
    {
        $url = $this->profile?->user->websiteUrl();

        if ($url !== null && in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
            Browser::inApp($url);
        }
    }

    /**
     * The web's muted and blocked people, where a block is undone, since the app can't unblock yet.
     */
    public function openSafety(): void
    {
        Browser::inApp(rtrim((string) config('devnull.url'), '/').'/settings/safety');
    }

    public function openGitHubProfile(): void
    {
        if ($this->profile !== null) {
            Browser::inApp($this->profile->user->gitHubProfileUrl());
        }
    }

    /**
     * The web leaves the pinned commit out of the replies tab.
     */
    public function pinnedCommit(): ?Commit
    {
        return $this->tab === 'commits' ? $this->profile?->pinnedCommit : null;
    }

    /**
     * @return iterable<Commit>
     */
    protected function shownCommits(): iterable
    {
        return [...($this->pinnedCommit() === null ? [] : [$this->pinnedCommit()]), ...$this->commits, ...$this->threadCommits()];
    }

    protected function updateShownCommits(Closure $update): void
    {
        $this->commits = array_map($update, $this->commits);
        $this->threads = array_map(fn (array $replies): array => array_map($update, $replies), $this->threads);

        if ($this->profile?->pinnedCommit !== null) {
            $this->profile = $this->profile->withPinnedCommit($update($this->profile->pinnedCommit));
        }
    }

    /**
     * Asks again for the profile and the tab, and on your own the unread count,
     * which picks up name and avatar changes too.
     */
    protected function revalidate(): void
    {
        $this->refreshProfile();
    }

    protected function showsContent(): bool
    {
        return $this->profile !== null && $this->unavailable === null;
    }

    /**
     * A profile across a block has its own page, as on the web, whatever devnull's message says.
     */
    protected function refused(ApiException $exception): bool
    {
        if (! $exception->is(ErrorCode::UserBlocked) && ! $exception->is(ErrorCode::UserUnavailable)) {
            return false;
        }

        $this->unavailable = $exception->errorCode;
        $this->commits = [];
        $this->threads = [];
        $this->nextCursor = null;
        $this->forgetNewCommits();
        $this->error = null;

        return true;
    }

    private function refreshProfile(bool $showNewer = false): void
    {
        if ($this->username === '') {
            return;
        }

        $this->refresh('users/'.rawurlencode($this->username), [], function (array $json): void {
            $this->profile = ProfileData::fromArray($json);
            $this->sketched = false;
            $this->unavailable = null;
        }, again: $showNewer);
        $this->refreshCommits($showNewer);
        $this->refreshThreads();

        if ($this->isMe()) {
            $this->refreshUnreadNotifications();
        }
    }

    private function refreshCommits(bool $showNewer = false): void
    {
        $tab = $this->tab;

        $this->refresh($this->commitsPath(), $this->commitsQuery(), fn (array $json) => $this->takeCommits($tab, $json, $showNewer), again: $showNewer);
    }

    /**
     * The profile and the tab as they were kept last time. Opened from a commit
     * with nothing kept, the header starts from what the commit knew of its author.
     */
    private function showKept(): void
    {
        if ($this->username === '') {
            return;
        }

        $profile = $this->cached('users/'.rawurlencode($this->username));

        if ($profile !== null) {
            $this->profile = ProfileData::fromArray($profile);
            $this->sketched = false;
        } elseif ($this->profile === null && ($author = $this->data('user')) instanceof User && $author->username === $this->username) {
            $this->profile = new ProfileData($author);
            $this->sketched = true;
        }

        if (($commits = $this->cached($this->commitsPath(), $this->commitsQuery())) !== null) {
            $this->takeCommits($this->tab, $commits);
        }
    }

    /**
     * @param  array<string, mixed>  $json
     */
    private function takeCommits(string $tab, array $json, bool $showNewer = false): void
    {
        // An answer for the other tab, which you've left.
        if ($tab !== $this->tab) {
            return;
        }

        $this->layFreshCommits($json, $showNewer || ! $this->commitsLoaded);
        $this->commitsLoaded = true;
    }

    private function commitsPath(): string
    {
        return 'users/'.rawurlencode($this->username).'/commits';
    }

    /**
     * @return array<string, string|null>
     */
    private function commitsQuery(?string $tab = null): array
    {
        return ['replies' => ($tab ?? $this->tab) === 'replies' ? '1' : null];
    }

    public function render(): View
    {
        $pages = array_map(fn (string $tab): array => $tab === $this->tab
            ? $this->page($tab, $this->commits, $this->nextCursor, $this->commitsLoaded, $this->listKey)
            : $this->previewPage($tab), self::Tabs);

        return view('native.profile', [
            'isBlocked' => $this->unavailable === ErrorCode::UserBlocked->value,
            'isMe' => $this->isMe(),
            'newCommitsLabel' => $this->newCommitsLabel(),
            'viewer' => $this->me(),
            'pages' => $pages,
            'pageIndex' => array_search($this->tab, self::Tabs, true),
            'tabs' => array_values(self::Labels),
        ]);
    }

    /**
     * The tab you're not on: the first few commits kept for it, as it will
     * open, or skeleton rows with nothing kept.
     *
     * @return array<string, mixed>
     */
    private function previewPage(string $tab): array
    {
        $json = $this->cached($this->commitsPath(), $this->commitsQuery($tab));
        $kept = is_array($json['data'] ?? null) ? $json['data'] : [];
        $page = $json === null ? null : Page::fromArray(['data' => array_slice($kept, 0, self::PreviewCommits)] + $json, Commit::fromArray(...));

        // Rows past the preview are left out, with a skeleton row for them, as for the next page.
        $nextCursor = count($kept) > self::PreviewCommits ? 'preview' : $page?->nextCursor;

        return $this->page($tab, $page->items ?? [], $nextCursor, $page !== null, $this->listKeys[$tab] ?? 0);
    }

    /**
     * What a tab's page shows. The web leaves the pinned commit out of the replies tab.
     *
     * @param  list<Commit>  $commits
     * @return array<string, mixed>
     */
    private function page(string $tab, array $commits, ?string $nextCursor, bool $loaded, int $listKey): array
    {
        $showing = $tab === $this->tab;

        return [
            'tab' => $tab,
            'showing' => $showing,
            'listKey' => $listKey,
            'loaded' => $loaded,
            'error' => $showing ? $this->error : null,
            'pinnedCommit' => $tab === 'commits' ? $this->profile?->pinnedCommit : null,
            'isEmpty' => $commits === [],
            'nextCursor' => $nextCursor,
            'rows' => Timeline::feed($commits),
            // Replies the tab you're on has opened, under their commits.
            'threads' => $showing ? $this->threads : [],
        ];
    }
}
