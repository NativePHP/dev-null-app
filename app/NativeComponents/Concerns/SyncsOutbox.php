<?php

namespace App\NativeComponents\Concerns;

use App\Devnull\Client;
use App\Devnull\Data\Commit;
use App\Devnull\Data\StashedDraft;
use App\Devnull\DraftImages;
use App\Devnull\Exceptions\KeychainUnavailable;
use App\Devnull\Exceptions\Unauthenticated;
use App\Devnull\FetchInBackground;
use App\Devnull\Outbox;
use App\Devnull\ResponseCache;
use App\Devnull\SignedRequest;
use App\Devnull\SiteLink;
use App\Devnull\Stash;
use App\Devnull\Toasts;
use App\Devnull\VoiceNotes;
use App\Models\PendingWrite;
use Native\Mobile\Attributes\On;
use Native\Mobile\Facades\Browser;
use Throwable;
use Victorycodedev\ToastKit\Events\ToastActionPressed;

/**
 * Stars, commits, replies and Remotes messages that feel instant: each is
 * written to the outbox on the phone first (see Outbox), the screen shows it
 * at once, and it goes to devnull from the background straight away, signed
 * here on the UI thread where the Keychain is, as WritesInBackground signs.
 * Nothing on the tap waits on devnull. Only a star or an unstar waits, for
 * the one before it of the same commit (see queueStar()).
 *
 * devnull's answer comes back to whichever screen is showing by then, not
 * only the one it was made on, so a commit that doesn't go is heard of even
 * after you've moved on. Once devnull has it, the outbox lets it go, the kept
 * copies take devnull's answer (see ResponseCache), and the screen takes it
 * too: the star's real count, the real commit in place of the staged one.
 *
 * When devnull turns it down, or can't be reached, a toast says why in its
 * words and the screen goes back to where you can try again:
 *
 * - A star comes back off the commit, here and in every kept copy, and the
 *   toast offers Retry.
 * - A commit or a reply leaves the screen, and its draft goes back where you
 *   wrote it: the text in its stash, and the photos and poll with the failed
 *   write in the outbox, for the composer to take when it opens. The screen
 *   that owns the composer opens it again with the lot. Anywhere else the
 *   toast offers Edit, which opens it here when it can, or the commit the
 *   reply or quote was for.
 * - A message stays in its thread as rejected, with its Retry, and the toast
 *   offers Retry too. Its photos or voice note stay on the phone with it
 *   until it goes.
 *
 * A 401 signs the phone out, as any request does, which empties the outbox.
 *
 * A screen takes part through the hooks it has (see showStar(),
 * showCommitWent(), showCommitTurnedDown(), reopenFailedCommit(),
 * editFailedCommit() and showMessage()). Each does nothing here.
 */
trait SyncsOutbox
{
    /**
     * Stars or unstars a commit: every kept copy shows it at once, and devnull
     * is told from the background, once any star or unstar of it before this
     * one has gone (see Outbox::starOnItsWay()). The screen showing it flips
     * its own.
     */
    protected function queueStar(string $id, bool $starred): void
    {
        $cache = app(ResponseCache::class);
        $outbox = app(Outbox::class);

        // An unstar takes the commit off your kept starred commits, so it keeps a copy to put back if devnull says no.
        $write = $outbox->add($starred ? PendingWrite::Star : PendingWrite::Unstar, $id, $starred ? [] : ['kept' => $cache->keptCommit($id)]);

        $cache->starChanged($id, $starred);

        if ($write !== null && ! $outbox->starOnItsWay($id)) {
            $this->sendFromOutbox($write);
        }
    }

    /**
     * Sends the star or unstar of the commit that was waiting for $write to
     * go, knowing whether devnull has the commit starred now, to put it back
     * to if devnull turns this one down too.
     */
    private function sendNextStar(PendingWrite $write, bool $starred): void
    {
        $next = app(Outbox::class)->nextStar((string) $write->target);

        if ($next !== null) {
            $next->forceFill(['payload' => [...$next->payload, 'was_starred' => $starred]])->save();
            $this->sendFromOutbox($next);
        }
    }

    /**
     * Sends a write from the outbox, from the background. Its answer comes
     * back as Outbox::Answered to whichever screen is showing (see
     * outboxAnswered()). The fake that runs tasks in tests has no screen to
     * deliver to, so it answers this one directly.
     */
    protected function sendFromOutbox(PendingWrite $write): void
    {
        try {
            $request = $this->signWrite($write);
        } catch (KeychainUnavailable $exception) {
            $this->writeTurnedDown($write, $exception->getMessage());

            return;
        }

        $task = FetchInBackground::dispatch($request->url, $request->headers, $request->method, $request->body, false, null, $request->contentType);
        $taskId = $task->getId();

        app(Outbox::class)->sending($write, $taskId);

        $task->timeout($write->images() === [] && $write->voice() === null ? Outbox::Timeout : Outbox::UploadTimeout)
            ->shared(Outbox::Answered)
            ->finished(fn (mixed $result) => $this->outboxAnswered($taskId, 'finished', $result))
            ->failed(fn (Throwable $exception) => $this->outboxAnswered($taskId, 'failed', null, $exception->getMessage()))
            ->start();
    }

    /**
     * devnull answered a write from the outbox, or it couldn't be sent.
     */
    #[On(Outbox::Answered)]
    public function outboxAnswered(string $id = '', string $status = 'finished', mixed $result = null, ?string $message = null): void
    {
        $write = app(Outbox::class)->forTask($id);

        if ($write === null) {
            return;
        }

        // The task gave up waiting, so devnull may have a commit without having said so.
        if ($status === 'failed') {
            $this->writeTurnedDown($write, $write->isCommit() ? Outbox::NoAnswer : 'fatal: '.($message ?? 'the request did not finish.'));

            return;
        }

        $exception = FetchInBackground::failureOf($result);

        if ($exception instanceof Unauthenticated) {
            $this->signOut($exception);

            return;
        }

        $exception === null
            ? $this->writeWent($write, FetchInBackground::jsonOf($result))
            : $this->writeTurnedDown($write, $exception->firstError());
    }

    /**
     * What was left in the outbox when the app last closed. Stars and
     * messages go again, since devnull can't do either twice. A commit might
     * have reached devnull, and has nothing it could be known by, so it's
     * turned down with why, for you to check and send again yourself. A star
     * or an unstar of a commit with one already on its way waits for it.
     */
    protected function sendLeftovers(): void
    {
        $outbox = app(Outbox::class);

        foreach ($outbox->leftovers() as $write) {
            if ($write->isStar() && $outbox->starOnItsWay((string) $write->target)) {
                continue;
            }

            $write->canBeSentAgain()
                ? $this->sendFromOutbox($write)
                : $this->writeTurnedDown($write, Outbox::ClosedBeforeAnswer, reopen: false);
        }
    }

    /**
     * The commits on their way that this screen would show, as they stand in for themselves (see
     * PendingWrite::stagedCommit()): replies to $parentId, or with null, new commits and quotes. Newest first.
     *
     * @return list<Commit>
     */
    protected function stagedCommits(?string $parentId): array
    {
        $me = $this->me();

        if ($me === null) {
            return [];
        }

        return app(Outbox::class)->commitsUnder($parentId)
            ->reverse()
            ->map(fn (PendingWrite $write): Commit => $write->stagedCommit($me))
            ->values()
            ->all();
    }

    /**
     * A toast's button: Retry, Edit or View issue, pressed on whichever screen is showing.
     *
     * @param  array<string, mixed>  $data
     */
    #[On(ToastActionPressed::class)]
    public function toastActionPressed(string $toastId = '', string $actionId = '', array $data = []): void
    {
        match ($actionId) {
            'retry-star' => $this->retryStar($data),
            'retry-message' => $this->retryMessage($data),
            'edit' => $this->editFromToast($data),
            'view-issue' => $this->viewIssueFromToast($data),
            default => null,
        };
    }

    /**
     * The star devnull took: the commit takes devnull's count, unless you've starred or unstarred it again since,
     * which has the last word.
     *
     * @param  array<string, mixed>  $json
     */
    private function writeWent(PendingWrite $write, array $json): void
    {
        $outbox = app(Outbox::class);
        $data = is_array($json['data'] ?? null) ? $json['data'] : [];

        if ($write->isStar()) {
            $later = $outbox->starsAfter($write);
            $outbox->forget($write);
            $this->sendNextStar($write, ($data['starred'] ?? $write->kind === PendingWrite::Star) === true);

            if (! $later && $data !== []) {
                app(ResponseCache::class)->commitReacted($data);
                app(ResponseCache::class)->commitStarred($data);
                $this->showStar((string) $write->target, ($data['starred'] ?? false) === true, Commit::fromArray($data));
            }

            return;
        }

        $outbox->forget($write);
        app(DraftImages::class)->discard($write->images());
        app(VoiceNotes::class)->discard($write->voice());

        if ($write->isCommit()) {
            app(ResponseCache::class)->commitAdded($data);
            $this->showCommitWent($write, Commit::fromArray($data));

            return;
        }

        $this->showMessage($write, $json);
    }

    /**
     * devnull turned the write down, or it couldn't go: the screen and the kept copies go back, and a toast says why.
     */
    private function writeTurnedDown(PendingWrite $write, string $error, bool $reopen = true): void
    {
        $outbox = app(Outbox::class);

        if ($write->isStar()) {
            $later = $outbox->starsAfter($write);
            $outbox->forget($write);
            $this->sendNextStar($write, $write->wasStarred());
            $starred = $write->kind === PendingWrite::Star;

            // A later star or unstar has the last word, and one devnull already had as you left it has nothing to put back.
            if ($later || $write->wasStarred() === $starred) {
                return;
            }

            app(ResponseCache::class)->starChanged((string) $write->target, $write->wasStarred(), is_array($write->payload['kept'] ?? null) ? $write->payload['kept'] : null);
            $this->showStar((string) $write->target, $write->wasStarred());

            Toasts::failed($error, ['label' => 'Retry', 'id' => 'retry-star', 'data' => ['commit' => $write->target, 'starred' => $starred]]);

            return;
        }

        $outbox->failed($write, $error);

        if ($write->isMessage()) {
            $this->showMessage($write, null);

            Toasts::failed($error, ['label' => 'Retry', 'id' => 'retry-message', 'data' => ['write' => $write->id]]);

            return;
        }

        $this->turnDownCommit($write, $error, $reopen);
    }

    /**
     * A commit devnull turned down leaves the screen, and goes back to its
     * draft: its text into its stash, unless you've started another there,
     * and the rest stays with the failed write for the composer to take. Its
     * composer opens again if it's this screen's, unless it's left over from
     * when the app closed, which waits for you to press Edit.
     */
    private function turnDownCommit(PendingWrite $write, string $error, bool $reopen): void
    {
        $name = $write->stashName();
        $stash = app(Stash::class);

        if ($name !== null && $stash->get($this->me()?->id, $name)->isEmpty()) {
            $stash->put($this->me()?->id, $name, new StashedDraft($write->body()));
        }

        $this->showCommitTurnedDown($write);

        if ($reopen && $this->reopenFailedCommit($write)) {
            Toasts::failed($error);

            return;
        }

        Toasts::failed($error, ['label' => 'Edit', 'id' => 'edit', 'data' => ['write' => $write->id]]);
    }

    /**
     * Signs a write as devnull takes it, here on the UI thread.
     */
    private function signWrite(PendingWrite $write): SignedRequest
    {
        $devnull = app(Client::class);
        $commit = 'commits/'.rawurlencode((string) $write->target).'/star';

        return match ($write->kind) {
            PendingWrite::Star => $devnull->signedWrite('POST', $commit),
            PendingWrite::Unstar => $devnull->signedWrite('DELETE', $commit),
            PendingWrite::Message => $devnull->signedMessage((string) $write->target, $write->body(), $write->client_id, $write->replyToId(), $write->images(), $write->voice()),
            default => $write->images() === []
                ? $devnull->signedCommit($write->body(), $write->parentId(), $write->quotedCommitId(), $write->poll())
                : $devnull->signedCommitWithImages($write->body(), $write->images(), $write->parentId(), $write->quotedCommitId()),
        };
    }

    /**
     * Retry on a star's toast: the star goes again as you meant it.
     *
     * @param  array<string, mixed>  $data
     */
    private function retryStar(array $data): void
    {
        if (! is_string($data['commit'] ?? null) || ! is_bool($data['starred'] ?? null)) {
            return;
        }

        $this->showStar($data['commit'], $data['starred']);
        $this->queueStar($data['commit'], $data['starred']);
    }

    /**
     * Retry on a message's toast, as the thread's own Retry does: it goes again under the same client id.
     *
     * @param  array<string, mixed>  $data
     */
    private function retryMessage(array $data): void
    {
        $write = is_numeric($data['write'] ?? null) ? app(Outbox::class)->find((int) $data['write']) : null;

        if ($write !== null) {
            $this->resendMessage($write);
        }
    }

    /**
     * Sends a message devnull turned down again, under the same client id, staged once more while it goes.
     */
    protected function resendMessage(PendingWrite $write): void
    {
        if (! $write->isMessage() || ! $write->isFailed()) {
            return;
        }

        $write->status = PendingWrite::Queued;
        $this->showMessage($write, null);
        $this->sendFromOutbox($write);
    }

    /**
     * Edit on a commit's toast: its composer again, with everything it had.
     *
     * @param  array<string, mixed>  $data
     */
    private function editFromToast(array $data): void
    {
        $write = is_numeric($data['write'] ?? null) ? app(Outbox::class)->find((int) $data['write']) : null;

        if ($write !== null && $write->isCommit() && $write->isFailed()) {
            $this->editFailedCommit($write);
        }
    }

    /**
     * View issue on the toast of an issue that opened once its sheet had gone (see DraftsIssues).
     *
     * @param  array<string, mixed>  $data
     */
    private function viewIssueFromToast(array $data): void
    {
        $url = $data['url'] ?? null;

        if (is_string($url) && SiteLink::isWebUrl($url)) {
            Browser::inApp($url);
        }
    }

    /**
     * Your star on a commit changed: by you, by devnull turning it down, or as devnull counted it ($fresh).
     */
    protected function showStar(string $id, bool $starred, ?Commit $fresh = null): void {}

    /**
     * devnull has a commit you made: the real one takes the staged one's place.
     */
    protected function showCommitWent(PendingWrite $write, Commit $commit): void {}

    /**
     * devnull turned a commit down: the staged one leaves.
     */
    protected function showCommitTurnedDown(PendingWrite $write): void {}

    /**
     * Opens the composer a commit devnull turned down was written in, with
     * everything it had, when it's this screen's and it isn't busy, so you're
     * back where you were. Answers whether it did.
     */
    protected function reopenFailedCommit(PendingWrite $write): bool
    {
        return false;
    }

    /**
     * Edit, pressed for a commit devnull turned down: its composer, here when
     * this screen can open it. A screen with no composer opens the commit a
     * reply or a quote was for, whose page opens it. A new commit's draft
     * waits in the stash for the Log's compose button.
     */
    protected function editFailedCommit(PendingWrite $write): void
    {
        $for = $write->parentId() ?? $write->quotedCommitId();

        if ($for !== null && method_exists($this, 'openInTab')) {
            $this->openInTab('/commits/'.$for, ['edit' => $write->id]);
        }
    }

    /**
     * A message you pushed went, with devnull's answer, or didn't, with null.
     *
     * @param  array<string, mixed>|null  $json
     */
    protected function showMessage(PendingWrite $write, ?array $json): void {}
}
