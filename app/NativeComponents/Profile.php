<?php

namespace App\NativeComponents;

use App\Devnull\Account;
use App\Devnull\Data\Commit;
use App\Devnull\Data\Mention;
use App\Devnull\Data\Page;
use App\Devnull\Data\Profile as ProfileData;
use App\Devnull\Data\User;
use App\Devnull\ErrorCode;
use App\Devnull\Exceptions\ApiException;
use App\Devnull\RowCache;
use App\Devnull\ShownTabs;
use App\Devnull\Stash;
use App\Devnull\Timeline;
use App\Icons\Lucide;
use App\NativeComponents\Concerns\DrawsSkeletonFirst;
use App\NativeComponents\Concerns\ExpandsThreads;
use App\NativeComponents\Concerns\HoldsNewCommits;
use App\NativeComponents\Concerns\OpensAchievements;
use App\NativeComponents\Concerns\OpensInTab;
use App\NativeComponents\Concerns\RefreshesInBackground;
use App\NativeComponents\Concerns\ShowsCommits;
use App\NativeComponents\Concerns\TalksToDevnull;
use Closure;
use Illuminate\View\View;
use Native\Mobile\Attributes\On;
use Native\Mobile\Edge\Layouts\Builders\NavAction;
use Native\Mobile\Edge\Layouts\Builders\NavBarOptions;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Events\Alert\ButtonPressed;
use Native\Mobile\Facades\Browser;
use Native\Mobile\Facades\Dialog;

/**
 * A developer's profile: who they are on GitHub, their counts and activity,
 * their achievements and Unix rank, then their commits (with the pinned one
 * on top) or their replies.
 *
 * Across a block devnull shows neither of two people the other's profile, as
 * on the web: the one who blocked is told so, the other only that it isn't
 * available. devnull says which by its code (see ErrorCode).
 *
 * Someone else's profile has the web's safety menu in its nav bar: Mute or
 * Unmute, and Block, or on a profile you blocked only Unblock. Each asks
 * first, then changes straight away, and goes back if devnull says no.
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
    use DrawsSkeletonFirst;
    use ExpandsThreads;
    use HoldsNewCommits;
    use OpensAchievements;
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
     * How many kept commits the tab you're not on shows, enough for the top of the screen while you swipe to it, as
     * Log::PreviewCommits: every row sent is one the phone builds.
     */
    public const int PreviewCommits = 3;

    /**
     * The alerts the safety menu confirms with, as the web words them. Each one's id names the profile too, so an
     * answer only reaches the profile that asked.
     */
    public const string MuteAlert = 'mute';

    public const string UnmuteAlert = 'unmute';

    public const string BlockAlert = 'block';

    public const string UnblockAlert = 'unblock';

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
     * Each tab's rows from the last frame, so a render only builds the rows that changed, as on the Log.
     *
     * @var array<string, RowCache>
     */
    private array $rowCaches = [];

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

        $this->fillInAfterFirstFrame('revalidate');
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
        if ($this->isMe()) {
            return NavBarOptions::make()
                ->action(
                    NavAction::make('starred')
                        ->icon(ios: Lucide::Star->ios(), android: Lucide::Star->android())
                        ->a11yLabel('Starred')
                        ->press('openStarred'),
                )
                ->action(
                    NavAction::make('account')
                        ->icon(ios: Lucide::Settings2->ios(), android: Lucide::Settings2->android())
                        ->a11yLabel('Account')
                        ->press('openAccount'),
                );
        }

        $items = $this->safetyActions();

        return $items === [] ? null : NavBarOptions::make()->action(
            NavAction::make('safety')
                ->icon(ios: Lucide::Ellipsis->ios(), android: Lucide::Ellipsis->android())
                ->a11yLabel('More options for @'.$this->username)
                ->items($items),
        );
    }

    /**
     * The web's safety menu: Mute or Unmute, then Block, in red. A profile you
     * blocked offers only Unblock, as the web's blocked page does, and one that
     * blocked you offers nothing. It waits for devnull to say whether you mute
     * them.
     *
     * @return list<NavAction>
     */
    private function safetyActions(): array
    {
        $handle = '@'.$this->username;

        if ($this->isBlocking()) {
            return [NavAction::make('unblock')->label("Unblock {$handle}")->icon(ios: Lucide::Ban->ios(), android: Lucide::Ban->android())->press('confirmBlock')];
        }

        if (! $this->canChangeSafety()) {
            return [];
        }

        $muting = $this->profile?->isMuting === true;
        $muteIcon = $muting ? Lucide::Volume2 : Lucide::VolumeX;

        return [
            NavAction::make($muting ? 'unmute' : 'mute')->label(($muting ? 'Unmute ' : 'Mute ').$handle)->icon(ios: $muteIcon->ios(), android: $muteIcon->android())->press('confirmMute'),
            NavAction::make('block')->label("Block {$handle}")->icon(ios: Lucide::Ban->ios(), android: Lucide::Ban->android())->destructive()->press('confirmBlock'),
        ];
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

    /**
     * Their followers, or the people they follow, from the counts under the bio.
     */
    public function openConnections(string $type): void
    {
        if (in_array($type, Connections::Types, true) && $this->showsContent() && ! $this->sketched) {
            $this->openInTab('/users/'.rawurlencode($this->username).'/'.$type);
        }
    }

    /**
     * Mute or Unmute, from the menu. Mute says what it does in the web's words.
     */
    public function confirmMute(): void
    {
        if (! $this->canChangeSafety()) {
            return;
        }

        $handle = '@'.$this->username;

        $this->profile?->isMuting === true
            ? Dialog::alert("Unmute {$handle}?", '', [
                ['label' => 'Cancel', 'style' => 'cancel'],
                ['label' => 'Unmute'],
            ])->id($this->alertId(self::UnmuteAlert))
            : Dialog::alert("Mute {$handle}?", "You won't see their commits or hear from them, and they won't know.", [
                ['label' => 'Cancel', 'style' => 'cancel'],
                ['label' => 'Mute'],
            ])->id($this->alertId(self::MuteAlert));
    }

    /**
     * Block or Unblock, from the menu, in the web's words: its Block dialog,
     * and for Unblock what the web's Safety settings say of it.
     */
    public function confirmBlock(): void
    {
        $handle = '@'.$this->username;

        if ($this->isBlocking()) {
            Dialog::alert("Unblock {$handle}?", "Unblocking doesn't bring back follows.", [
                ['label' => 'Cancel', 'style' => 'cancel'],
                ['label' => 'Unblock'],
            ])->id($this->alertId(self::UnblockAlert));

            return;
        }

        if (! $this->canChangeSafety()) {
            return;
        }

        Dialog::alert("Block {$handle}?", "You'll stop following each other. Neither of you will be able to follow, reply to, or star the other, or see each other's commits. They won't be told, but they'll see your profile as unavailable. You can unblock them any time from your settings.", [
            ['label' => 'Cancel', 'style' => 'cancel'],
            ['label' => 'Block', 'style' => 'destructive'],
        ])->id($this->alertId(self::BlockAlert));
    }

    /**
     * NativePHP gives each event one listener per screen, so this one answers
     * the delete alert of a commit on the profile too (see ShowsCommits).
     */
    #[On(ButtonPressed::class)]
    public function onAlertButton(string $label = '', ?string $id = null): void
    {
        $this->answerDeleteAlert($label, $id);

        if ($id === $this->alertId(self::MuteAlert) && $label === 'Mute') {
            $this->mute();
        }

        if ($id === $this->alertId(self::UnmuteAlert) && $label === 'Unmute') {
            $this->unmute();
        }

        if ($id === $this->alertId(self::BlockAlert) && $label === 'Block') {
            $this->block();
        }

        if ($id === $this->alertId(self::UnblockAlert) && $label === 'Unblock') {
            $this->unblock();
        }
    }

    /**
     * Mutes straight away, so the profile says Muted, and puts it back if devnull says no.
     */
    public function mute(): void
    {
        $this->changeMute(true);
    }

    public function unmute(): void
    {
        $this->changeMute(false);
    }

    /**
     * Blocks straight away: the profile becomes the blocked page. If devnull
     * says no, the profile comes back as it was.
     */
    public function block(): void
    {
        if (! $this->canChangeSafety()) {
            return;
        }

        $before = [$this->commits, $this->threads, $this->nextCursor];
        $this->showBlocked();

        $done = $this->attempt(function ($devnull): bool {
            $devnull->block($this->username);

            return true;
        });

        if ($done !== true) {
            [$this->commits, $this->threads, $this->nextCursor] = $before;
            $this->unavailable = null;
        }
    }

    /**
     * Unblocks straight away, from the menu or the blocked page's own button,
     * which the web doesn't confirm. The header shows what it knows until
     * devnull sends the profile again, without the follows the block ended.
     */
    public function unblock(): void
    {
        if (! $this->isBlocking()) {
            return;
        }

        $before = [$this->sketched, $this->commitsLoaded];
        $this->unavailable = null;
        $this->sketched = true;
        $this->commitsLoaded = false;

        $done = $this->attempt(function ($devnull): bool {
            $devnull->unblock($this->username);

            return true;
        });

        if ($done !== true) {
            [$this->sketched, $this->commitsLoaded] = $before;
            $this->unavailable = ErrorCode::UserBlocked->value;

            return;
        }

        $this->refreshProfile(showNewer: true);
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

    /**
     * The commits you starred, which the web's phone keeps in its account menu.
     */
    public function openStarred(): void
    {
        $this->navigate('/profile/starred');
    }

    /**
     * Your Remotes thread with them, empty until one of you pushes, as the web's Remote button opens it.
     */
    public function openRemote(): void
    {
        if ($this->canRemote()) {
            $this->openInTab('/conversations/'.rawurlencode($this->username), $this->profile === null ? [] : ['user' => $this->profile->user]);
        }
    }

    /**
     * Whether the web would offer Remote here: you have Remotes on, it isn't you or someone across a block, and they
     * follow you, unless devnull takes requests from anyone. Whether they have Remotes on only shows in the thread.
     */
    private function canRemote(): bool
    {
        $remotes = app(Account::class)->remotes();

        return $remotes !== null && $this->canChangeSafety() && ($this->profile?->followsYou === true || $remotes->requests);
    }

    public function editOnGitHub(): void
    {
        Browser::inApp('https://github.com/settings/profile');
    }

    /**
     * The website on their GitHub profile: a page on dev/null the app has a
     * screen for here in the app, anything else in the browser. Taps name the
     * link rather than carry a URL, so the URL always comes from what devnull
     * sent.
     */
    public function openWebsite(): void
    {
        $this->openUrl($this->profile?->user->websiteUrl());
    }

    /**
     * The people you mute and block, beneath this tab.
     */
    public function openSafety(): void
    {
        $this->openInTab('/safety');
    }

    public function openGitHubMention(string $username): void
    {
        $user = $this->profile?->user;

        foreach (Mention::inText(($user?->bio ?? '')."\n".($user?->company ?? '')) as $mention) {
            if ($mention->username === $username) {
                Browser::inApp('https://github.com/'.rawurlencode($username));

                return;
            }
        }
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
     * On your profile, the commit you pinned leaves your commits for the top
     * at once, and the one you unpinned leaves the top. Then devnull answers
     * with your commits in order, as the web reloads them after a pin.
     */
    protected function showPinned(Commit $commit): void
    {
        if ($this->profile === null || ! $this->isMe()) {
            return;
        }

        if ($commit->isPinned) {
            $this->profile = $this->profile->withPinnedCommit($commit);
            $this->commits = array_values(array_filter($this->commits, fn (Commit $shown): bool => $shown->id !== $commit->id));
        } elseif ($this->profile->pinnedCommit?->id === $commit->id) {
            $this->profile = $this->profile->withPinnedCommit(null);
        }

        $this->refreshProfile(showNewer: true);
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
     * A profile across a block has its own page, as on the web, whatever devnull's message says. devnull sends who
     * you blocked with it, for the page's name and avatar.
     */
    protected function refused(ApiException $exception): bool
    {
        if ($exception->is(ErrorCode::UserBlocked)) {
            if ($exception->user !== null && $exception->user->username === $this->username) {
                $this->profile = new ProfileData($exception->user);
                $this->sketched = true;
            }

            $this->showBlocked();
            $this->error = null;

            return true;
        }

        if (! $exception->is(ErrorCode::UserUnavailable)) {
            return false;
        }

        $this->unavailable = $exception->errorCode;
        $this->forgetShownCommits();
        $this->error = null;

        return true;
    }

    private function showBlocked(): void
    {
        $this->unavailable = ErrorCode::UserBlocked->value;
        $this->forgetShownCommits();
    }

    private function forgetShownCommits(): void
    {
        $this->commits = [];
        $this->threads = [];
        $this->nextCursor = null;
        $this->forgetNewCommits();
    }

    private function isBlocking(): bool
    {
        return ! $this->isMe() && $this->unavailable === ErrorCode::UserBlocked->value;
    }

    /**
     * Whether you can mute or block them: someone else, whose profile devnull has sent, across no block.
     */
    private function canChangeSafety(): bool
    {
        return $this->username !== '' && ! $this->isMe() && $this->profile !== null && ! $this->sketched && $this->unavailable === null;
    }

    private function changeMute(bool $muting): void
    {
        if (! $this->canChangeSafety() || $this->profile?->isMuting === $muting) {
            return;
        }

        $before = $this->profile;
        $this->profile = $before?->withMuting($muting);

        $done = $this->attempt(function ($devnull) use ($muting): bool {
            $muting ? $devnull->mute($this->username) : $devnull->unmute($this->username);

            return true;
        });

        if ($done !== true) {
            $this->profile = $before;
        }
    }

    private function alertId(string $alert): string
    {
        return "{$alert}:{$this->username}";
    }

    private function refreshProfile(bool $showNewer = false): void
    {
        if ($this->username === '') {
            return;
        }

        $this->refresh('users/'.rawurlencode($this->username), [], function (array $json): void {
            $profile = ProfileData::fromArray($json);

            // An answer with nobody in it, like a maintenance page, leaves what's showing alone.
            if ($profile->user->username === '') {
                return;
            }

            $this->profile = $profile;
            $this->sketched = false;
            $this->unavailable = null;

            if ($this->isMe()) {
                app(Account::class)->rememberProfile($json);
            }
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

        $profile = $this->keptProfile();

        if ($profile !== null) {
            $this->profile = $profile;
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
     * The profile as it was kept. Your own falls back to the one the phone keeps
     * from when you signed in (see Account::profile()), so it still shows when
     * devnull can't be reached and nothing usable was kept. A kept answer with
     * nobody in it is no profile at all.
     */
    private function keptProfile(): ?ProfileData
    {
        $json = $this->cached('users/'.rawurlencode($this->username));
        $profile = $json === null ? null : ProfileData::fromArray($json);

        if (($profile === null || $profile->user->username === '') && $this->isMe() && ($stored = app(Account::class)->profile()) !== null) {
            return ProfileData::fromArray($stored);
        }

        return $profile?->user->username === '' ? null : $profile;
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
        foreach (self::Tabs as $tab) {
            $this->rowCache($tab)->startFrame();
        }

        $pages = array_map(fn (string $tab): array => $tab === $this->tab
            ? $this->page($tab, $this->commits, $this->nextCursor, $this->commitsLoaded, $this->listKey)
            : $this->previewPage($tab), self::Tabs);

        return view('native.profile', [
            'myColour' => $this->myColour(),
            'isBlocked' => $this->isBlocking(),
            'isMe' => $this->isMe(),
            'canRemote' => $this->canRemote(),
            'newCommitsLabel' => $this->newCommitsLabel(),
            'viewer' => $this->me(),
            'stashed' => app(Stash::class)->names($this->me()?->id),
            'pages' => $pages,
            'pageIndex' => array_search($this->tab, self::Tabs, true),
            'tabs' => array_values(self::Labels),
            'pasteis' => app(Account::class)->pasteis(),
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
        $json = $this->skeletonFrame ? null : $this->cached($this->commitsPath(), $this->commitsQuery($tab));
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
            'rowCache' => $this->rowCache($tab),
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

    private function rowCache(string $tab): RowCache
    {
        return $this->rowCaches[$tab] ??= new RowCache;
    }
}
