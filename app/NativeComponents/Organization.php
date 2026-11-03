<?php

namespace App\NativeComponents;

use App\Devnull\Account;
use App\Devnull\Client;
use App\Devnull\Data\Commit;
use App\Devnull\Data\Mention;
use App\Devnull\Data\Organization as OrganizationData;
use App\Devnull\Data\Page;
use App\Devnull\RowCache;
use App\Devnull\SiteLink;
use App\Devnull\Stash;
use App\Devnull\Timeline;
use App\NativeComponents\Concerns\DrawsSkeletonFirst;
use App\NativeComponents\Concerns\ExpandsThreads;
use App\NativeComponents\Concerns\HoldsNewCommits;
use App\NativeComponents\Concerns\OpensInTab;
use App\NativeComponents\Concerns\OpensLiveStream;
use App\NativeComponents\Concerns\RefreshesInBackground;
use App\NativeComponents\Concerns\ShowsCommits;
use App\NativeComponents\Concerns\TalksToDevnull;
use Closure;
use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Facades\Browser;
use Native\Mobile\Facades\Share;

/**
 * A GitHub organization's page, as the web shows it at its "@handle": its
 * avatar as a rounded square in its lane, Follow and Share, what GitHub says
 * about it, how many follow it and its admins, then its Commits and the
 * commits that mention it, as pages you swipe between, as on a profile (see
 * Profile).
 *
 * It opens from a commit the organization posted, or a mention of it, beneath
 * the tab it was tapped in, on the page as it was kept last time, then asks
 * devnull again behind it. A tab devnull can't send says so on its own page,
 * and the header and the other tab carry on. When devnull can't send the
 * organization at all, the page on the web is a tap away.
 */
class Organization extends NativeComponent
{
    use DrawsSkeletonFirst;
    use ExpandsThreads;
    use HoldsNewCommits;
    use OpensInTab;
    use OpensLiveStream;
    use RefreshesInBackground;
    use ShowsCommits;
    use TalksToDevnull;

    /**
     * Every frame goes in full, as on a profile (see Profile::$forceFullFrames).
     */
    protected bool $forceFullFrames = true;

    public const array Tabs = ['commits', 'mentions'];

    /**
     * The web's tab labels.
     */
    public const array Labels = ['commits' => 'Commits', 'mentions' => 'Mentions'];

    /**
     * How many kept commits the tab you're not on shows, as Profile::PreviewCommits.
     */
    public const int PreviewCommits = 3;

    public ?OrganizationData $organization = null;

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
     * devnull didn't send the tab you're on, so it says so instead of its commits.
     */
    public bool $commitsFailed = false;

    /**
     * Each tab's rows from the last frame, so a render only builds the rows that changed, as on a profile.
     *
     * @var array<string, RowCache>
     */
    private array $rowCaches = [];

    public function mount(): void
    {
        $this->fillInAfterFirstFrame('revalidate');
    }

    /**
     * Back from a commit or a profile: take any change made there from the cache at once, then ask devnull.
     */
    public function onResume(): void
    {
        $this->forgetRefreshes();
        $this->showKept();
        $this->revalidate();
    }

    public function navTitle(): string
    {
        return $this->organization->organization->name ?? $this->handle();
    }

    /**
     * Pull to refresh. You're at the top, so new commits go straight in.
     */
    public function load(): void
    {
        $this->refreshOrganization(showNewer: true);
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

    public function loadMore(): void
    {
        if ($this->nextCursor === null) {
            return;
        }

        $page = $this->attempt(fn (Client $devnull) => $devnull->organizationCommits($this->handle(), $this->tab, $this->nextCursor));

        if ($page !== null) {
            $this->commits = [...$this->commits, ...$page->items];
            $this->nextCursor = $page->nextCursor;
        }
    }

    /**
     * Follows or unfollows straight away, and puts it back if devnull says no.
     */
    public function toggleFollow(): void
    {
        if ($this->organization === null) {
            return;
        }

        $before = $this->organization;
        $this->organization = $before->withFollowing(! $before->isFollowing);

        $done = $this->attempt(function (Client $devnull) use ($before): bool {
            $before->isFollowing
                ? $devnull->unfollowOrganization($this->handle())
                : $devnull->followOrganization($this->handle());

            return true;
        });

        if ($done !== true) {
            $this->organization = $before;
        }
    }

    /**
     * The web's Share beside Follow: the system share sheet, with the organization's page on the web.
     */
    public function share(): void
    {
        Share::url('', '', $this->organization?->organization->htmlUrl ?? $this->webUrl());
    }

    public function openWebsite(): void
    {
        $this->openUrl($this->organization?->organization->websiteUrl());
    }

    public function openOnWeb(): void
    {
        Browser::inApp($this->webUrl());
    }

    public function openGitHub(): void
    {
        $url = $this->organization?->organization->gitHubProfileUrl() ?? 'https://github.com/'.rawurlencode($this->handle());

        if (SiteLink::isWebUrl($url)) {
            Browser::inApp($url);
        }
    }

    /**
     * A GitHub handle mentioned in its bio, as on a profile: only one the bio does mention opens.
     */
    public function openGitHubMention(string $username): void
    {
        foreach (Mention::inText($this->organization->organization->bio ?? '') as $mention) {
            if ($mention->username === $username) {
                Browser::inApp('https://github.com/'.rawurlencode($username));

                return;
            }
        }
    }

    /**
     * An admin, from the row under the counts, as the web links them.
     */
    public function openAdmin(string $username): void
    {
        foreach ($this->organization->admins ?? [] as $admin) {
            if ($admin->username === $username) {
                $this->openInTab('/users/'.rawurlencode($username), ['user' => $admin]);

                return;
            }
        }
    }

    public function render(): View
    {
        foreach (self::Tabs as $tab) {
            $this->rowCache($tab)->startFrame();
        }

        $pages = array_map(fn (string $tab): array => $tab === $this->tab
            ? $this->page($tab, $this->commits, $this->nextCursor, $this->commitsLoaded, $this->listKey)
            : $this->previewPage($tab), self::Tabs);

        return view('native.organization', [
            'handle' => $this->handle(),
            'myColour' => $this->myColour(),
            'newCommitsLabel' => $this->newCommitsLabel(),
            'viewer' => $this->me(),
            'stashed' => app(Stash::class)->names($this->me()?->id),
            'pages' => $pages,
            'pageIndex' => array_search($this->tab, self::Tabs, true),
            'tabs' => array_values(self::Labels),
            'pasteis' => app(Account::class)->pasteis(),
        ]);
    }

    protected function revalidate(): void
    {
        $this->refreshOrganization();
    }

    protected function showsContent(): bool
    {
        return $this->organization !== null;
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

    /**
     * The tab you go to keeps the list its first few commits were drawn in, and fills it. The tab you leave starts
     * again at the top of a fresh one.
     */
    private function switchTo(string $tab): void
    {
        $this->listKeys[$this->tab] = $this->listKey + 1;
        $this->tab = $tab;
        $this->listKey = $this->listKeys[$tab] ?? 0;
        $this->commits = [];
        $this->threads = [];
        $this->nextCursor = null;
        $this->commitsLoaded = false;
        $this->commitsFailed = false;
        $this->forgetNewCommits();

        if (($kept = $this->cached($this->commitsPath())) !== null) {
            $this->takeCommits($tab, $kept);
        }

        $this->refreshCommits();
    }

    private function refreshOrganization(bool $showNewer = false): void
    {
        $this->refresh($this->organizationPath(), [], $this->takeOrganization(...), again: $showNewer);
        $this->refreshCommits($showNewer);
        $this->refreshThreads();
    }

    /**
     * Quiet, so a tab devnull can't send says so on its own page and leaves the header alone.
     */
    private function refreshCommits(bool $showNewer = false): void
    {
        $tab = $this->tab;

        $this->refresh(
            $this->commitsPath(),
            [],
            fn (array $json) => $this->takeCommits($tab, $json, $showNewer),
            again: $showNewer,
            quiet: true,
            failed: function () use ($tab): void {
                if ($tab === $this->tab && ! $this->commitsLoaded) {
                    $this->commitsFailed = true;
                }
            },
        );
    }

    private function showKept(): void
    {
        if (($json = $this->cached($this->organizationPath())) !== null) {
            $this->takeOrganization($json);
        }

        if (($commits = $this->cached($this->commitsPath())) !== null) {
            $this->takeCommits($this->tab, $commits);
        }
    }

    /**
     * @param  array<string, mixed>  $json
     */
    private function takeOrganization(array $json): void
    {
        $organization = OrganizationData::fromArray($json);

        // An answer with no organization in it, like a maintenance page, leaves what's showing alone.
        if ($organization->organization->username !== '') {
            $this->organization = $organization;
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
        $this->commitsFailed = false;
    }

    /**
     * The tab you're not on: the first few commits kept for it, as it will open, or skeleton rows with nothing kept.
     *
     * @return array<string, mixed>
     */
    private function previewPage(string $tab): array
    {
        $json = $this->skeletonFrame ? null : $this->cached($this->commitsPath($tab));
        $kept = is_array($json['data'] ?? null) ? $json['data'] : [];
        $page = $json === null ? null : Page::fromArray(['data' => array_slice($kept, 0, self::PreviewCommits)] + $json, Commit::fromArray(...));

        // Rows past the preview are left out, with a skeleton row for them, as for the next page.
        $nextCursor = count($kept) > self::PreviewCommits ? 'preview' : $page?->nextCursor;

        return $this->page($tab, $page->items ?? [], $nextCursor, $page !== null, $this->listKeys[$tab] ?? 0);
    }

    /**
     * What a tab's page shows.
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
            'failed' => $showing && $this->commitsFailed,
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

    private function commitsPath(?string $tab = null): string
    {
        return Client::organizationCommitsPath($this->handle(), $tab ?? $this->tab);
    }

    private function organizationPath(): string
    {
        return 'organizations/'.rawurlencode($this->handle());
    }

    private function webUrl(): string
    {
        return rtrim((string) config('devnull.url'), '/').'/@'.rawurlencode($this->handle());
    }

    private function handle(): string
    {
        return (string) $this->param('handle');
    }
}
