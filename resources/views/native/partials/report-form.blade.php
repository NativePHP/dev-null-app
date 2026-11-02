{{--
    The web's Report dialog, for a commit (see commit-sheet) or a person (see person-report-sheet): what the report is,
    how private it is, the reasons, optional details, and Send report, which waits for a reason. If devnull says no, why
    shows here and the form keeps what you wrote. $reported is what the report is about, a commit's body, shown between
    hairlines.
--}}
<native:scroll-view ref="report-form" class="w-full flex-1">
    <native:column class="w-full px-5 pt-6 pb-10 gap-5">
        <native:column class="w-full gap-2">
            <native:text font="semibold" class="text-lg text-theme-ink">{{ $title }}</native:text>
            <native:text class="text-sm leading-relaxed text-theme-ink-muted">{{ $intro }}</native:text>
        </native:column>

        @if (($reported ?? null) !== null)
            <native:column class="w-full">
                <native:column class="w-full h-[1] bg-theme-hairline" />
                <native:text ref="report-body" class="w-full py-3 text-sm leading-relaxed text-theme-ink" :max-lines="3">{{ $reported }}</native:text>
                <native:column class="w-full h-[1] bg-theme-hairline" />
            </native:column>
        @endif

        <native:radio-group ref="report-reason" label="{{ $question }}" native:model="reportReason">
            @foreach (\App\Devnull\ReportReason::cases() as $reportOption)
                <native:radio value="{{ $reportOption->value }}" label="{{ $reportOption->label() }}" />
            @endforeach
        </native:radio-group>

        <native:outlined-text-input
            native:key="report-details"
            ref="report-details"
            class="w-full"
            label="Details (optional)"
            supporting="Add context for moderators. Up to {{ \App\Devnull\ReportReason::DetailsMaxLength }} characters."
            native:model.debounce.150ms="reportDetails"
            multiline
            :min-lines="3"
            :max-lines="6"
            :max-length="\App\Devnull\ReportReason::DetailsMaxLength"
        />

        @if ($reportError)
            <native:text ref="sheet-error" class="w-full text-sm text-theme-destructive">{{ $reportError }}</native:text>
        @endif

        <native:button ref="send-report" :variant="$myColour" size="lg" :disabled="$reportReason === ''" @press="{{ $send }}" class="w-full">Send report</native:button>
    </native:column>
</native:scroll-view>
