<?php

namespace App\NativeComponents\Concerns;

use App\Devnull\Client;
use App\Devnull\ReportReason;

/**
 * The sheet that reports a Remotes message or a person to devnull's
 * moderators (see partials/report-sheet), as the commit sheet reports a
 * commit: a reason, optional details, and Send report, which waits for a
 * reason. Once devnull has it, the sheet says so, with Done. If devnull says
 * no, why shows above Send report and the form stays as it was.
 *
 * Reports are private: the person reported isn't told, and nothing in the
 * thread or on the profile changes.
 */
trait ReportsToModerators
{
    public bool $reportingOpen = false;

    /**
     * What's being reported: 'message' or 'user', or '' when nothing is.
     */
    public string $reportingKind = '';

    /**
     * The message's id, or the person's handle.
     */
    public string $reportingId = '';

    /**
     * What the sheet quotes: the message's text, or the person's handle.
     */
    public string $reportingPreview = '';

    public string $reportingReason = '';

    public string $reportingDetails = '';

    public bool $reportingSent = false;

    public ?string $reportingError = null;

    /**
     * Opens the sheet for someone else's message.
     */
    protected function openMessageReport(int $id, string $preview): void
    {
        $this->openReport('message', (string) $id, $preview);
    }

    /**
     * Opens the sheet for someone else's profile.
     */
    protected function openUserReport(string $username): void
    {
        $this->openReport('user', $username, '@'.$username);
    }

    public function sendReporting(): void
    {
        $reason = ReportReason::tryFrom($this->reportingReason);

        if (! $this->reportingOpen || $this->reportingSent || $reason === null) {
            return;
        }

        $kind = $this->reportingKind;
        $id = $this->reportingId;
        $details = trim($this->reportingDetails);
        $screenError = $this->error;

        $sent = $this->attempt(function (Client $devnull) use ($kind, $id, $reason, $details): bool {
            $kind === 'message'
                ? $devnull->reportMessage((int) $id, $reason, $details)
                : $devnull->reportUser($id, $reason, $details);

            return true;
        });

        $this->reportingError = $sent === true ? null : $this->error;
        $this->error = $screenError;
        $this->reportingSent = $sent === true;
    }

    /**
     * Done, or the sheet dragged away. What it shows stays until the next
     * report opens, so it doesn't empty while it slides away, and iOS's late
     * dismiss after Done changes nothing.
     */
    public function closeReporting(): void
    {
        $this->reportingOpen = false;
    }

    private function openReport(string $kind, string $id, string $preview): void
    {
        $this->reportingKind = $kind;
        $this->reportingId = $id;
        $this->reportingPreview = $preview;
        $this->reportingReason = '';
        $this->reportingDetails = '';
        $this->reportingSent = false;
        $this->reportingError = null;
        $this->reportingOpen = true;
    }
}
