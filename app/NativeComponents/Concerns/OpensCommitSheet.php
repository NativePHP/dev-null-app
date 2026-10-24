<?php

namespace App\NativeComponents\Concerns;

use App\Devnull\Client;
use App\Devnull\Data\Commit;
use App\Devnull\Data\Draft;
use App\Devnull\ReportReason;
use App\Devnull\Stash;
use Closure;

/**
 * The sheet under a commit (see partials/commit-sheet). Report commit, in the
 * More menu, opens it as the web's Report dialog. As on the web, only someone
 * else's published commit can be reported, so only their menus have it.
 *
 * Quote opens the same sheet as a composer, over the commit being quoted, as
 * the web's Quote dialog. The Log opens it with nothing to quote, for a new
 * commit of your own, so you can write one without leaving where you are in
 * it. Once devnull has the commit the sheet goes, and a screen that lists
 * your new commits puts it in place (see showCommitted()).
 *
 * What you write in it is stashed as you type, as on the web (see Stash): a
 * quote per commit quoted, and a new commit under the web's composer draft.
 * Opened again, it starts from the draft, marked as restored. It's forgotten
 * once it's sent.
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

    public bool $sheetPosting = false;

    /**
     * Whether the sheet opened on a stashed draft, which it marks until you empty it or drop it.
     */
    public bool $sheetRestored = false;

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
     * Stashes the draft as it changes. An empty field forgets it, and isn't restored any more.
     */
    public function updatedSheetBody(): void
    {
        $this->typeIssueCommand();
        $name = $this->sheetStashName();

        if (! $this->sheetOpen || $name === null) {
            return;
        }

        app(Stash::class)->put($this->me()?->id, $name, $this->sheetBody);

        if ($this->sheetBody === '') {
            $this->sheetRestored = false;
        }
    }

    /**
     * The web's drop, beside a restored draft: it's thrown away, and the field starts again empty.
     */
    public function dropSheetStash(): void
    {
        $this->sheetBody = '';
        $this->updatedSheetBody();
    }

    /**
     * Commits what's written in the sheet, quoting its commit if it's a quote, or replying to it, with its photos or
     * its poll. The sheet goes once devnull has it, or keeps the draft with why it didn't take it.
     */
    public function commitFromSheet(): void
    {
        $parent = $this->sheetView === 'reply' ? $this->sheetCommit : null;
        $quoted = $this->sheetView === 'quote' ? $this->sheetCommit : null;
        $stashName = $this->sheetStashName();
        $body = trim($this->sheetBody);

        if (! $this->sheetOpen || $this->sheetPosting || $stashName === null || $this->sheetIssue !== null || ! Draft::canCommit($body, $this->sheetImages, $this->sheetPoll)) {
            return;
        }

        $sent = function (Commit $commit) use ($stashName): void {
            app(Stash::class)->put($this->me()?->id, $stashName, '');
            $this->sheetOpen = false;
            $this->showCommitted($commit);
        };

        if ($this->sheetImages !== []) {
            $this->commitPhotosFromSheet($body, $parent, $quoted, $sent);

            return;
        }

        $this->sheetPosting = true;

        try {
            $commit = $this->attemptInSheet(fn (Client $devnull): Commit => $devnull->createCommit($body, $parent?->id, $quoted?->id, $this->sheetPollPayload()));
        } finally {
            $this->sheetPosting = false;
        }

        if ($commit !== null) {
            $sent($commit);
        }
    }

    /**
     * Done, after a report, and Cancel, in the composer's bar: the sheet goes. A draft in it stays stashed, as when
     * it's swiped away, so this mustn't forget it.
     */
    public function hideCommitSheet(): void
    {
        $this->sheetOpen = false;
    }

    /**
     * iOS says the sheet has gone, swiped away or closed from here. What was drafted in it stays stashed.
     */
    public function commitSheetClosed(): void
    {
        $view = $this->sheetView;

        $this->forgetCommitSheet();
        $this->commitSheetGone($view);
    }

    /**
     * A commit you just made, for a screen to put where it lists your new ones. Most don't.
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

    private function restoreSheetDraft(): void
    {
        $name = $this->sheetStashName();

        $this->sheetBody = $name === null ? '' : app(Stash::class)->get($this->me()?->id, $name);
        $this->sheetRestored = $this->sheetBody !== '';
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
