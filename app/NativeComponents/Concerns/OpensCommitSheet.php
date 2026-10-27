<?php

namespace App\NativeComponents\Concerns;

use App\Devnull\Client;
use App\Devnull\Data\Commit;
use App\Devnull\Data\Draft;
use App\Devnull\Data\PollDraft;
use App\Devnull\Data\StashedDraft;
use App\Devnull\Outbox;
use App\Devnull\ReportReason;
use App\Devnull\Stash;
use App\Models\PendingWrite;
use Closure;
use Native\Mobile\Facades\System;

/**
 * The sheet under a commit (see partials/commit-sheet). Report commit, in the
 * More menu, opens it as the web's Report dialog. As on the web, only someone
 * else's published commit can be reported, so only their menus have it.
 *
 * Quote opens the same sheet as a composer, over the commit being quoted, as
 * the web's Quote dialog. The Log opens it with nothing to quote, for a new
 * commit of your own, so you can write one without leaving where you are in
 * it. Commit closes the sheet at once: the commit goes through the outbox
 * (see SyncsOutbox), and a screen that lists your new commits puts it in
 * place, staged, until devnull has it (see showCommitted()).
 *
 * What you write in it is stashed as you go, as on the web (see Stash): a
 * quote per commit quoted, and a new commit under the web's composer draft,
 * with its photos and its poll. Opened again, it starts from the draft,
 * marked as restored. It's forgotten once it's sent. If devnull turns the
 * commit down, it goes back to its draft, photos and poll too, and the screen
 * it was written on opens the sheet with it again (see reopenFailedCommit()).
 *
 * A new commit's draft can become a `/feature` or `/bug` issue, as on the web
 * (see DraftsIssues).
 *
 * A commit's page can open its reply in the same composer, for the photos and
 * the preview the reply bar has no room for (see replyInSheet()). The draft
 * is the reply bar's, and goes back to it when the sheet closes.
 *
 * The composer can attach photos, a poll and show a preview, as the web's
 * does (see AttachesToCommits).
 *
 * The sheet keeps its commit while it slides away, so it doesn't empty on the
 * way down, and lets go of it once iOS says it's gone, however it was closed.
 */
trait OpensCommitSheet
{
    use AttachesToCommits;
    use DraftsIssues;
    use SyncsOutbox;

    /**
     * Whether the sheet is up, or on its way up.
     */
    public bool $sheetOpen = false;

    /**
     * The commit the sheet is for, as it was when it opened, until the sheet has gone.
     */
    public ?Commit $sheetCommit = null;

    /**
     * What the sheet shows: 'report' (the form), 'reported' (sent), 'quote', 'reply', or 'compose' (a new commit,
     * with no commit of its own).
     */
    public string $sheetView = 'report';

    /**
     * The reason picked on the report form, or '' until one is.
     */
    public string $reportReason = '';

    public string $reportDetails = '';

    /**
     * The commit you're writing in the sheet, quoting its commit or not.
     */
    public string $sheetBody = '';

    /**
     * Whether the sheet opened on a stashed draft, which it marks until you empty it or drop it.
     */
    public bool $sheetRestored = false;

    /**
     * Whether the sheet was closed from here and is still sliding away, on
     * iOS. iOS says when it's gone (see commitSheetClosed()), and a sheet
     * opened before then goes with it.
     *
     * Android is different on both counts, so this stays false there. Its
     * sheet only sends @dismiss when you drag it away or press back, never
     * when it's closed from here, so a flag waiting for that would never
     * clear. And it keeps the sheet drawn through its hide animation, so
     * showing it again straight away simply shows it again.
     */
    public bool $sheetClosing = false;

    /**
     * A commit devnull turned down while the sheet was still sliding away,
     * whose sheet opens again once it's gone (see reopenFailedCommit()).
     */
    public ?int $reopenAfterSheet = null;

    /**
     * Why devnull didn't take the report or the commit, shown in the sheet.
     */
    public ?string $sheetError = null;

    /**
     * Report commit, in the More menu of someone else's published commit.
     */
    public function reportCommit(string $id): void
    {
        $commit = $this->shownCommit($id);

        if ($commit === null || ! $this->canReport($commit)) {
            return;
        }

        $this->forgetCommitSheet();
        $this->sheetCommit = $commit;
        $this->sheetOpen = true;
    }

    /**
     * Sends the report. The sheet says it was received, or keeps the form with why it wasn't.
     */
    public function sendReport(): void
    {
        $commit = $this->sheetCommit;
        $reason = ReportReason::tryFrom($this->reportReason);

        if ($commit === null || $this->sheetView !== 'report' || $reason === null) {
            return;
        }

        $details = trim($this->reportDetails);

        $sent = $this->attemptInSheet(function (Client $devnull) use ($commit, $reason, $details): bool {
            $devnull->reportCommit($commit->id, $reason, $details);

            return true;
        });

        if ($sent === true) {
            $this->sheetView = 'reported';
        }
    }

    /**
     * Quote, under someone else's published commit or one of your own, as on the web.
     */
    public function quoteCommit(string $id): void
    {
        $commit = $this->shownCommit($id);

        if ($commit === null || ! $this->canQuote($commit)) {
            return;
        }

        $this->forgetCommitSheet();
        $this->sheetCommit = $commit;
        $this->sheetView = 'quote';
        $this->sheetOpen = true;
        $this->restoreSheetDraft();
    }

    /**
     * The reply to a commit in the sheet, from its page's reply bar, with what was written there. The draft was just
     * handed over, so it isn't marked as restored.
     */
    public function replyInSheet(string $id): void
    {
        $commit = $this->shownCommit($id);

        if ($commit === null || $commit->isDeleted()) {
            return;
        }

        $this->forgetCommitSheet();
        $this->sheetCommit = $commit;
        $this->sheetView = 'reply';
        $this->sheetOpen = true;
        $this->restoreSheetDraft();
        $this->sheetRestored = false;
    }

    /**
     * A new commit in the sheet, with nothing quoted.
     */
    public function composeInSheet(): void
    {
        $this->forgetCommitSheet();
        $this->sheetView = 'compose';
        $this->sheetOpen = true;
        $this->restoreSheetDraft();
    }

    /**
     * Stashes the draft as it changes (see stashSheetDraft()).
     */
    public function updatedSheetBody(): void
    {
        $this->typeIssueCommand();
        $this->stashSheetDraft();
    }

    /**
     * The web's drop, beside a restored draft: it's thrown away, photos and poll too, and the sheet starts again
     * empty.
     */
    public function dropSheetStash(): void
    {
        $this->sheetBody = '';
        $this->forgetAttachments(discardPhotos: true);
        $this->stashSheetDraft();
    }

    /**
     * Commits what's written in the sheet, quoting its commit if it's a quote, or replying to it, with its photos or
     * its poll. The sheet goes at once, and the commit shows staged where the screen lists your new ones, while it
     * goes to devnull from the outbox (see SyncsOutbox). The photos go with it, so closing the sheet keeps them.
     */
    public function commitFromSheet(): void
    {
        $parent = $this->sheetView === 'reply' ? $this->sheetCommit : null;
        $quoted = $this->sheetView === 'quote' ? $this->sheetCommit : null;
        $stashName = $this->sheetStashName();
        $body = trim($this->sheetBody);
        $me = $this->me();

        if (! $this->sheetOpen || $me === null || $stashName === null || $this->sheetIssue !== null || ! Draft::canCommit($body, $this->sheetImages, $this->sheetPoll)) {
            return;
        }

        $write = app(Outbox::class)->add(PendingWrite::Commit, $parent?->id ?? $quoted?->id, [
            'body' => $body,
            'parent_id' => $parent?->id,
            'quoted_commit_id' => $quoted?->id,
            'poll' => $this->sheetPollPayload(),
            'images' => PendingWrite::imagePayload($this->sheetImages),
            'stash' => $stashName,
            'composer' => $this->sheetView,
        ]);

        if ($write === null) {
            return;
        }

        app(Stash::class)->put($me->id, $stashName, new StashedDraft);
        $this->sheetImages = [];
        $this->closeCommitSheet();
        $this->showCommitted($write->stagedCommit($me));
        $this->sendFromOutbox($write);
    }

    /**
     * Done, after a report, and Cancel, in the composer's bar: the sheet goes. A draft in it stays stashed, as when
     * it's swiped away, so this mustn't forget it. A screen whose composer handed it the draft takes it back now,
     * since Android never says when a sheet closed from here has gone (see $sheetClosing).
     */
    public function hideCommitSheet(): void
    {
        $this->closeCommitSheet();
        $this->commitSheetGone($this->sheetView);
    }

    /**
     * iOS says the sheet has gone, swiped away or closed from here. What was drafted in it stays stashed.
     */
    public function commitSheetClosed(): void
    {
        $view = $this->sheetView;
        $held = $this->reopenAfterSheet;

        $this->sheetClosing = false;
        $this->reopenAfterSheet = null;
        $this->forgetCommitSheet();
        $this->commitSheetGone($view);

        $write = $held === null ? null : app(Outbox::class)->find($held);

        if ($write !== null && $write->isCommit() && $write->isFailed()) {
            $this->openFailedCommit($write);
        }
    }

    /**
     * A commit you just made, staged until devnull has it, for a screen to put where it lists your new ones. Most
     * don't.
     */
    protected function showCommitted(Commit $commit): void
    {
        //
    }

    /**
     * The sheet has gone, after showing $view. A screen whose own composer handed it a draft takes it back.
     */
    protected function commitSheetGone(string $view): void
    {
        //
    }

    /**
     * A commit devnull turned down goes back into the sheet it was written in,
     * on this screen, if the sheet's free: a new commit on the screen that
     * opens it (see opensComposer()), and a quote or a reply in the sheet
     * where the commit it was for is showing.
     *
     * When devnull answers at once, as it does with no connection, the sheet
     * the commit was sent from may still be sliding away on iOS, and a sheet
     * opened now would go with it when it's gone. So it waits until iOS says the sheet
     * has gone (see commitSheetClosed()), and answers that it didn't open,
     * so the toast still offers Edit.
     */
    protected function reopenFailedCommit(PendingWrite $write): bool
    {
        if ($this->sheetOpen || ($write->composer() === 'compose' && ! $this->opensComposer())) {
            return false;
        }

        if ($this->sheetClosing) {
            $this->reopenAfterSheet = $write->id;

            return false;
        }

        return $this->openFailedCommit($write);
    }

    /**
     * Edit, on the toast of a commit devnull turned down: its sheet, here if
     * the commit it quotes or replies to is showing, or the commit's own page.
     * A new commit's sheet opens anywhere it can.
     */
    protected function editFailedCommit(PendingWrite $write): void
    {
        if ($this->openFailedCommit($write)) {
            return;
        }

        $for = $write->parentId() ?? $write->quotedCommitId();

        if ($for !== null) {
            $this->openInTab('/commits/'.$for, ['edit' => $write->id]);
        }
    }

    /**
     * Closes the sheet from here. On iOS it slides away until iOS says it's
     * gone. Android never says so for a sheet closed from here (see
     * $sheetClosing).
     */
    private function closeCommitSheet(): void
    {
        $this->sheetClosing = $this->sheetClosing || ($this->sheetOpen && System::isIos());
        $this->sheetOpen = false;
    }

    /**
     * Whether this screen has the button for a new commit, which a new commit devnull turned down goes back to.
     */
    protected function opensComposer(): bool
    {
        return false;
    }

    /**
     * Opens the sheet a commit devnull turned down was written in, with everything it had (see restoreSheetDraft()).
     */
    private function openFailedCommit(PendingWrite $write): bool
    {
        $for = $write->parentId() ?? $write->quotedCommitId();

        match ($write->composer()) {
            'compose' => $this->composeInSheet(),
            'quote' => $for === null ? null : $this->quoteCommit($for),
            'reply' => $for === null ? null : $this->replyInSheet($for),
            default => null,
        };

        return $this->sheetOpen && $this->sheetStashName() === $write->stashName();
    }

    private function canQuote(Commit $commit): bool
    {
        $viewer = $this->me();

        return $viewer !== null && ! $commit->isDeleted() && ! $commit->isStaged() && ($commit->isApproved() || $commit->author->id === $viewer->id);
    }

    private function canReport(Commit $commit): bool
    {
        $viewer = $this->me();

        return $viewer !== null && ! $commit->isDeleted() && $commit->isApproved() && $commit->author->id !== $viewer->id;
    }

    private function forgetCommitSheet(): void
    {
        $this->sheetOpen = false;
        $this->sheetCommit = null;
        $this->sheetView = 'report';
        $this->reportReason = '';
        $this->reportDetails = '';
        $this->sheetBody = '';
        $this->sheetRestored = false;
        $this->sheetError = null;
        $this->forgetAttachments();
        $this->forgetIssueDraft();
    }

    /**
     * The draft the sheet stashes, by the web's name for it, or null when it isn't a composer.
     */
    private function sheetStashName(): ?string
    {
        return match (true) {
            $this->sheetView === 'compose' => 'commit',
            $this->sheetView === 'quote' && $this->sheetCommit !== null => 'quote-'.$this->sheetCommit->id,
            $this->sheetView === 'reply' && $this->sheetCommit !== null => 'reply-'.$this->sheetCommit->id,
            default => null,
        };
    }

    /**
     * Stashes the draft as it changes: what's written, its photos and its
     * poll. With nothing left in it, it's forgotten, and isn't marked as
     * restored any more.
     */
    protected function stashSheetDraft(): void
    {
        $name = $this->sheetStashName();

        if (! $this->sheetOpen || $name === null) {
            return;
        }

        $draft = new StashedDraft($this->sheetBody, $this->sheetImages, $this->sheetView === 'reply' ? null : $this->sheetPoll, $this->sheetPollLength);
        app(Stash::class)->put($this->me()?->id, $name, $draft);

        if ($draft->isEmpty()) {
            $this->sheetRestored = false;
        }
    }

    /**
     * The sheet starts from its stashed draft, with its photos and poll. A
     * commit devnull turned down that was written under it comes back whole,
     * and leaves the outbox: from here it's a draft again.
     */
    private function restoreSheetDraft(): void
    {
        $name = $this->sheetStashName();
        $failed = $name === null ? null : app(Outbox::class)->failedDraft($name);

        if ($failed !== null) {
            $this->takeFailedCommit($failed);
        }

        $draft = $name === null ? new StashedDraft : app(Stash::class)->get($this->me()?->id, $name);

        $this->sheetBody = $draft->body;
        $this->sheetImages = $draft->images;
        $this->sheetPoll = $this->sheetView === 'reply' ? null : $draft->poll;
        $this->sheetPollLength = $draft->pollLength;
        $this->sheetRestored = ! $draft->isEmpty();
    }

    private function takeFailedCommit(PendingWrite $write): void
    {
        $poll = $write->poll();

        app(Stash::class)->put($this->me()?->id, (string) $write->stashName(), new StashedDraft(
            $write->body(),
            $write->images(),
            $poll === null || $this->sheetView === 'reply' ? null : $poll['options'],
            $poll === null ? PollDraft::DefaultLength : (string) (array_search($poll['duration'], PollDraft::Lengths, true) ?: PollDraft::DefaultLength),
        ));
        app(Outbox::class)->forget($write);
    }

    /**
     * Like attempt(), but a failure shows in the sheet rather than where the screen prints its errors.
     *
     * @template TResult
     *
     * @param  Closure(Client): TResult  $call
     * @return TResult|null
     */
    private function attemptInSheet(Closure $call): mixed
    {
        $screenError = $this->error;
        $this->sheetError = null;

        $result = $this->attempt($call);

        if ($result === null) {
            $this->sheetError = $this->error;
            $this->error = $screenError;
        }

        return $result;
    }
}
