<?php

namespace App\NativeComponents;

use App\Devnull\Data\Commit;
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

    public string $username = '';

    public const array Tabs = ['commits', 'replies'];

    public ?ProfileData $profile = null;

    /**
     * The header only has what a commit row knew of them: no counts, no Follow,
     * no activity, until devnull answers.
     */
    public bool $sketched = false;

    public string $tab = 'commits';

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

    public function showTab(string $tab): void
    {
        if (! in_array($tab, self::Tabs, true) || $tab === $this->tab) {
            return;
        }

        $this->tab = $tab;

        if ($this->isMe()) {
            app(ShownTabs::class)->remember($this->me()?->id, 'profile', $tab);
        }

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
    private function commitsQuery(): array
    {
        return ['replies' => $this->tab === 'replies' ? '1' : null];
    }

    public function render(): View
    {
        $pinnedCommit = $this->pinnedCommit();

        return view('native.profile', [
            'myColour' => $this->myColour(),
            'isBlocked' => $this->unavailable === ErrorCode::UserBlocked->value,
            'isMe' => $this->isMe(),
            'newCommitsLabel' => $this->newCommitsLabel(),
            'viewer' => $this->me(),
            'pinnedCommit' => $pinnedCommit,
            'rows' => Timeline::feed($this->commits),
        ]);
    }
}
