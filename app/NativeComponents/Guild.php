<?php

namespace App\NativeComponents;

use App\Devnull\Account;
use App\Devnull\Client;
use App\Devnull\Data\Commit;
use App\Devnull\Data\Guild as GuildData;
use App\Devnull\Data\GuildBoard;
use App\Devnull\Data\TimelineRow;
use App\Devnull\Exceptions\ApiException;
use App\Devnull\Outbox;
use App\Devnull\RowCache;
use App\Devnull\Stash;
use App\Devnull\Timeline;
use App\Icons\Lucide;
use App\Models\PendingWrite;
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
 * emoji beside Commit and Join or Leave, its name, "#handle · Public guild ·
 * N members" (the count opens its members), what it's about and how to post
 * there, then its bulletin board, the commits posted in it or tagged with its
 * hashtag, newest first, with the one its moderator pinned on top.
 *
 * It opens from the directory, or a commit's "posted in" pill, beneath the tab
 * it was tapped in (see OpensGuilds), on the board as it was kept last time,
 * then asks devnull again behind it, as an organization's page does. The
 * board is paged by number, and new commits push the older ones down a page,
 * so a page loaded later can repeat a commit already showing: those go once,
 * as does the pinned commit, which devnull also sends in its place.
 *
 * The header's Commit posts to the board, as the web's guild page does,
 * from the compose sheet (see OpensCommitSheet::composeInGuildSheet()), while
 * the guild isn't paused. The commit shows on top of the board, staged, until
 * devnull has it, as a new commit does on the Log. If devnull turns it down,
 * it leaves the board and goes back into this page's sheet.
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
        $this->editFromData();
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

    /**
     * Share. Commit and Join are in the header, as Remote and Follow are on a profile.
     */
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
     * The header's Commit: the sheet, for a commit on this guild's board.
     */
    public function composeForGuild(): void
    {
        if ($this->takesPosts()) {
            $this->composeInGuildSheet($this->handle());
        }
    }

    /**
     * The header's Join or Leave. It changes at once, with the member count, and goes back if devnull refuses.
     */
    public function toggleJoined(): void
    {
        if ($this->guild === null) {
            return;
        }

        $before = $this->guild;
        $this->guild = $before->withJoined(! $before->isJoined);

        $done = $this->attempt(function (Client $devnull) use ($before): bool {
            $before->isJoined
                ? $devnull->leaveGuild($before->handle)
                : $devnull->joinGuild($before->handle);

            return true;
        });

        if ($done !== true) {
            $this->guild = $before;
        }
    }

    /**
     * The member count: who's in the guild, titled with its name.
     */
    public function openMembers(): void
    {
        if ($this->guild !== null) {
            $this->openInTab('/guilds/'.rawurlencode($this->guild->handle).'/members', ['guildName' => $this->guild->name]);
        }
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

    /**
     * Puts a commit you just posted to the board on top of it, staged until devnull has it (see SyncsOutbox).
     */
    protected function showCommitted(Commit $commit): void
    {
        $this->keepStagedOnTop();
    }

    /**
     * devnull has the commit: the real one takes the staged one's place.
     */
    protected function showCommitWent(PendingWrite $write, Commit $commit): void
    {
        $staged = PendingWrite::stagedId($write->client_id);

        if (! in_array($staged, array_map(fn (Commit $shown): string => $shown->id, $this->commits), true)) {
            return;
        }

        $this->madeHere[] = $commit->id;
        $this->commits = array_map(fn (Commit $shown): Commit => $shown->id === $staged ? $commit : $shown, $this->commits);
    }

    /**
     * devnull turned the commit down: the staged one leaves the board.
     */
    protected function showCommitTurnedDown(PendingWrite $write): void
    {
        $this->keepStagedOnTop();
    }

    /**
     * The header's Commit is this page's, so a post to this board devnull turned down opens here again.
     */
    protected function opensComposer(): bool
    {
        return true;
    }

    /**
     * This page's composer posts to its board, even once the guild is paused, so a post devnull turned down for that
     * still comes back here with why.
     */
    protected function composerGuild(): ?string
    {
        return $this->handle();
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
        $this->keepStagedOnTop();
        $this->guild = $board->guild;
        $this->pinned = $board->pinned;
        $this->pinnedId = $board->pinned?->id;
        $this->missing = false;
        $this->unavailable = false;
    }

    /**
     * Your posts to this board still on their way stay on top of it, newest
     * first, through every fresh page. Ones that went or were turned down
     * while the page wasn't showing leave it, and one that went comes in with
     * the board's next answer.
     */
    private function keepStagedOnTop(): void
    {
        $staged = $this->stagedCommits(null, $this->handle());

        foreach ($staged as $commit) {
            $this->madeHere[] = $commit->id;
        }

        $this->commits = [...$staged, ...array_values(array_filter($this->commits, fn (Commit $commit): bool => ! $commit->isStaged()))];
    }

    /**
     * Opened by Edit on the toast of a post to this board devnull turned down: its composer, with what it had.
     */
    private function editFromData(): void
    {
        $id = $this->data('edit');
        $write = is_numeric($id) ? app(Outbox::class)->find((int) $id) : null;

        if ($write !== null && $write->isCommit() && $write->isFailed()) {
            $this->editFailedCommit($write);
        }
    }

    /**
     * Whether the guild is loaded and takes posts: a paused one doesn't, as on the web.
     */
    private function takesPosts(): bool
    {
        return $this->guild !== null && ! $this->guild->isPaused;
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
