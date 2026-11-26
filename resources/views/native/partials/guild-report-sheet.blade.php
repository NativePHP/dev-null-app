{{--
    Report guild, from the guild page's ⋯ (see Guild::reportGuild()), in the web's words: its title and private line,
    then "What looks wrong?", which devnull requires, up to 500 characters, and Send report, which waits for it. Built
    like the commit sheet's report, without reasons, as the web's guild report has none. Once devnull has it, the sheet
    says so, with Done, which only hides the sheet, so it keeps saying so while it slides away. If devnull says no, why
    shows over Send report and what you wrote stays.
--}}
<native:bottom-sheet ref="guild-report-sheet" :visible="$guildReportOpen" :detents="$guildReportSent ? '0.3' : 'large'" @dismiss="guildReportClosed">
    @if ($guildReportSent)
        <native:column ref="guild-report-received" class="w-full px-5 pt-6 pb-6 gap-5">
            <native:row class="w-full gap-3 items-center">
                <native:icon :ios="\App\Icons\Lucide::Check->ios()" :android="\App\Icons\Lucide::Check->android()" :size="20" class="text-theme-primary" />
                <native:text class="flex-1 text-base text-theme-ink">Report received. Admins will review it privately.</native:text>
            </native:row>
            <native:button ref="guild-report-done" variant="secondary" size="lg" @press="hideGuildReport" class="w-full">Done</native:button>
        </native:column>
    @elseif ($guildReportOpen)
        <native:scroll-view ref="guild-report-form" class="w-full flex-1">
            <native:column class="w-full px-5 pt-6 pb-10 gap-5">
                <native:column class="w-full gap-2">
                    <native:text font="semibold" class="text-lg text-theme-ink">Report {{ $guild?->name }}</native:text>
                    <native:text class="text-sm leading-relaxed text-theme-ink-muted">Your report is private. Admins will review the description and icon. Reporting does not hide the guild.</native:text>
                </native:column>

                <native:outlined-text-input
                    ref="guild-report-details"
                    class="w-full"
                    label="What looks wrong?"
                    native:model.debounce.150ms="guildReportDetails"
                    multiline
                    :min-lines="3"
                    :max-lines="6"
                    :max-length="\App\NativeComponents\Guild::ReportDetailsMaxLength"
                />

                @if ($guildReportError)
                    <native:text ref="guild-report-error" class="w-full text-sm text-theme-destructive">{{ $guildReportError }}</native:text>
                @endif

                <native:button ref="guild-send-report" :variant="$myColour" size="lg" :disabled="trim($guildReportDetails) === ''" @press="sendGuildReport" class="w-full">Send report</native:button>
            </native:column>
        </native:scroll-view>
    @endif
</native:bottom-sheet>
