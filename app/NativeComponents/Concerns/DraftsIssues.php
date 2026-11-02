<?php

namespace App\NativeComponents\Concerns;

use App\Devnull\Client;
use App\Devnull\Data\StashedDraft;
use App\Devnull\DraftImages;
use App\Devnull\Exceptions\ApiException;
use App\Devnull\IssueCommand;
use App\Devnull\SiteLink;
use App\Devnull\Stash;
use App\Devnull\Toasts;
use Illuminate\Support\Facades\Cache;
use Native\Mobile\Facades\Browser;

/**
 * The web's `/feature` and `/bug` commands, in the compose sheet (see
 * OpensCommitSheet), as the web's composer has them for a new commit. Quotes
 * and replies don't, on the web or here.
 *
 * Typing a command and a space at the start of the draft turns it into an
 * issue draft, without the command: the command's badge goes above the
 * field, which takes the command's placeholder, and Open issue takes
 * Commit's place. The badge's X turns it back into a commit, with what you
 * wrote.
 *
 * While the draft is only a slash and the start of a command, the web opens a
 * menu of the commands it could become, but only while the composer has
 * focus (#528), so a lone slash restored from the stash doesn't open it.
 * EDGE tells PHP nothing about focus, so here the menu waits until you've
 * typed in the sheet since it opened: the field is focused when the sheet
 * opens, and it's the only place in the sheet to type. A restored slash
 * opens the menu at your next keystroke. Tapping a command starts the issue
 * with an empty draft, as picking one does on the web.
 *
 * A plain draft that mentions a bug or a feature gets the web's hint under
 * it, which offers the command with the draft kept. Its X stops the hints
 * on this phone, as the web's stops them in that browser.
 *
 * Opening the issue waits on GitHub, so it's sent in the background while the
 * button spins (see WritesInBackground). Once it's open the draft goes, with
 * any photos it had, and the sheet says "Opened issue #123." with View issue,
 * as the web's toast does, or a toast does, with View issue as its button, if
 * the sheet was swiped away by then (see SyncsOutbox::toastActionPressed()).
 * When it isn't, the draft stays an issue, with why under it: devnull's own
 * words when it sends them, else the web's, which say to check upstream when
 * the issue might have been opened anyway.
 */
trait DraftsIssues
{
    /**
     * Where this phone remembers that the hints were dismissed.
     */
    public const string IssueHintDismissedKey = 'devnull.issue-hint-dismissed';

    /**
     * The command the sheet's draft is an issue for, 'feature' or 'bug', or null while it's a commit.
     */
    public ?string $sheetIssue = null;

    /**
     * Whether you've typed in the sheet since it opened, which opens the command menu.
     */
    public bool $sheetTyped = false;

    /**
     * Whether the hints were dismissed on this phone.
     */
    public bool $issueHintsDismissed = false;

    /**
     * Whether the issue is on its way to GitHub.
     */
    public bool $openingIssue = false;

    /**
     * The issue the sheet just opened, as devnull answered: its number and where it is.
     */
    public ?int $openedIssueNumber = null;

    public ?string $openedIssueUrl = null;

    /**
     * A command picked from the menu: the issue starts empty, as on the web.
     */
    public function pickIssueCommand(string $command): void
    {
        if ($this->canDraftIssue() && $this->sheetIssue === null && ($picked = IssueCommand::tryFrom($command)) !== null) {
            $this->sheetIssue = $picked->value;
            $this->sheetBody = '';
            $this->updatedSheetBody();
        }
    }

    /**
     * The hint's Use /bug or Use /feature: the draft becomes that issue as it is.
     */
    public function useIssueCommand(string $command): void
    {
        if ($this->canDraftIssue() && $this->sheetIssue === null && ($used = IssueCommand::tryFrom($command)) !== null) {
            $this->sheetIssue = $used->value;
        }
    }

    /**
     * The badge's X: back to a commit, with what you wrote.
     */
    public function cancelIssue(): void
    {
        if (! $this->openingIssue) {
            $this->sheetIssue = null;
            $this->sheetError = null;
        }
    }

    public function dismissIssueHint(): void
    {
        Cache::forever(self::IssueHintDismissedKey, true);
        $this->issueHintsDismissed = true;
    }

    /**
     * Sends the issue draft to devnull, which has its GitHub App open it upstream, crediting you.
     */
    public function openIssueFromSheet(): void
    {
        $command = $this->sheetIssue === null ? null : IssueCommand::tryFrom($this->sheetIssue);
        $body = trim($this->sheetBody);

        if ($command === null || $this->openingIssue || ! $this->canDraftIssue() || ! IssueCommand::fits($body)) {
            return;
        }

        $this->openingIssue = true;
        $this->sheetError = null;

        $this->writeInBackground('POST', Client::IssuesPath, ['command' => $command->command(), 'body' => $body], function (array $json): void {
            $number = is_int($json['number'] ?? null) ? $json['number'] : null;
            $url = is_string($json['url'] ?? null) ? $json['url'] : null;
            $stash = app(Stash::class);
            app(DraftImages::class)->discard($stash->get($this->me()?->id, 'commit')->images);
            $stash->put($this->me()?->id, 'commit', new StashedDraft);

            // Swiped away while it was on its way, so a toast says it, with View issue as its button.
            if (! $this->sheetOpen) {
                Toasts::done("Opened issue #{$number}.", $url === null ? null : ['label' => 'View issue', 'id' => 'view-issue', 'data' => ['url' => $url]]);

                return;
            }

            $this->forgetIssueDraft();
            $this->sheetBody = '';
            $this->sheetRestored = false;
            $this->openedIssueNumber = $number;
            $this->openedIssueUrl = $url;
            $this->sheetView = 'issued';
        }, function (string $message, ?ApiException $exception = null): void {
            $this->openingIssue = false;
            $this->sheetError = match (true) {
                $exception?->status === 0 => 'Connection lost. Check upstream before retrying. Your draft is safe.',
                // A proxy's own page for a 502 or 504 has no code, and the issue may be open anyway.
                $exception !== null && $exception->status >= 500 && $exception->code() === null => 'GitHub did not confirm the issue. Check upstream before retrying. Your draft is safe.',
                default => $message,
            };
        });
    }

    /**
     * View issue, beside "Opened issue #123.", as the web's toast offers it.
     */
    public function viewOpenedIssue(): void
    {
        if (SiteLink::isWebUrl($this->openedIssueUrl)) {
            Browser::inApp($this->openedIssueUrl);
        }
    }

    /**
     * Turns the draft into an issue when it starts with a command and a space or a new line, as on the web.
     */
    protected function typeIssueCommand(): void
    {
        $this->sheetTyped = true;

        if ($this->canDraftIssue() && $this->sheetIssue === null && ($typed = IssueCommand::typed($this->sheetBody)) !== null) {
            [$command, $this->sheetBody] = $typed;
            $this->sheetIssue = $command->value;
        }
    }

    /**
     * Only a new commit can become an issue, as on the web.
     */
    protected function canDraftIssue(): bool
    {
        return $this->sheetOpen && $this->sheetView === 'compose' && ! $this->scheduling;
    }

    /**
     * The sheet starts again as a commit, with the menu shut until you type.
     */
    protected function forgetIssueDraft(): void
    {
        $this->issueHintsDismissed = Cache::get(self::IssueHintDismissedKey) === true;
        $this->sheetIssue = null;
        $this->sheetTyped = false;
        $this->openingIssue = false;
        $this->openedIssueNumber = null;
        $this->openedIssueUrl = null;
    }
}
