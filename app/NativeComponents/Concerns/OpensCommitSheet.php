<?php

namespace App\NativeComponents\Concerns;

use App\Devnull\Client;
use App\Devnull\Data\Commit;
use App\Devnull\ReportReason;
use Closure;

/**
 * The sheet a commit's More button opens (see partials/commit-sheet): Share
 * link, which does what copy link does, and Report commit, which turns the
 * sheet into the web's Report dialog. As on the web, only someone else's
 * published commit can be reported, so only those have More.
 *
 * Quote opens the same sheet as a composer, over the commit being quoted, as
 * the web's Quote dialog. The Log opens it with nothing to quote, for a new
 * commit of your own, so you can write one without leaving where you are in
 * it. Once devnull has the commit the sheet goes, and a screen that lists
 * your new commits puts it in place (see showCommitted()).
 *
 * The sheet keeps its commit while it slides away, so it doesn't empty on the
 * way down, and forgets it and any draft once iOS says it's gone, however it
 * was closed. Share link waits for that too: iOS shows the share sheet over
 * the screen, and can't while this one is still on it.
 */
trait OpensCommitSheet
{
    /**
     * Whether the sheet is up, or on its way up.
     */
    public bool $sheetOpen = false;

    /**
     * The commit the sheet is for, as it was when it opened, until the sheet has gone.
     */
    public ?Commit $sheetCommit = null;

    /**
     * What the sheet shows: 'more' (Share link and Report commit), 'report' (the form), 'reported' (sent),
     * 'quote', or 'compose' (a new commit, with no commit of its own).
     */
    public string $sheetView = 'more';

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
     * Why devnull didn't take the report or the commit, shown in the sheet.
     */
    public ?string $sheetError = null;

    /**
     * The commit whose link to share once the sheet has gone.
     */
    public ?string $shareAfterSheet = null;

    /**
     * More, under someone else's published commit.
     */
    public function openCommitSheet(string $id): void
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
     * Share link: the sheet goes, and the share sheet comes up once it has (see commitSheetClosed()).
     */
    public function shareFromSheet(): void
    {
        $this->shareAfterSheet = $this->sheetCommit?->id;
        $this->sheetOpen = false;
    }

    /**
     * Report commit: the sheet becomes the report form.
     */
    public function startReport(): void
    {
        if ($this->sheetCommit !== null) {
            $this->sheetView = 'report';
            $this->sheetError = null;
        }
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
    }

    /**
     * A new commit in the sheet, with nothing quoted.
     */
    public function composeInSheet(): void
    {
        $this->forgetCommitSheet();
        $this->sheetView = 'compose';
        $this->sheetOpen = true;
    }

    /**
     * Commits what's written in the sheet, quoting its commit if it's a quote. The sheet goes once devnull has it,
     * or keeps the draft with why it didn't take it.
     */
    public function commitFromSheet(): void
    {
        $quoted = $this->sheetView === 'quote' ? $this->sheetCommit : null;
        $body = trim($this->sheetBody);

        if (($quoted === null && $this->sheetView !== 'compose') || mb_strlen($body) < Commit::MinLength || mb_strlen($body) > Commit::MaxLength) {
            return;
        }

        $commit = $this->attemptInSheet(fn (Client $devnull): Commit => $devnull->createCommit($body, quotedCommitId: $quoted?->id));

        if ($commit === null) {
            return;
        }

        $this->sheetOpen = false;
        $this->showCommitted($commit);
    }

    /**
     * Done: the sheet goes.
     */
    public function hideCommitSheet(): void
    {
        $this->sheetOpen = false;
    }

    /**
     * iOS says the sheet has gone, swiped away or closed from here. What was
     * drafted in it goes with it, and a link waiting to be shared is shared.
     */
    public function commitSheetClosed(): void
    {
        $share = $this->shareAfterSheet;

        $this->forgetCommitSheet();

        if ($share !== null) {
            $this->shareCommit($share);
        }
    }

    /**
     * A commit you just made, for a screen to put where it lists your new ones. Most don't.
     */
    protected function showCommitted(Commit $commit): void
    {
        //
    }

    private function canQuote(Commit $commit): bool
    {
        $viewer = $this->me();

        return $viewer !== null && ! $commit->isDeleted() && ($commit->isApproved() || $commit->author->id === $viewer->id);
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
        $this->sheetView = 'more';
        $this->reportReason = '';
        $this->reportDetails = '';
        $this->sheetBody = '';
        $this->sheetError = null;
        $this->shareAfterSheet = null;
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
