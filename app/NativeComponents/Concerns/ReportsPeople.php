<?php

namespace App\NativeComponents\Concerns;

use App\Devnull\Client;
use App\Devnull\ReportReason;

/**
 * The sheet that reports a person to devnull's moderators (see
 * partials/person-report-sheet), as the web's Report dialog does. A report
 * from a chat is about the person: it says who, why and any details you add,
 * and sends nothing from the conversation. There's no way to report one
 * message, and an org has no one to report.
 */
trait ReportsPeople
{
    use FillsReportForm;

    /**
     * Whether the sheet is up, or on its way up.
     */
    public bool $personReportOpen = false;

    /**
     * Who the sheet reports. It stays while the sheet slides away, so the sheet doesn't empty as it goes.
     */
    public string $reportedUsername = '';

    /**
     * Whether devnull has the report, so the sheet says so.
     */
    public bool $personReported = false;

    /**
     * Why devnull didn't take the report, shown in the sheet.
     */
    public ?string $personReportError = null;

    /**
     * Sends the report. The sheet says it was received, or keeps the form with why it wasn't.
     */
    public function sendPersonReport(): void
    {
        $username = $this->reportedUsername;
        $reason = ReportReason::tryFrom($this->reportReason);

        if (! $this->personReportOpen || $this->personReported || $username === '' || $reason === null) {
            return;
        }

        $details = trim($this->reportDetails);
        $screenError = $this->error;
        $this->personReportError = null;

        $sent = $this->attempt(function (Client $devnull) use ($username, $reason, $details): bool {
            $devnull->reportUser($username, $reason, $details);

            return true;
        });

        if ($sent === true) {
            $this->personReported = true;

            return;
        }

        // It's the sheet's to say, not the screen's.
        $this->personReportError = $this->error;
        $this->error = $screenError;
    }

    /**
     * Done, after a report. Android never says when a sheet closed from here has gone, so the next one starts over
     * when it opens (see openPersonReport()).
     */
    public function hidePersonReport(): void
    {
        $this->personReportOpen = false;
    }

    /**
     * The sheet has gone, swiped away or closed from here.
     */
    public function personReportClosed(): void
    {
        $this->personReportOpen = false;
        $this->reportedUsername = '';
        $this->personReported = false;
        $this->personReportError = null;
        $this->forgetReportForm();
    }

    private function openPersonReport(string $username): void
    {
        $this->personReportClosed();
        $this->reportedUsername = $username;
        $this->personReportOpen = true;
    }
}
