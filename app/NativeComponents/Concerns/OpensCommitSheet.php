<?php

namespace App\NativeComponents\Concerns;

use App\Devnull\Client;
use App\Devnull\Data\Commit;
use App\Devnull\Data\Draft;
use App\Devnull\Data\PollDraft;
use App\Devnull\Data\StashedDraft;
use App\Devnull\DraftImages;
use App\Devnull\Outbox;
use App\Devnull\ReportReason;
use App\Devnull\ResponseCache;
use App\Devnull\SignedRequest;
use App\Devnull\Stash;
use App\Devnull\Timezone;
use App\Devnull\Toasts;
use App\Models\PendingWrite;
use Carbon\CarbonImmutable;
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
 * A new commit can be scheduled instead, as the web's composer schedules one:
 * the clock button opens "Submit at" with the system's date and time picker,
 * in the phone's timezone (see Timezone), and Commit becomes Schedule commit.
 * Only you see it until it's due, on the Scheduled commits page it links to.
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
 * does (see AttachesToCommits), and suggests people to @mention, as the
 * web's does: devnull's people for a new commit, a reply or a quote (see
 * SuggestsMentions).
 *
 * The sheet keeps its commit while it slides away, so it doesn't empty on the
 * way down, and lets go of it once iOS says it's gone, however it was closed.
 */
trait OpensCommitSheet
{
    use AttachesToCommits;
    use DraftsIssues;
    use SuggestsMentions;
    use SyncsOutbox;

    /**
     * What the web says once a commit is scheduled.
     */
    public const string Scheduled = 'Commit scheduled. Only you can see it until it is submitted.';

    /**
     * What the web says of a time the timezone skips.
     */
    public const string SkippedTime = 'This time does not exist in your timezone. Choose another time.';

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
     * Whether the new commit is to be scheduled for later, as the web's clock button toggles it.
     */
    public bool $scheduling = false;

    /**
     * When to submit it, as the picker's wall clock in the phone's timezone (`2026-10-20T09:00`), or '' until one is
     * picked.
     */
    public string $scheduledAt = '';

    /**
     * Whether the time picked is one to schedule for: there is one, and the phone's timezone doesn't skip it.
     */
    public bool $hasScheduledTime = false;

    /**
     * Whether Scheduled commits → was tapped, so the list opens once the sheet has gone.
     */
    public bool $opensScheduledCommits = false;

    /**
     * Whether the commit being scheduled is on its way to devnull.
     */
    public bool $sendingSchedule = false;

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
     * The web's clock button, in a new commit's composer: it opens "Submit at" under the field, with no time picked,
     * or closes it, and the commit goes now again. Its photos or its poll go with it either way, as on the web.
     */
    public function toggleSchedule(): void
    {
        if ($this->sheetView !== 'compose' || $this->sheetIssue !== null) {
            return;
        }

        $this->scheduling = ! $this->scheduling;
        $this->scheduledAt = '';
        $this->hasScheduledTime = false;
        $this->sheetError = null;
    }

    /**
     * A time picked: the web says when the phone's timezone skips it, as on the night the clocks go forward.
     */
    public function updatedScheduledAt(): void
    {
        $this->hasScheduledTime = $this->scheduledTime() !== null;
        $this->sheetError = $this->scheduledAt === '' || $this->hasScheduledTime ? null : self::SkippedTime;
    }

    /**
     * Scheduled commits →, under "Submit at": the sheet goes, keeping the draft, and the list opens once it has.
     * Pushed while the sheet slides away, the list comes in without its title.
     */
    public function openScheduledCommits(): void
    {
        $this->opensScheduledCommits = true;
        $this->sheetOpen = false;
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
     * its poll, or schedules it (see scheduleFromSheet()). The sheet goes at once, and the commit shows staged where the screen lists your new ones, while it
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

        if ($this->scheduling && $this->sheetView === 'compose') {
            $this->scheduleFromSheet($body, $stashName);

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
     * The time picked to submit at, or null until one is, or when the phone's timezone skips it, like 01:30 on the
     * night the clocks go forward.
     */
    private function scheduledTime(): ?CarbonImmutable
    {
        if ($this->scheduledAt === '') {
            return null;
        }

        $at = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $this->scheduledAt, app(Timezone::class)->here());

        return $at instanceof CarbonImmutable && $at->format('Y-m-d\TH:i') === $this->scheduledAt ? $at : null;
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
        $opensScheduledCommits = $this->opensScheduledCommits;

        $this->sheetClosing = false;
        $this->reopenAfterSheet = null;
        $this->forgetCommitSheet();
        $this->commitSheetGone($view);

        $write = $held === null ? null : app(Outbox::class)->find($held);

        if ($write !== null && $write->isCommit() && $write->isFailed()) {
            $this->openFailedCommit($write);

            return;
        }

        if ($opensScheduledCommits) {
            $this->openInTab('/scheduled');
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
        $this->forgetMention('sheetBody');
    }

    /**
     * The composer's field, while it's up, suggests whom devnull suggests for a new commit, a reply to the sheet's
     * commit or a quote of it, as the web's composers ask.
     */
    protected function mentionFields(): array
    {
        if (! $this->sheetOpen || ! in_array($this->sheetView, ['compose', 'quote', 'reply'], true)) {
            return [];
        }

        $about = match ($this->sheetView) {
            'reply' => ['reply' => $this->sheetCommit?->id],
            'quote' => ['quote' => $this->sheetCommit?->id],
            default => [],
        };

        return ['sheetBody' => ['limit' => Commit::MaxRawLength, ...array_filter($about)]];
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

    /**
     * Schedules what's written for the time picked, with its photos or its poll, as the web schedules them. It goes
     * from the background, photos and all, while the bar's check spins. Once devnull has it the sheet goes, the
     * photos with it, and says so as the web does; otherwise it keeps the draft and says why.
     */
    private function scheduleFromSheet(string $body, string $stashName): void
    {
        $at = $this->scheduledTime();
        $me = $this->me();

        if ($at === null || $me === null || $this->sendingSchedule) {
            return;
        }

        $images = $this->sheetImages;
        $poll = $this->sheetPollPayload();
        $this->sendingSchedule = true;
        $this->sheetError = null;

        $this->sendInBackground(fn (Client $devnull): SignedRequest => $devnull->signedScheduledCommit($body, $at, $images, $poll), function () use ($me, $stashName, $images): void {
            $this->sendingSchedule = false;
            app(ResponseCache::class)->forget(Client::ScheduledCommitsPath);
            app(Stash::class)->put($me->id, $stashName, new StashedDraft);
            app(DraftImages::class)->discard($images);
            $this->sheetImages = [];
            $this->closeCommitSheet();
            Toasts::say(self::Scheduled);
        }, function (string $message): void {
            $this->sendingSchedule = false;
            $this->sheetError = $message;
        }, timeout: $images === [] ? 30 : 120);
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
        $this->forgetMention('sheetBody');
        $this->forgetAttachments();
        $this->forgetIssueDraft();
        $this->scheduling = false;
        $this->scheduledAt = '';
        $this->hasScheduledTime = false;
        $this->opensScheduledCommits = false;
        $this->sendingSchedule = false;
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
