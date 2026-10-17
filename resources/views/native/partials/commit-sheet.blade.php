{{--
    The sheet under a commit (see OpensCommitSheet). Quote opens it as a composer: the field, focused, with the commit
    being quoted under it as a static card, then the counter and Commit, which waits for a body long enough to make.
    If devnull says no, why shows here and the draft stays. The Log's compose button opens the same composer for a new
    commit, without the card.

    More opens it under someone else's published commit, with two rows, Share link and Report commit. Report commit turns it into the web's Report dialog: the private, not-hidden line, the
    commit's body clamped to three lines, the four reasons, optional details, and Send report, which waits for a
    reason. Once devnull has the report, the sheet says so, with Done. If devnull says no, why shows here and the form
    keeps what you wrote.

    It's short for the two rows and the receipt, and full height for the forms. It's always on the screen, shown or
    not, so iOS can slide it away and say when it's gone.
--}}
<native:bottom-sheet ref="sheet" :visible="$sheetOpen" :detents="in_array($sheetView, ['report', 'quote', 'compose'], true) ? 'large' : '0.3'" @dismiss="commitSheetClosed">
    @if ($sheetCommit || $sheetView === 'compose')
        @if (in_array($sheetView, ['quote', 'compose'], true))
            @php($sheetLength = mb_strlen(trim($sheetBody)))
            @php($quoting = $sheetView === 'quote')
            <native:scroll-view ref="sheet-composer" class="w-full flex-1">
                <native:column class="w-full px-5 pt-6 pb-10 gap-4">
                    <native:text font="semibold" class="text-lg text-theme-ink">{{ $quoting ? 'Quote '.$sheetCommit->author->handle() : 'New commit' }}</native:text>

                    <native:bare-text-input
                        ref="sheet-body"
                        class="w-full text-theme-ink"
                        :placeholder="$quoting ? 'Add your take' : 'What did you ship?'"
                        native:model.debounce.150ms="sheetBody"
                        autofocus
                        multiline
                        :max-lines="8"
                        :max-length="\App\Devnull\Data\Commit::MaxLength"
                        size="lg"
                        a11y-label="Your commit"
                    />

                    @if ($quoting)
                        @include('native.partials.quoted-commit', ['quoted' => $sheetCommit, 'static' => true])
                    @endif

                    @if ($sheetError)
                        <native:text ref="sheet-error" class="w-full text-sm text-theme-destructive">{{ $sheetError }}</native:text>
                    @endif

                    <native:row class="w-full gap-3 items-center justify-end">
                        @include('native.partials.body-counter', ['body' => $sheetBody, 'ref' => 'sheet-budget'])
                        <native:button ref="sheet-commit" variant="primary" :disabled="$sheetLength < \App\Devnull\Data\Commit::MinLength || $sheetLength > \App\Devnull\Data\Commit::MaxLength" @press="commitFromSheet">Commit</native:button>
                    </native:row>
                </native:column>
            </native:scroll-view>
        @elseif ($sheetView === 'report')
            <native:scroll-view ref="report-form" class="w-full flex-1">
                <native:column class="w-full px-5 pt-6 pb-10 gap-5">
                    <native:column class="w-full gap-2">
                        <native:text font="semibold" class="text-lg text-theme-ink">Report commit</native:text>
                        <native:text class="text-sm leading-relaxed text-theme-ink-muted">Your report is private. Only moderators can review it. Reporting does not hide this commit.</native:text>
                    </native:column>

                    <native:column class="w-full">
                        <native:column class="w-full h-[1] bg-theme-hairline" />
                        <native:text ref="report-body" class="w-full py-3 text-sm leading-relaxed text-theme-ink" :max-lines="3">{{ $sheetCommit->plainText() }}</native:text>
                        <native:column class="w-full h-[1] bg-theme-hairline" />
                    </native:column>

                    <native:radio-group ref="report-reason" label="Why are you reporting this commit?" native:model="reportReason">
                        @foreach (\App\Devnull\ReportReason::cases() as $reportOption)
                            <native:radio value="{{ $reportOption->value }}" label="{{ $reportOption->label() }}" />
                        @endforeach
                    </native:radio-group>

                    <native:outlined-text-input
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

                    @if ($sheetError)
                        <native:text ref="sheet-error" class="w-full text-sm text-theme-destructive">{{ $sheetError }}</native:text>
                    @endif

                    <native:button ref="send-report" variant="primary" :disabled="$reportReason === ''" @press="sendReport" class="w-full">Send report</native:button>
                </native:column>
            </native:scroll-view>
        @elseif ($sheetView === 'reported')
            <native:column ref="report-received" class="w-full px-5 pt-6 pb-6 gap-5">
                <native:row class="w-full gap-3 items-center">
                    <native:icon :ios="\App\Icons\Lucide::Check->ios()" :android="\App\Icons\Lucide::Check->android()" :size="20" class="text-theme-primary" />
                    <native:text class="flex-1 text-base text-theme-ink">Report received. Moderators will review it privately.</native:text>
                </native:row>
                <native:button ref="report-done" variant="secondary" @press="hideCommitSheet" class="w-full">Done</native:button>
            </native:column>
        @else
            <native:column ref="sheet-more" class="w-full pt-5">
                <native:pressable ref="sheet-share" @press="shareFromSheet" a11y-label="Share link">
                    <native:row class="w-full min-h-[52] px-5 gap-4 items-center">
                        <native:icon :ios="\App\Icons\Lucide::Link->ios()" :android="\App\Icons\Lucide::Link->android()" :size="20" class="text-theme-ink" />
                        <native:text class="text-base text-theme-ink">Share link</native:text>
                    </native:row>
                </native:pressable>
                <native:row class="w-full pl-[56]">
                    <native:column class="flex-1 h-[1] bg-theme-hairline" />
                </native:row>
                <native:pressable ref="sheet-report" @press="startReport" a11y-label="Report commit">
                    <native:row class="w-full min-h-[52] px-5 gap-4 items-center">
                        <native:icon :ios="\App\Icons\Lucide::Flag->ios()" :android="\App\Icons\Lucide::Flag->android()" :size="20" class="text-theme-ink" />
                        <native:text class="text-base text-theme-ink">Report commit</native:text>
                    </native:row>
                </native:pressable>
            </native:column>
        @endif
    @endif
</native:bottom-sheet>
