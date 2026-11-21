<?php

namespace App\NativeComponents;

use App\Devnull\Account;
use App\Devnull\Client;
use App\Devnull\Data\Commit;
use App\Devnull\Data\Guild as GuildData;
use App\Devnull\Data\GuildBoard;
use App\Devnull\Data\TimelineRow;
use App\Devnull\Exceptions\ApiException;
use App\Devnull\RowCache;
use App\Devnull\Stash;
use App\Devnull\Timeline;
use App\Icons\Lucide;
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
use Native\Mobile\Edge\Layouts\Builders\NavAction;
use Native\Mobile\Edge\Layouts\Builders\NavBarOptions;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Facades\Share;

/**
 * A guild's page, as the web shows it at "/guilds/{handle}": its icon or
 * emoji, its name, "#handle · Public guild · N members", what it's about and
 * how to post there, then its bulletin board, the commits posted in it or
 * tagged with its hashtag, newest first, with the one its moderator pinned on
 * top.
 *
 * It opens from the directory, or a commit's "posted in" pill, beneath the tab
 * it was tapped in (see OpensGuilds), on the board as it was kept last time,
 * then asks devnull again behind it, as an organization's page does. The
 * board is paged by number, and new commits push the older ones down a page,
 * so a page loaded later can repeat a commit already showing: those go once,
 * as does the pinned commit, which devnull also sends in its place.
 *
 * A guild devnull no longer has says so. When devnull has no guilds for you
 * at all, it says so as the directory does (see Guilds::refused).
 */
class Guild extends NativeComponent
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

    public ?GuildData $guild = null;

    /**
     * The commit pinned to the top of the board, from devnull's answer.
     */
    public ?Commit $pinned = null;

    /**
     * The id of the pinned commit. Where the board also carries it, it shows
     * on top rather than in its place.
     */
    public ?string $pinnedId = null;

    /**
     * devnull has no guild by this handle, though it has guilds.
     */
    public bool $missing = false;

    /**
     * Guilds are switched off for you: devnull answered the directory with a 404 too.
     */
    public bool $unavailable = false;

    /**
     * The board's rows from the last frame, so a render only builds the rows that changed.
     */
    private ?RowCache $rowCache = null;

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
        return $this->guild->name ?? '#'.$this->handle();
    }

    public function navigationOptions(): ?NavBarOptions
    {
        return NavBarOptions::make()->action(
            NavAction::make('share')
                ->icon(ios: Lucide::Share->ios(), android: Lucide::Share2->android())
                ->a11yLabel('Share guild')
                ->press('share'),
        );
    }

    /**
     * Pull to refresh. You're at the top, so new commits go straight in.
     */
    public function load(): void
    {
        $this->refreshBoard(showNewer: true);
    }

    /**
     * The next page of the board, less the commits already showing.
     */
    public function loadMore(): void
    {
        if ($this->nextCursor === null) {
            return;
        }

        $board = $this->attempt(fn (Client $devnull): ?GuildBoard => $devnull->guild($this->handle(), $this->nextCursor));

        if ($board === null) {
            return;
        }

        $shown = array_flip(array_map(fn (Commit $commit): string => $commit->id, $this->commits));
        $this->commits = [...$this->commits, ...array_values(array_filter($board->commits->items, fn (Commit $commit): bool => ! isset($shown[$commit->id])))];
        $this->nextCursor = $board->commits->nextCursor;
    }

    /**
     * The bar's Share: the system share sheet, with the guild's page on the web.
     */
    public function share(): void
    {
        Share::url('', '', $this->guild?->htmlUrl ?? rtrim((string) config('devnull.url'), '/').'/guilds/'.rawurlencode($this->handle()));
    }

    public function render(): View
    {
        $this->rowCache ??= new RowCache;
        $this->rowCache->startFrame();

        $pinned = $this->pinnedCommit();
        $boardCommits = array_values(array_filter($this->commits, fn (Commit $commit): bool => $commit->id !== $this->pinnedId));

        return view('native.guild', [
            'handle' => $this->handle(),
            'myColour' => $this->myColour(),
            'newCommitsLabel' => $this->newCommitsLabel(),
            'hasNewCommits' => $this->waitingCommits() !== [],
            'viewer' => $this->me(),
            'stashed' => app(Stash::class)->names($this->me()?->id),
            'pasteis' => app(Account::class)->pasteis(),
            'rowCache' => $this->rowCache,
            // The pinned commit has its label for a heading, so no day of its own.
            'pinnedRows' => $pinned === null ? [] : array_values(array_filter(Timeline::feed([$pinned]), fn (TimelineRow $row): bool => ! $row->isDayMarker())),
            'rows' => Timeline::feed($boardCommits),
            'isEmpty' => $pinned === null && $boardCommits === [],
            // Everything on the board was posted in this guild, so none of it says so.
            'hidesGuildBadge' => true,
        ]);
    }

    protected function revalidate(): void
    {
        $this->refreshBoard();
    }

    /**
     * The pinned commit already shows on top, so a new one that's pinned isn't new to you.
     *
     * @return list<Commit>
     */
    protected function waitingCommits(): array
    {
        return array_values(array_filter($this->newCommits, fn (Commit $commit): bool => $commit->id !== $this->pinnedId));
    }

    protected function showsContent(): bool
    {
        return $this->guild !== null;
    }

    /**
     * @return iterable<Commit>
     */
    protected function shownCommits(): iterable
    {
        return [...$this->commits, ...($this->pinned === null ? [] : [$this->pinned]), ...$this->threadCommits()];
    }

    protected function updateShownCommits(Closure $update): void
    {
        $this->commits = array_map($update, $this->commits);
        $this->pinned = $this->pinned === null ? null : $update($this->pinned);
        $this->threads = array_map(fn (array $replies): array => array_map($update, $replies), $this->threads);
    }

    /**
     * A 404 for the board is a guild devnull no longer has, unless it has no
     * guilds for you at all. Only the directory can tell, so it's asked, and
     * only its 404 says guilds are off. Until it answers, the page waits, and
     * if it can't answer, the page says it couldn't load.
     *
     * An opened thread devnull refuses, such as one under a commit by someone
     * you blocked, closes, and leaves the board alone.
     */
    protected function refused(ApiException $exception, string $path): bool
    {
        if ($path === 'guilds' && $exception->status === 404) {
            $this->unavailable = true;
            app(Account::class)->rememberGuildsAvailable(false);

            return true;
        }

        if ($path !== $this->boardPath()) {
            if (preg_match('#^commits/(?<id>[^/]+)/replies$#', $path, $matches) !== 1) {
                return false;
            }

            $this->closeThread($matches['id']);

            return true;
        }

        if ($exception->status !== 404) {
            return false;
        }

        $this->guild = null;
        $this->pinned = null;
        $this->pinnedId = null;
        $this->commits = [];
        $this->threads = [];
        $this->nextCursor = null;
        $this->forgetNewCommits();

        $this->refresh('guilds', [], function (): void {
            $this->missing = true;
            app(Account::class)->rememberGuildsAvailable(true);
        });

        return true;
    }

    private function refreshBoard(bool $showNewer = false): void
    {
        $this->refresh($this->boardPath(), [], fn (array $json) => $this->takeBoard($json, $showNewer), again: $showNewer);
        $this->refreshThreads();
    }

    private function showKept(): void
    {
        if (($json = $this->cached($this->boardPath())) !== null) {
            $this->takeBoard($json);
        }
    }

    /**
     * @param  array<string, mixed>  $json
     */
    private function takeBoard(array $json, bool $showNewer = false): void
    {
        $board = GuildBoard::fromArray($json);

        // An answer with no guild in it, like a maintenance page, leaves what's showing alone.
        if ($board === null) {
            return;
        }

        $this->layFreshCommits($json, $showNewer || $this->guild === null);
        $this->guild = $board->guild;
        $this->pinned = $board->pinned;
        $this->pinnedId = $board->pinned?->id;
        $this->missing = false;
        $this->unavailable = false;
    }

    /**
     * The pinned commit as the board last sent it, or as devnull sent it on its own when its page isn't loaded.
     */
    private function pinnedCommit(): ?Commit
    {
        foreach ($this->commits as $commit) {
            if ($commit->id === $this->pinnedId) {
                return $commit;
            }
        }

        return $this->pinned;
    }

    private function boardPath(): string
    {
        return 'guilds/'.rawurlencode($this->handle());
    }

    private function handle(): string
    {
        return (string) $this->param('handle');
    }
}
