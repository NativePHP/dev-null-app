{{--
    The sheet Report @handle opens (see ReportsPeople), in the words of the web's dialog. It's full height for the form
    and short for the receipt. Keyed, so what a screen's polls redraw behind it never gives it a new id, and the keyboard
    stays up in the details field.
--}}
<native:bottom-sheet native:key="person-report-sheet" ref="person-report-sheet" :visible="$personReportOpen" :detents="$personReported ? '0.3' : 'large'" @dismiss="personReportClosed">
    @if ($reportedUsername !== '')
        @if ($personReported)
            @include('native.partials.report-received', ['done' => 'hidePersonReport'])
        @else
            @include('native.partials.report-form', [
                'title' => 'Report @'.$reportedUsername,
                'intro' => "Your report is private and they won't be told. Moderators see your reason and details, not your messages. Reporting doesn't block them.",
                'question' => 'Why are you reporting this person?',
                'reportError' => $personReportError,
                'send' => 'sendPersonReport',
            ])
        @endif
    @endif
</native:bottom-sheet>
