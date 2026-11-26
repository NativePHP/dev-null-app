<?php

namespace App\NativeComponents;

use App\Devnull\Account;
use App\Devnull\Beat;
use App\Devnull\Client;
use App\Devnull\Data\Commit;
use App\Devnull\Data\CommitThread;
use App\Devnull\Data\Draft;
use App\Devnull\Data\DraftImage;
use App\Devnull\Data\Page;
use App\Devnull\Data\StashedDraft;
use App\Devnull\Display;
use App\Devnull\DraftImages;
use App\Devnull\Exceptions\ApiException;
use App\Devnull\Outbox;
use App\Devnull\PostAs;
use App\Devnull\ResponseCache;
use App\Devnull\RowCache;
use App\Devnull\SelfThread;
use App\Devnull\Stash;
use App\Devnull\ThreadRows;
use App\Devnull\Timeline;
use App\Devnull\Toasts;
use App\Icons\Lucide;
use App\Models\PendingWrite;
use App\NativeComponents\Concerns\ExpandsThreads;
use App\NativeComponents\Concerns\LeavesGonePush;
use App\NativeComponents\Concerns\OpensInTab;
use App\NativeComponents\Concerns\OpensLiveStream;
use App\NativeComponents\Concerns\RedrawsOnAppearanceChange;
use App\NativeComponents\Concerns\RefreshesInBackground;
use App\NativeComponents\Concerns\ShowsCommits;
use App\NativeComponents\Concerns\TalksToDevnull;
use Closure;
use Illuminate\View\View;
use Native\Mobile\Edge\Layouts\Builders\NavAction;
use Native\Mobile\Edge\Layouts\Builders\NavBarOptions;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Edge\Transition;

/**
 * One commit: its ancestors above, its replies below, and a reply composer.
 * A part of a series has the earlier parts above it instead, and the later
 * parts below, with its replies between, as on the web. Deleted ancestors,
 * parts and replies show as tombstones.
 *
 * The commit opens near the top, under the commit it replies to, with the
 * ones before that behind "↑ N earlier commits" and its replies behind
 * "› N replies", each opened in place. Opened from a "› N replies" button
 * ($data['replies']), its replies already show. They come ten at a time
 * (see Client::RepliesPerPage), and "Show more" under the last asks for the
 * next ten.
 *
 * Each reply's own replies open in place under it, and theirs under them, as
 * deep as they go, as the web's commit page does (see ExpandsThreads and
 * App\Devnull\ThreadRows). So do the commits above the commit, less the one
 * on the line down to it, which already shows (see ancestorThreads()).
 *
 * Tapping a commit in the tree, above the commit, below it or among the
 * replies at any depth, moves this page to it rather than pushing another
 * (see refocus()). Back still leaves the page.
 *
 * It opens on the commit as it was last time, or opened from a row, on what
 * the row knew of it, then asks devnull for the rest behind it and fills it in.
 *
 * The reply is stashed as you type, per commit, as on the web (see Stash), so
 * leaving the page keeps it, and it's in the reply bar again, marked as
 * restored, when you come back to the commit. Drop throws it away whenever
 * there's anything in it.
 *
 * The reply bar does everything the composer sheet did for a reply, so a
 * reply never needs the sheet: presenting one takes the keyboard down and
 * puts it back up on both platforms, however soon its field takes focus.
 * Photos come from the library or the camera into the bar, above the field,
 * each with its ✕ and ALT (see AttachesPhotos), and Preview shows the reply
 * as it will look in the field's place (see toggleReplyPreview()). Reply in
 * the bar sends them all.
 *
 * Related commits wait under a header with their count, closed, and open
 * in place when it's tapped (see toggleRelated()).
 *
 * A reply goes at once: the bar empties and the reply shows staged under the
 * commit while it goes to devnull from the outbox (see SyncsOutbox), then the
 * real one takes its place. The page scrolls to it as it shows, and stays
 * there when the real one comes (see scrollToRow()). If devnull turns it down, it leaves, and what you
 * wrote is back in the bar, or in the sheet with its photos. Edit, on the
 * toast of a reply or a quote turned down after you'd left, opens its commit
 * here with it ($data['edit']).
 *
 * A member of a GitHub organization can reply as it, from the avatar at the
 * start of the bar's row, as the web's reply composer does (see PostAs).
 *
 * A thread its author wrote as replies to themselves reads in order, as on
 * the web (see SelfThread): the parts after the commit follow it on its rail,
 * each with its place in the thread, ten at a time with "Show more of this
 * thread", and the next part isn't among the commit's replies. The replies of
 * the last part show under it. The earlier parts are the ancestors above it,
 * and wear their places too. Each step needs the replies of the commit above
 * it, which are asked for one after another ($partReplies).
 */
class CommitDetail extends NativeComponent
{
    use ExpandsThreads {
        toggleReplies as private toggleThread;
        showMoreReplies as private showMoreThreadReplies;
    }
    use LeavesGonePush;
    use OpensInTab;
    use OpensLiveStream;
    use RedrawsOnAppearanceChange;
    use RefreshesInBackground;
    use ShowsCommits {
        openCommit as private openCommitPage;
        openReplies as private openRepliesPage;
        replyTo as private replyOnPage;
        reopenFailedCommit as private reopenFailedCommitInSheet;
        editFailedCommit as private editFailedCommitInSheet;
        mentionFields as private sheetMentionFields;
        attachedPhotos as private sheetPhotos;
        photosChanged as private sheetPhotosChanged;
        takesPhotos as private sheetTakesPhotos;
        photoFailed as private sheetPhotoFailed;
    }
    use TalksToDevnull;

    public string $id = '';

    public bool $embedded = false;

    /** @var array<string, mixed> */
    public array $conversationData = [];

    public ?Commit $commit = null;

    /** @var list<Commit> */
    public array $ancestors = [];

    /**
     * The commits above whose own page was asked for their count since the page last asked devnull again.
     *
     * @var list<string>
     */
    protected array $countedAncestors = [];

    /**
     * The parts of its series after the commit, in order.
     *
     * @var list<Commit>
     */
    public array $series = [];

    /** @var list<Commit> */
    public array $replies = [];

    /**
     * The first page of replies of the commits around this one in its author's thread, by id, which say which
     * reply carries it on (see SelfThread).
     *
     * @var array<string, list<Commit>>
     */
    public array $partReplies = [];

    /**
     * The commits in $partReplies with more replies than their first page.
     *
     * @var list<string>
     */
    public array $partsWithMore = [];

    /**
     * How many parts of the author's thread show after the commit.
     */
    public int $threadLimit = SelfThread::PerPage;

    public ?string $repliesCursor = null;

    /**
     * Other threads closest in meaning, in devnull's relevance order.
     *
     * @var list<Commit>
     */
    public array $related = [];

    /**
     * Whether the reply bar shows the reply as it will look, in the field's place.
     */
    public bool $replyPreviewing = false;

    /**
     * What devnull sent back for the reply's body, with the body it was for under `draft`.
     *
     * @var array<string, mixed>|null
     */
    public ?array $replyPreview = null;

    /**
     * Whether the related commits show under their header.
     */
    public bool $relatedShown = false;

    /**
     * Whether the related commits are drawn see-through: about to fade in, or fading out on their way to closing.
     */
    public bool $relatedFaded = false;

    /**
     * Whether the related commits are fading out, still drawn until they have.
     */
    public bool $relatedClosing = false;

    /**
     * How long the related commits take to fade in or out, in milliseconds, as a Log thread's replies do.
     */
    public const int RelatedFade = 220;

    /**
     * How much later each related commit starts fading in than the one above it, in milliseconds.
     */
    public const int RelatedStagger = 40;

    /**
     * Whether the replies have been kept or fetched. Until then they show as loading.
     */
    public bool $repliesLoaded = false;

    public string $reply = '';

    /**
     * The photos in the reply's draft, attached in the sheet, which the bar sends with it.
     *
     * @var list<DraftImage>
     */
    public array $replyImages = [];

    /**
     * The handle of the organization the reply bar posts as, or null for you (see PostAs).
     */
    public ?string $replyAuthor = null;

    /**
     * Whether the reply was stashed when the page opened, which the bar marks until you empty it or drop it.
     */
    public bool $replyRestored = false;

    /**
     * Whether the ancestors before the commit's parent show above it, rather than "↑ N earlier commits".
     */
    public bool $earlierShown = false;

    /**
     * Whether the replies show under the commit, rather than "› N replies".
     */
    public bool $repliesShown = false;

    /**
     * Opened from a Reply action, so the reply composer takes the focus.
     */
    public bool $focusReply = false;

    /**
     * Which of the scroll view's rows it brings into view: the commit's, once tapping another commit in the tree
     * moved the page to it (see refocus()), or the reply you just sent (see scrollToRow()). Null until one of those,
     * so the page opens at the top as it always has.
     */
    public ?int $scrollTarget = null;

    /**
     * Counts the moves refocus() and scrollToRow() made. The scroll view takes it as its auto-scroll-token, so asking for the same row
     * as last time (going back to the top twice) scrolls again rather than doing nothing.
     */
    public int $scrollRequest = 0;

    /**
     * The key of the row scrollToRow() brought into view last, so a reply you sent can be followed when devnull's
     * takes its place (see showCommitWent()).
     */
    private ?string $scrolledToRow = null;

    /**
     * The commit the page opened on, before tapping others in its tree moved it (see refocus()).
     */
    public string $openedId = '';

    /**
     * The page's rows from the last frame, so a render only builds the ones that changed, as the Log does its rows:
     * the commit, what it replies to and the rest of its series, its replies, and the related commits. Typing a reply
     * draws a frame a few times a second, and each used to build every commit above the reply bar again.
     */
    private ?RowCache $replyRows = null;

    public function data(string $key, mixed $default = null): mixed
    {
        return $this->embedded ? ($this->conversationData[$key] ?? $default) : parent::data($key, $default);
    }

    /** @param array<string, mixed> $data */
    public function applyConversationIntent(array $data): void
    {
        $this->conversationData = $data;
        if (($data['reply'] ?? false) === true) {
            $this->focusReply = true;
        }
        if (($data['replies'] ?? false) === true) {
            $this->repliesShown = true;
        }
        $this->editFromData();
    }

    public function mount(): void
    {
        $this->openedId = $this->id;
        $this->focusReply = $this->data('reply', false) === true;
        $this->repliesShown = $this->embedded || $this->data('replies', false) === true;
        $draft = app(Stash::class)->get($this->me()?->id, $this->replyStashName());
        $this->reply = $draft->body;
        $this->replyImages = $draft->images;
        $this->replyRestored = ! $draft->isEmpty();
        $this->showKept();
        $this->revalidate();
        $this->editFromData();
    }

    /**
     * A reply opened from here may have been deleted, or replied to, in the meantime.
     */
    public function onResume(): void
    {
        $this->forgetRefreshes();
        $this->showKept();
        $this->revalidate();
    }

    public function navTitle(): string
    {
        return 'Commit';
    }

    /**
     * The short hash under the title, and Delete for your own. In the iOS workspace (see Workspace) Delete keeps the
     * system's trash it has always drawn there.
     */
    public function navigationOptions(): ?NavBarOptions
    {
        $options = NavBarOptions::make()->subtitle($this->commit?->shortHash ?? Display::shortHash($this->id));

        if ($this->isOwn()) {
            $options = $options->action(
                NavAction::make('delete')
                    ->icon(ios: $this->embedded ? 'trash' : Lucide::Trash2->ios(), android: Lucide::Trash2->android())
                    ->a11yLabel('Delete commit')
                    ->destructive()
                    ->press("confirmDelete('{$this->id}')"),
            );
        }

        return $options;
    }

    /**
     * In a workspace, back closes this commit's detail, as its back button does.
     */
    public function back(Transition|string|null $transition = null): static
    {
        if ($this->nativeParentComponent instanceof Workspace) {
            $this->nativeParentComponent->closePane($this);

            return $this;
        }

        return parent::back($transition);
    }

    /**
     * Asks again for the commit, its replies and any deleted reply opened in place.
     */
    public function load(): void
    {
        $this->revalidate();
    }

    protected function revalidate(): void
    {
        $this->countedAncestors = [];
        $this->refresh($this->threadPath(), [], fn (array $json) => $this->takeCommitThread($json));
        $this->refresh($this->threadPath().'/replies', [], fn (array $json) => $this->takeReplies($json));
        $this->refresh($this->threadPath().'/related', [], fn (array $json) => $this->takeRelated($json), quiet: true, failed: function (): void {
            $this->related = [];
            app(ResponseCache::class)->forget($this->threadPath().'/related');
        });

        foreach (array_keys($this->partReplies) as $id) {
            $this->refreshPartReplies((string) $id);
        }

        $this->refreshThreads();
        $this->countAncestorReplies();
    }

    /**
     * A commit a push opened that has since been deleted goes back to Notifications.
     */
    protected function refused(ApiException $exception): bool
    {
        return $this->leaveGonePush($exception, 'That commit has been deleted.');
    }

    protected function showsContent(): bool
    {
        return $this->commit !== null;
    }

    /**
     * "↑ N earlier commits": the ancestors open above the commit.
     */
    public function showEarlier(): void
    {
        $this->earlierShown = true;
        $this->countAncestorReplies();
    }

    /**
     * A commit in the tree this page shows moves the page to it, rather than
     * pushing another commit page (see refocus()). Anything else opens its page.
     */
    public function openCommit(string $id): void
    {
        if (! $this->refocus($id)) {
            $this->openCommitPage($id);
        }
    }

    /**
     * "Show more" under the last part of a thread, or "› N replies" on a commit in the tree: the page moves to it with
     * its replies showing.
     */
    public function openReplies(string $id): void
    {
        if (! $this->refocus($id)) {
            $this->openRepliesPage($id);
        }
    }

    /**
     * Reply on a commit in the tree moves the page to it, with the reply bar answering it and its keyboard up.
     */
    public function replyTo(string $id): void
    {
        if ($id === $this->id) {
            $this->focusReply = true;

            return;
        }

        if ($this->refocus($id)) {
            $this->focusReply = true;

            return;
        }

        $this->replyOnPage($id);
    }

    /**
     * Moves the page to a commit already in its tree: an ancestor, a part of
     * the author's thread or series, or a reply at any depth. It becomes the
     * commit the page is about, with what's above it as its ancestors and its
     * replies showing below, all from what the page already has, so nothing
     * goes blank, and devnull is asked for the rest behind it. The commits
     * above it start with their replies folded, showing only "› N replies",
     * as when the page opens on a reply. Moving up, the commit the page was on
     * keeps its replies, and so do the commits between, so the commit you
     * move to shows the way back down to where you came from opened under it.
     *
     * The page stays one screen: Back still leaves it, as Simon asked, rather
     * than stepping back through the commits it moved to. The scroll view
     * brings the commit into view, with every ancestor showing above it.
     *
     * False, changing nothing, for a commit that isn't in the tree, or one
     * that's staged or deleted, which has no page of its own.
     */
    private function refocus(string $id): bool
    {
        if ($this->commit === null || $id === $this->id) {
            return false;
        }

        $target = $this->treeCommit($id);
        $ancestors = $target === null || $target->isStaged() || $target->isDeleted() ? null : $this->ancestorsInTree($target);

        if ($ancestors === null) {
            return false;
        }

        $line = [...$this->ancestors, $this->commit];
        $below = array_slice($line, count($ancestors) + 1);

        // What the page was on keeps its replies, opened in place, when it ends up below the commit the page moves to.
        if ($this->repliesShown && $this->repliesLoaded && $this->replies !== []) {
            $this->threads[$this->id] = array_values(array_filter($this->replies, fn (Commit $reply): bool => ! $reply->isStaged()));
            $this->keepCursor($this->id, $this->repliesCursor);
            unset($this->threadShown[$this->id]);
        }

        // Moving up, the commits between keep their replies open, so the one the page was on shows under them.
        foreach ($below as $between) {
            if ($between->id !== $this->id && ! array_key_exists($between->id, $this->threads) && ($kept = $this->cached('commits/'.rawurlencode($between->id).'/replies')) !== null) {
                $this->takeThread($between->id, $kept);
            }
        }

        $this->id = $id;
        $this->commit = $target;
        $this->ancestors = $ancestors;
        $this->series = [];
        $this->related = [];
        $this->relatedShown = false;
        $this->threadLimit = SelfThread::PerPage;
        $this->takeOwnReplies($id);

        // The commits above it fold their replies, the commit the page was on among them when the page moved down.
        foreach ($this->ancestors as $ancestor) {
            $this->closeThread($ancestor->id);
        }

        if (($thread = $this->cached($this->threadPath())) !== null) {
            $this->takeCommitThread($thread);
        }

        $this->takeRelated($this->cached($this->threadPath().'/related') ?? []);
        $this->repliesShown = true;
        $this->earlierShown = true;
        $this->takeReplyDraft();
        $this->error = null;

        // The commit's row is the one after its ancestors, whose replies are folded. A new token asks again even when
        // that's the row the scroll view went to last time. The scroll view keeps its key, so its rows slide to their
        // new places. Opening an ancestor's replies later leaves the target be, so it doesn't scroll.
        $this->scrollTarget = count($this->ancestors);
        $this->scrollRequest++;
        $this->forgetRefreshes();
        $this->revalidate();
        $this->followThread();

        return true;
    }

    /**
     * The commit's own replies, as the page knows them: opened in place, read for its author's thread, or kept from
     * before. Until one of those, they show as loading.
     */
    private function takeOwnReplies(string $id): void
    {
        $this->replies = [];
        $this->repliesCursor = null;
        $this->repliesLoaded = false;

        if (array_key_exists($id, $this->threads)) {
            $this->replies = $this->threads[$id];
            $this->repliesCursor = $this->threadCursors[$id] ?? null;
            $this->repliesLoaded = true;
            unset($this->threads[$id], $this->threadCursors[$id], $this->threadShown[$id]);
            $this->pagedThreads = array_values(array_diff($this->pagedThreads, [$id]));
        } elseif (($kept = $this->cached($this->threadPath().'/replies')) !== null) {
            $this->takeReplies($kept);

            return;
        } elseif (array_key_exists($id, $this->partReplies)) {
            $this->replies = $this->partReplies[$id];
            $this->repliesLoaded = true;
        }

        $this->keepStagedReplies();
    }

    /**
     * The reply bar takes up the draft kept for the commit the page is on now.
     */
    private function takeReplyDraft(): void
    {
        $draft = app(Stash::class)->get($this->me()?->id, $this->replyStashName());
        $this->reply = $draft->body;
        $this->replyImages = $draft->images;
        $this->replyRestored = ! $draft->isEmpty();
        $this->replyAuthor = null;
        $this->replyPreviewing = false;
        $this->replyPreview = null;
        $this->focusReply = false;
    }

    /**
     * A commit the page draws in its tree, by id: around the commit, in its author's thread, or among the replies.
     */
    private function treeCommit(string $id): ?Commit
    {
        $lastPart = $this->commit === null ? null : $this->selfThread()->parts;
        $tree = [
            ...$this->ancestors,
            ...$this->series,
            ...($lastPart ?? []),
            ...$this->replies,
            ...$this->threadCommits(),
            ...array_merge([], ...array_values($this->partReplies)),
        ];

        foreach ($tree as $commit) {
            if ($commit->id === $id) {
                return $commit;
            }
        }

        return null;
    }

    /**
     * What's above $target in the tree, in order: the page's line of ancestors as far as the one it hangs from, then
     * the replies, parts or series between. Null when it doesn't hang from that line.
     *
     * @return list<Commit>|null
     */
    private function ancestorsInTree(Commit $target): ?array
    {
        $line = [...$this->ancestors, ...($this->commit === null ? [] : [$this->commit])];
        $lineIds = array_map(fn (Commit $commit): string => $commit->id, $line);
        $at = array_search($target->id, $lineIds, true);

        if ($at !== false) {
            return array_slice($line, 0, $at);
        }

        $between = [];
        $parentId = $target->parentId;

        while ($parentId !== null && ! in_array($parentId, $lineIds, true)) {
            $parent = $this->treeCommit($parentId);

            if ($parent === null || in_array($parent, $between, true)) {
                return null;
            }

            array_unshift($between, $parent);
            $parentId = $parent->parentId;
        }

        if ($parentId === null) {
            return null;
        }

        return [...array_slice($line, 0, array_search($parentId, $lineIds, true) + 1), ...$between];
    }

    /**
     * The replies of each commit above the commit, as its "› N replies" opens them in place under it, the same as a
     * reply's (see ExpandsThreads), and how many that button counts. The commit under it on the page, when that's
     * one of its replies, is left out of both: it's already showing, on the line down to the commit. The web leaves
     * out only the part of a self-thread that carries it on (see SelfThread), so there the rest of the line would
     * show twice. devnull's count already leaves that part out.
     *
     * @param  list<Commit>  $above
     * @return array<string, array{count: int, rows: list<array{key: string, partial: string, data: array<string, mixed>, reply: Commit|null}>}>
     */
    private function ancestorThreads(array $above): array
    {
        if ($this->commit === null) {
            return [];
        }

        $line = [...$above, $this->commit];
        $threads = [];

        foreach ($above as $index => $ancestor) {
            $next = $line[$index + 1];
            $onLine = $next->parentId === $ancestor->id && $ancestor->continuationId !== $next->id;
            $replies = array_values(array_filter($this->threads[$ancestor->id] ?? [], fn (Commit $reply): bool => $reply->id !== $next->id));

            $threads[$ancestor->id] = [
                'count' => max(0, $ancestor->repliesCount - ($onLine ? 1 : 0)),
                'rows' => $ancestor->isDeleted() || $replies === [] ? [] : ThreadRows::under(
                    $ancestor,
                    [...$this->threads, $ancestor->id => $replies],
                    $this->threadCursors,
                    $this->threadShown,
                    $ancestor->lane(),
                    opensInPlace: PHP_INT_MAX,
                    onPage: true,
                    // The next commit on the line follows them, so they end on the hairline the ancestor's row gave up.
                    hairline: true,
                ),
            ];
        }

        return $threads;
    }

    /**
     * The commits on the page's line, above the commit and the commit itself, draw rows of their own, so closing the
     * replies they're among leaves their own replies open.
     */
    protected function drawsOnItsOwn(string $id): bool
    {
        return $id === $this->id || in_array($id, array_map(fn (Commit $ancestor): string => $ancestor->id, $this->ancestors), true);
    }

    /**
     * "› N replies" under the commit opens them in place, and "Hide replies" folds them away. Under a reply, at any
     * depth, or a commit above the commit, it opens that one's replies in place under it (see ExpandsThreads).
     */
    public function toggleReplies(string $id = ''): void
    {
        if ($id === '' || $id === $this->id) {
            $this->repliesShown = ! $this->repliesShown;

            return;
        }

        $this->toggleThread($id);
    }

    /**
     * "Show more" under a reply's replies opened in place. The commit's own go on from loadMoreReplies().
     */
    public function showMoreReplies(string $id): void
    {
        if ($id === $this->id) {
            $this->loadMoreReplies();

            return;
        }

        $this->showMoreThreadReplies($id);
    }

    /**
     * "Show more of this thread": the next ten parts of the author's thread, as on the web.
     */
    public function showMoreThread(): void
    {
        $this->threadLimit += SelfThread::PerPage;
        $this->followThread();
    }

    /**
     * The web's preview toggle, in the reply bar: the reply shows as it will
     * look in the field's place, at once, and devnull is asked for its body,
     * with its code and mentions, unless it already said for this one. The
     * keyboard goes down with the field. Pressed again, the field comes back
     * with the keyboard.
     */
    public function toggleReplyPreview(): void
    {
        $this->replyPreviewing = ! $this->replyPreviewing;
        $this->describing = null;

        if (! $this->replyPreviewing) {
            $this->focusReply = true;

            return;
        }

        $body = trim($this->reply);

        if ($body === '' || ($this->replyPreview['draft'] ?? null) === $body) {
            return;
        }

        $this->writeInBackground('POST', Client::PreviewPath, ['body' => $body], function (array $json) use ($body): void {
            if (is_array($json['data'] ?? null)) {
                $this->replyPreview = [...$json['data'], 'draft' => $body];
            }
        }, fn (): null => null);
    }

    /**
     * The related commits' header opens them, and closes them again. EDGE
     * can't animate a node coming into the page or leaving it, so they're
     * drawn see-through first and shown once a Beat comes back, which each
     * row fades and slides to, a little after the one above. Closing fades
     * them out the same way, then takes them out.
     */
    public function toggleRelated(): void
    {
        if ($this->relatedClosing) {
            return;
        }

        if (! $this->relatedShown) {
            $this->relatedShown = true;
            $this->relatedFaded = true;
            $shown = fn () => $this->relatedFaded = false;

            Beat::dispatch(Beat::Step)->finished($shown)->failed($shown);

            return;
        }

        $this->relatedFaded = true;
        $this->relatedClosing = true;
        $closed = function (): void {
            $this->relatedShown = false;
            $this->relatedFaded = false;
            $this->relatedClosing = false;
        };

        Beat::dispatch(self::RelatedFade)->finished($closed)->failed($closed);
    }

    public function loadMoreReplies(): void
    {
        if ($this->repliesCursor === null) {
            return;
        }

        $page = $this->attempt(fn ($devnull) => $devnull->replies($this->id, $this->repliesCursor));

        if ($page !== null) {
            // A reply you posted is shown at the end before its page loads, so it moves to where the page has it.
            $loaded = array_flip(array_map(fn (Commit $reply): string => $reply->id, $page->items));

            $this->replies = [...array_values(array_filter($this->replies, fn (Commit $reply): bool => ! isset($loaded[$reply->id]))), ...$page->items];
            $this->repliesCursor = $page->nextCursor;
            $this->keepStagedReplies();
            $this->followThread();
        }
    }

    /**
     * Sends the reply in the bar, with any photos its draft has: the bar
     * empties, and the reply shows staged under the commit, at once, while it
     * goes from the outbox.
     */
    public function sendReply(): void
    {
        $body = trim($this->reply);
        $me = $this->me();

        if ($me === null || $this->commit === null || $this->commit->isDeleted() || ! Draft::canCommit($body, $this->replyImages)) {
            return;
        }

        $write = app(Outbox::class)->add(PendingWrite::Commit, $this->id, [
            'body' => $body,
            'parent_id' => $this->id,
            'images' => PendingWrite::imagePayload($this->replyImages),
            'stash' => $this->replyStashName(),
            'composer' => 'bar',
            ...PendingWrite::authorPayload(PostAs::author($this->replyAuthor)),
        ]);

        if ($write === null) {
            return;
        }

        $this->reply = '';
        $this->replyImages = [];
        $this->replyPreviewing = false;
        $this->replyPreview = null;
        $this->forgetMention('reply');
        $this->updatedReply();
        $this->repliesShown = true;
        $this->keepStagedReplies();
        $this->scrollToRow('thread-reply-'.PendingWrite::stagedId($write->client_id));
        $this->sendFromOutbox($write);
    }

    /**
     * Post as, from the reply bar's avatar: an organization's handle, or '' for you.
     */
    public function postAsInReply(string $handle = ''): void
    {
        $this->replyAuthor = PostAs::author($handle)?->username;
    }

    /**
     * The reply bar suggests whom devnull suggests for a reply to this commit, the people in its thread first, as
     * the web's reply composer asks, and so does the composer sheet over it (see SuggestsMentions).
     */
    protected function mentionFields(): array
    {
        $bar = $this->commit === null || $this->sheetOpen ? [] : ['reply' => ['limit' => Commit::MaxRawLength, 'reply' => $this->commit->id]];

        return [...$this->sheetMentionFields(), ...$bar];
    }

    /**
     * Stashes the reply as it changes, with its photos. With nothing left in it, it's forgotten, and isn't restored
     * any more.
     */
    public function updatedReply(): void
    {
        $draft = new StashedDraft($this->reply, $this->replyImages);
        app(Stash::class)->put($this->me()?->id, $this->replyStashName(), $draft);

        if ($draft->isEmpty()) {
            $this->replyRestored = false;
        }
    }

    /**
     * The web's drop, beside the reply while there's anything in it: it's thrown away, photos too, and the field
     * starts again empty.
     */
    public function dropReplyStash(): void
    {
        app(DraftImages::class)->discard($this->replyImages);
        $this->reply = '';
        $this->replyImages = [];
        $this->replyPreviewing = false;
        $this->replyPreview = null;
        $this->describing = null;
        $this->updatedReply();
    }

    /**
     * A reply sent from the composer sheet goes under the commit, staged, as one sent from the reply bar does.
     */
    protected function showCommitted(Commit $commit): void
    {
        if ($commit->parentId !== $this->id) {
            return;
        }

        $this->reply = '';
        $this->replyImages = [];
        $this->replyRestored = false;
        $this->repliesShown = true;
        $this->keepStagedReplies();
        $this->scrollToRow('thread-reply-'.$commit->id);
    }

    /**
     * devnull has the reply: the real one takes the staged one's place.
     */
    protected function showCommitWent(PendingWrite $write, Commit $commit): void
    {
        $staged = PendingWrite::stagedId($write->client_id);

        $this->replies = array_map(fn (Commit $reply): Commit => $reply->id === $staged ? $commit : $reply, $this->replies);
        $this->followThread();
        $this->followSentReply('thread-reply-'.$staged, $commit);
    }

    /**
     * The page scrolled to a reply you sent, staged, and devnull's reply has taken its place. That's the same row,
     * so the scroll view is left be. Only when the reply has moved, as the next part of your thread below the
     * commit's replies, does the page follow it there.
     */
    private function followSentReply(string $stagedKey, Commit $commit): void
    {
        if ($this->scrolledToRow !== $stagedKey) {
            return;
        }

        $keys = array_column($this->pageRows($this->viewData()), 'key');
        $at = array_search('thread-reply-'.$commit->id, $keys, true);
        $at = $at === false ? array_search('commit-'.$commit->id, $keys, true) : $at;

        if ($at === false || $at === $this->scrollTarget) {
            $this->scrolledToRow = null;

            return;
        }

        $this->scrollToRow($keys[$at]);
    }

    /**
     * devnull turned the reply down: the staged one leaves.
     */
    protected function showCommitTurnedDown(PendingWrite $write): void
    {
        $this->keepStagedReplies();
    }

    protected function opensComposer(): bool
    {
        return $this->embedded;
    }

    /**
     * A reply to this commit from the bar goes back into the bar, marked as
     * restored, when the bar's free. One from the sheet opens the sheet again,
     * with its photos.
     */
    protected function reopenFailedCommit(PendingWrite $write): bool
    {
        if ($write->composer() !== 'bar') {
            return $this->reopenFailedCommitInSheet($write);
        }

        if ($write->parentId() !== $this->id || $this->sheetOpen || trim($this->reply) !== '' || $this->replyImages !== []) {
            return false;
        }

        $this->takeFailedReply($write);

        return true;
    }

    /**
     * Edit, on the toast of a reply from the bar: this commit's bar, or the commit it was for.
     */
    protected function editFailedCommit(PendingWrite $write): void
    {
        if ($write->composer() !== 'bar' || $write->parentId() !== $this->id) {
            $this->editFailedCommitInSheet($write);

            return;
        }

        $this->sheetOpen = false;
        $this->takeFailedReply($write);
    }

    /**
     * The photos on the draft being written: the sheet's while it's up, otherwise the reply bar's.
     *
     * @return list<DraftImage>
     */
    protected function attachedPhotos(): array
    {
        return $this->sheetOpen ? $this->sheetPhotos() : $this->replyImages;
    }

    /**
     * Photos added in the reply bar, or taken off it, go in the reply's stash with its text.
     *
     * @param  list<DraftImage>  $photos
     */
    protected function photosChanged(array $photos): void
    {
        if ($this->sheetOpen) {
            $this->sheetPhotosChanged($photos);

            return;
        }

        $this->replyImages = $photos;
        $this->updatedReply();
    }

    /**
     * A reply can have photos, as on the web, unless its commit has gone.
     */
    protected function takesPhotos(): bool
    {
        return $this->sheetOpen ? $this->sheetTakesPhotos() : $this->commit !== null && ! $this->commit->isDeleted();
    }

    /**
     * Why a photo won't do comes as a toast, from the top, clear of the reply bar, as it does in Remotes.
     */
    protected function photoFailed(?string $error): void
    {
        if ($this->sheetOpen) {
            $this->sheetPhotoFailed($error);

            return;
        }

        if ($error !== null) {
            Toasts::failed($error);
        }
    }

    /**
     * The reply bar takes back the draft it handed the sheet, as it was left there, photos too.
     */
    protected function commitSheetGone(string $view): void
    {
        if ($view === 'reply') {
            $draft = app(Stash::class)->get($this->me()?->id, $this->replyStashName());
            $this->reply = $draft->body;
            $this->replyImages = $draft->images;
            $this->replyRestored = false;
        }
    }

    /**
     * The commit this page is about takes you back where you came from. Any
     * other of yours leaves the page as it would coming back to it.
     */
    protected function showDeleted(string $id): void
    {
        // The page moved here from a commit above it, so it goes back there rather than leaving.
        $parent = $this->ancestors === [] ? null : end($this->ancestors);

        if ($id === $this->id && $id !== $this->openedId && $parent !== null && $this->refocus($parent->id)) {
            $this->threads = array_map(fn (array $replies): array => array_values(array_filter($replies, fn (Commit $reply): bool => $reply->id !== $id)), $this->threads);
            $this->replies = array_values(array_filter($this->replies, fn (Commit $reply): bool => $reply->id !== $id));

            return;
        }

        if ($id === $this->id) {
            $this->back();

            return;
        }

        $this->onResume();
    }

    protected function updateShownCommits(Closure $update): void
    {
        $this->commit = $this->commit === null ? null : $update($this->commit);
        $this->ancestors = array_map($update, $this->ancestors);
        $this->series = array_map($update, $this->series);
        $this->replies = array_map($update, $this->replies);
        $this->partReplies = array_map(fn (array $replies): array => array_map($update, $replies), $this->partReplies);
        $this->related = array_map($update, $this->related);
        $this->threads = array_map(fn (array $replies): array => array_map($update, $replies), $this->threads);
    }

    /**
     * @return iterable<Commit>
     */
    protected function shownCommits(): iterable
    {
        return [...($this->commit === null ? [] : [$this->commit]), ...$this->ancestors, ...$this->series, ...$this->replies, ...array_merge([], ...array_values($this->partReplies)), ...$this->related, ...$this->threadCommits()];
    }

    /**
     * Opened by Edit on the toast of a reply or a quote devnull turned down: its composer, with what it had.
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
     * The bar takes back a reply devnull turned down, with its photos, and the outbox lets it go: it's a draft again.
     */
    private function takeFailedReply(PendingWrite $write): void
    {
        $this->reply = $write->body();
        $this->replyImages = $write->images();
        $this->replyAuthor = $write->author();
        $this->replyRestored = true;
        $this->focusReply = true;
        $this->updatedReply();
        app(Outbox::class)->forget($write);
    }

    /**
     * Your replies to this commit still on their way go after the rest,
     * staged, oldest first. Ones that went or were turned down while the page
     * wasn't showing leave, and come back with devnull's replies if they went.
     */
    private function keepStagedReplies(): void
    {
        $this->replies = [
            ...array_values(array_filter($this->replies, fn (Commit $reply): bool => ! $reply->isStaged())),
            ...array_reverse($this->stagedCommits($this->id)),
        ];
    }

    /**
     * The commit and its replies as they were kept last time. Opened from a row
     * with nothing kept, it starts from what the row knew: the commit, and the
     * commit it replies to when the row had that too.
     */
    private function showKept(): void
    {
        if (($thread = $this->cached($this->threadPath())) !== null) {
            $this->takeCommitThread($thread);
        } elseif ($this->commit === null && ($commit = $this->data('commit')) instanceof Commit && $commit->id === $this->id) {
            $this->commit = $commit;
            $this->ancestors = $commit->parent === null ? [] : [$commit->parent];
        }

        if (($replies = $this->cached($this->threadPath().'/replies')) !== null) {
            $this->takeReplies($replies);
        }

        $this->takeRelated($this->cached($this->threadPath().'/related') ?? []);
        $this->keepStagedReplies();
        $this->followThread();
    }

    /**
     * @param  array<string, mixed>  $json
     */
    private function takeRelated(array $json): void
    {
        $this->related = Page::fromArray($json, Commit::fromArray(...))->items;
    }

    /**
     * @param  array<string, mixed>  $json
     */
    private function takeCommitThread(array $json): void
    {
        $thread = CommitThread::fromArray($json);

        $this->commit = $thread->commit;
        $this->ancestors = $thread->ancestors;
        $this->series = $thread->series;
        $this->countAncestorReplies();
        $this->followThread();
    }

    /**
     * The commits above the commit that show: its parent, or every one once "↑ N earlier commits" opened them.
     *
     * @return list<Commit>
     */
    private function shownAncestors(): array
    {
        return $this->earlierShown ? $this->ancestors : array_slice($this->ancestors, -1);
    }

    /**
     * devnull sends the commits above the commit without their counts, so on their own a commit above never shows
     * its "› N replies". Each one showing takes its count from its own page instead: kept from before at once, and
     * asked for behind. That also keeps its page, so tapping it moves there with nothing blank.
     */
    private function countAncestorReplies(): void
    {
        foreach ($this->shownAncestors() as $ancestor) {
            if ($ancestor->isStaged() || $ancestor->isDeleted()) {
                continue;
            }

            $id = $ancestor->id;
            $path = 'commits/'.rawurlencode($id);

            if (($kept = $this->cached($path)) !== null) {
                $this->takeAncestorCount($id, $kept);
            }

            // The commit's page landing draws its ancestors again, and they were asked for once already.
            if (! in_array($id, $this->countedAncestors, true)) {
                $this->countedAncestors[] = $id;
                $this->refresh($path, [], fn (array $json) => $this->takeAncestorCount($id, $json), quiet: true);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $json
     */
    private function takeAncestorCount(string $id, array $json): void
    {
        $page = CommitThread::fromArray($json)->commit;

        if ($page->id !== $id) {
            return;
        }

        $this->ancestors = array_map(fn (Commit $ancestor): Commit => $ancestor->id === $id ? $ancestor->withRepliesCount($page->repliesCount) : $ancestor, $this->ancestors);
    }

    /**
     * A fresh first page of replies. Pages loaded after it stay, past what it holds.
     *
     * @param  array<string, mixed>  $json
     */
    private function takeReplies(array $json): void
    {
        [$this->replies, $this->repliesCursor] = self::withFirstPage($this->replies, $this->repliesCursor, Page::fromArray($json, Commit::fromArray(...)));
        $this->repliesLoaded = true;
        $this->keepStagedReplies();
        $this->followThread();
    }

    /**
     * Asks for the replies the author's thread needs next, above the commit and below it, each when the one
     * before has come (see SelfThread). A page kept from before shows at once and refreshes behind.
     */
    private function followThread(): void
    {
        if ($this->commit === null) {
            return;
        }

        $waiting = [
            $this->selfThread()->waiting,
            SelfThread::above($this->ancestors, $this->commit, $this->repliesOf())[1],
        ];

        foreach (array_unique(array_filter($waiting, fn (?string $id): bool => $id !== null && $id !== $this->id)) as $id) {
            $kept = $this->cached('commits/'.rawurlencode($id).'/replies');
            $this->refreshPartReplies($id);

            if ($kept !== null) {
                $this->takePartReplies($id, $kept);
            }
        }
    }

    private function refreshPartReplies(string $id): void
    {
        $this->refresh('commits/'.rawurlencode($id).'/replies', [], fn (array $json) => $this->takePartReplies($id, $json));
    }

    /**
     * @param  array<string, mixed>  $json
     */
    private function takePartReplies(string $id, array $json): void
    {
        $page = Page::fromArray($json, Commit::fromArray(...));
        $this->partReplies[$id] = $page->items;
        $this->partsWithMore = array_values(array_diff($this->partsWithMore, [$id]));

        if ($page->hasMore()) {
            $this->partsWithMore[] = $id;
        }

        $this->followThread();
    }

    /**
     * The first page of a commit's replies as far as the page has them, or null until they come. The commit's
     * own are the ones showing, less any still on their way.
     *
     * @return Closure(Commit): (list<Commit>|null)
     */
    private function repliesOf(): Closure
    {
        return fn (Commit $commit): ?array => $commit->id === $this->id
            ? ($this->repliesLoaded ? array_values(array_filter($this->replies, fn (Commit $reply): bool => ! $reply->isStaged())) : null)
            : ($this->partReplies[$commit->id] ?? null);
    }

    /**
     * The parts of the author's thread after the commit, as far as they've been read.
     */
    private function selfThread(): SelfThread
    {
        return $this->commit === null ? new SelfThread : SelfThread::below($this->commit, $this->repliesOf(), $this->threadLimit);
    }

    /**
     * A fresh first page over the replies showing, and where the next page
     * starts. Pages loaded after the first stay, past what it holds: there's
     * a page after it still to load, or more show than a page holds. Staged
     * replies are left for keepStagedReplies() to put back.
     *
     * @param  list<Commit>  $shown
     * @param  Page<Commit>  $page
     * @return array{0: list<Commit>, 1: string|null}
     */
    private static function withFirstPage(array $shown, ?string $cursor, Page $page): array
    {
        $shown = array_values(array_filter($shown, fn (Commit $reply): bool => ! $reply->isStaged()));

        if ($cursor === null && count($shown) <= Client::RepliesPerPage) {
            return [$page->items, $page->nextCursor];
        }

        $fresh = array_flip(array_map(fn (Commit $reply): string => $reply->id, $page->items));
        $later = array_values(array_filter(array_slice($shown, count($page->items)), fn (Commit $reply): bool => ! isset($fresh[$reply->id])));

        return [[...$page->items, ...$later], $later === [] ? $page->nextCursor : $cursor];
    }

    private function replyStashName(): string
    {
        return 'reply-'.$this->id;
    }

    private function threadPath(): string
    {
        return 'commits/'.rawurlencode($this->id);
    }

    /**
     * Whether the commit is yours, or an organization's you're in, which puts Delete in the nav bar (see Ownership).
     */
    public function isOwn(): bool
    {
        return $this->commit !== null && $this->ownership()->owns($this->commit);
    }

    public function render(): View
    {
        $this->replyRows ??= new RowCache;
        $this->replyRows->startFrame();
        $data = $this->viewData();

        return view('native.commit-detail', [...$data, 'replyRows' => $this->replyRows, 'pageRows' => $this->pageRows($data)]);
    }

    /**
     * What the page's view draws from, besides the component's own properties.
     *
     * @return array<string, mixed>
     */
    private function viewData(): array
    {
        $earlier = $this->earlierShown ? [] : array_slice($this->ancestors, 0, -1);
        $above = $this->shownAncestors();
        $viewer = $this->me();
        $thread = $this->selfThread();
        $parts = $thread->parts;
        $partsAbove = $this->commit === null ? 0 : SelfThread::above($this->ancestors, $this->commit, $this->repliesOf())[0];
        $lastPart = $thread->hasEnded && $parts !== [] ? end($parts) : null;
        $replies = $this->commit === null ? $this->replies : SelfThread::others($this->commit, $this->replies);

        return [
            'myColour' => $this->myColour(),
            'viewer' => $viewer,
            'ownership' => $this->ownership(),
            'stashed' => app(Stash::class)->names($viewer?->id),
            'pasteis' => app(Account::class)->pasteis(),
            'rows' => $this->commit === null ? [] : Timeline::thread(
                [...$above, $this->commit, ...$this->series, ...$parts],
                laneAbove: $earlier === [] ? null : end($earlier)->lane(),
            ),
            'relatedRows' => Timeline::thread($this->related),
            'ancestorThreads' => $this->ancestorThreads($above),
            'earlierCount' => $this->commit === null ? 0 : count($earlier),
            'earlierLane' => $earlier === [] ? null : end($earlier)->lane(),
            'replyCount' => max($this->commit->repliesCount ?? 0, count($replies)),
            'replies' => $replies,
            'threadParts' => array_map(fn (Commit $part): string => $part->id, $parts),
            'threadMore' => $thread->hasMore,
            'threadPositions' => $this->threadPositions($thread, $partsAbove),
            'lastPart' => $lastPart,
            'lastPartReplies' => $lastPart === null ? [] : array_slice($this->partReplies[$lastPart->id] ?? [], 0, Client::RepliesPerPage),
            'lastPartMore' => $lastPart !== null && (count($this->partReplies[$lastPart->id] ?? []) > Client::RepliesPerPage || in_array($lastPart->id, $this->partsWithMore, true)),
        ];
    }

    /**
     * The scroll view's rows from the top down to the end of the thread, in the order the view draws them, each one
     * child of the scroll view: "↑ N earlier commits", then each commit with what hangs under it, its replies opened
     * in place and theirs (see App\Devnull\ThreadRows). The view draws exactly these, so a row's place here is its
     * place among the scroll view's children, which auto-scroll-to counts in (see scrollToRow()). What comes after
     * the thread, the related commits and the room for the reply bar, the view draws on its own.
     *
     * @param  array<string, mixed>  $data  What viewData() gave.
     * @return list<array{key: string, partial: string|null, data: array<string, mixed>, reply: Commit|null}>
     */
    private function pageRows(array $data): array
    {
        $commit = $this->commit;
        $rows = $data['rows'];
        $replies = $data['replies'];
        $lastPart = $data['lastPart'];
        $noReplies = $commit !== null && $data['replyCount'] === 0 && $this->repliesLoaded && $this->series === [] && $data['threadParts'] === [];
        $relatedBelow = $commit !== null && $this->related !== [] && ! $noReplies;
        $pageRows = [];

        if ($data['earlierCount'] > 0) {
            $pageRows[] = ['key' => 'earlier-commits', 'partial' => 'native.partials.earlier-commits', 'data' => ['count' => $data['earlierCount'], 'lane' => $data['earlierLane']], 'reply' => null];
        }

        foreach ($rows as $index => $row) {
            $last = $index === array_key_last($rows);
            $isFocused = $row->commit->id === $commit?->id;
            $isLastPart = $lastPart !== null && $row->commit->id === $lastPart->id;
            $hasThread = $isFocused && $this->repliesShown && $replies !== [];
            $partThread = $isLastPart && $data['lastPartReplies'] !== [];
            $aboveThread = $data['ancestorThreads'][$row->commit->id] ?? null;
            $aboveOpen = ($aboveThread['rows'] ?? []) !== [];

            $pageRows[] = ['key' => 'commit-'.$row->commit->id, 'partial' => 'native.partials.commit-row', 'reply' => null, 'data' => [
                'commit' => $row->commit,
                'previousLane' => $row->previousLane,
                'continuesRun' => $row->continuesRun,
                'continuesNext' => $isFocused || $aboveOpen ? ! $last : null,
                'threadBelow' => $hasThread || $partThread || $aboveOpen,
                'repliesBelow' => $partThread,
                'focused' => $isFocused,
                'muted' => ! $isFocused,
                'seriesExpanded' => true,
                'withTail' => ! $last || $data['threadMore'],
                'hairline' => ! ($last && $relatedBelow && ! $data['threadMore']),
                'threadPosition' => $data['threadPositions'][$row->commit->id] ?? null,
                'showsThread' => false,
                'expandable' => $aboveThread !== null,
                'repliesCount' => $aboveThread['count'] ?? null,
            ]];

            if ($isFocused && $this->repliesShown && $replies === [] && ! $this->repliesLoaded && $this->error === null) {
                $pageRows[] = ['key' => 'loading-replies', 'partial' => null, 'data' => [], 'reply' => null];
            }

            // The replies, and theirs opened in place under them as deep as they go. A commit above the commit opens
            // its replies in place under it too, all but the one on the line down to the commit, which already shows
            // (see ancestorThreads()). Replies with another commit's row after them end on the hairline the row above
            // them gave up; at the foot of the page they end on none.
            array_push($pageRows, ...match (true) {
                $aboveThread !== null => $aboveThread['rows'],
                $hasThread => ThreadRows::under($commit, [...$this->threads, $commit->id => $replies], array_filter([...$this->threadCursors, $commit->id => $this->repliesCursor]), $this->threadShown, $last ? null : $commit->lane(), opensInPlace: PHP_INT_MAX, onPage: true, morePress: 'loadMoreReplies', hairline: ! $last),
                $partThread => ThreadRows::under($lastPart, [...$this->threads, $lastPart->id => $data['lastPartReplies']], array_filter([...$this->threadCursors, $lastPart->id => $data['lastPartMore'] ? 'more' : null]), $this->threadShown, opensInPlace: PHP_INT_MAX, onPage: true, morePress: "openReplies('{$lastPart->id}')"),
                default => [],
            });
        }

        return $pageRows;
    }

    /**
     * Brings the row keyed $key into view, animated, under a new token so it goes even when that's the row it went to
     * last time. Nothing happens for a row the page doesn't draw.
     */
    private function scrollToRow(string $key): void
    {
        $at = array_search($key, array_column($this->pageRows($this->viewData()), 'key'), true);

        if ($at === false) {
            return;
        }

        $this->scrollTarget = $at;
        $this->scrollRequest++;
        $this->scrolledToRow = $key;
    }

    /**
     * Each commit's place in its author's thread, by id, as the web marks them: the earlier parts above the
     * commit, the commit, and the parts after it. None when the commit isn't part of a thread.
     *
     * A part known to follow the last one read counts, and a thread not read to its end gets the web's "+".
     *
     * @return array<string, string>
     */
    private function threadPositions(SelfThread $below, int $partsAbove): array
    {
        if ($this->commit === null) {
            return [];
        }

        $thread = [...array_slice($this->ancestors, count($this->ancestors) - $partsAbove, $partsAbove), $this->commit, ...$below->parts];
        $size = count($thread) + ($below->hasMore ? 1 : 0);
        $positions = [];

        foreach ($thread as $index => $commit) {
            $label = SelfThread::position($index + 1, $size, ! $below->hasEnded);

            if ($label !== null) {
                $positions[$commit->id] = $label;
            }
        }

        return $positions;
    }
}
