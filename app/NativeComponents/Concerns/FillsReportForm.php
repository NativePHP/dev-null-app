<?php

namespace App\NativeComponents\Concerns;

/**
 * What's on the web's Report form (see partials/report-form), for a commit (see
 * OpensCommitSheet) or a person (see ReportsPeople).
 */
trait FillsReportForm
{
    /**
     * The reason picked on the report form, or '' until one is.
     */
    public string $reportReason = '';

    public string $reportDetails = '';

    private function forgetReportForm(): void
    {
        $this->reportReason = '';
        $this->reportDetails = '';
    }
}
