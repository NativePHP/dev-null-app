{{--
    The sheet that reports a Remotes message or a person (see ReportsToModerators), as the commit sheet's Report commit
    form: the title, a not-hidden line, what's reported, the four reasons, optional details, and Send report, which waits
    for a reason. Once devnull has the report, the sheet says so, with Done. If devnull says no, why shows above Send
    report.
--}}
<native:bottom-sheet native:key="report-sheet" ref="report-sheet" :visible="$reportingOpen" :detents="$reportingSent ? '0.3' : 'large'" @dismiss="closeReporting">
    @if ($reportingSent)
        <native:column ref="reporting-received" class="w-full px-5 pt-6 pb-6 gap-5">
            <native:row class="w-full gap-3 items-center">
                <native:icon :ios="\App\Icons\Lucide::Check->ios()" :android="\App\Icons\Lucide::Check->android()" :size="20" class="text-theme-primary" />
                <native:text class="flex-1 text-base text-theme-ink">Report received. Moderators will review it privately.</native:text>
            </native:row>
            <native:button ref="reporting-done" variant="secondary" size="lg" @press="closeReporting" class="w-full">Done</native:button>
        </native:column>
    @elseif ($reportingKind !== '')
        <native:scroll-view ref="reporting-form" class="w-full flex-1">
            <native:column class="w-full px-5 pt-6 pb-10 gap-5">
                <native:column class="w-full gap-2">
                    <native:text font="semibold" class="text-lg text-theme-ink">{{ $reportingKind === 'message' ? 'Report message' : 'Report '.$reportingPreview }}</native:text>
                    <native:text class="text-sm leading-relaxed text-theme-ink-muted">{{ $reportingKind === 'message' ? 'Your report is private. Only moderators can review it, and they will see this message. Reporting does not hide it.' : 'Your report is private. Only moderators can review it. They will not be told. To stop seeing them, block them too.' }}</native:text>
                </native:column>

                @if ($reportingKind === 'message')
                    <native:column class="w-full">
                        <native:column class="w-full h-[1] bg-theme-hairline" />
                        <native:text ref="reporting-body" class="w-full py-3 text-sm leading-relaxed text-theme-ink" :max-lines="3">{{ $reportingPreview }}</native:text>
                        <native:column class="w-full h-[1] bg-theme-hairline" />
                    </native:column>
                @endif

                <native:radio-group ref="reporting-reason" label="{{ $reportingKind === 'message' ? 'Why are you reporting this message?' : 'Why are you reporting them?' }}" native:model="reportingReason">
                    @foreach (\App\Devnull\ReportReason::cases() as $reportOption)
                        <native:radio value="{{ $reportOption->value }}" label="{{ $reportOption->label() }}" />
                    @endforeach
                </native:radio-group>

                <native:outlined-text-input
                    ref="reporting-details"
                    class="w-full"
                    label="Details (optional)"
                    supporting="Add context for moderators. Up to {{ \App\Devnull\ReportReason::DetailsMaxLength }} characters."
                    native:model.debounce.150ms="reportingDetails"
                    multiline
                    :min-lines="3"
                    :max-lines="6"
                    :max-length="\App\Devnull\ReportReason::DetailsMaxLength"
                />

                @if ($reportingError)
                    <native:text ref="reporting-error" class="w-full text-sm text-theme-destructive">{{ $reportingError }}</native:text>
                @endif

                <native:button ref="send-reporting" variant="primary" size="lg" :disabled="$reportingReason === ''" @press="sendReporting" class="w-full">Send report</native:button>
            </native:column>
        </native:scroll-view>
    @endif
</native:bottom-sheet>
