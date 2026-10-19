{{--
    The sheet under a commit (see OpensCommitSheet). Quote opens it as a composer: the field, focused, with the commit
    being quoted under it as a static card, then the counter and Commit, which waits for a body long enough to make.
    If devnull says no, why shows here and the draft stays. The Log's compose button opens the same composer for a new
    commit, without the card. A draft stashed last time comes back in it, with the web's stash@{0} and drop on the left
    of Commit's row.

    A commit's page opens its reply here too, as "Reply to @handle", over the commit being answered as a static card,
    for the photos and the preview its reply bar has no room for. Commit says Reply there.

    Under what's being written come its photos, then the poll editor, then a row of tools: photos, the camera, a poll
    (not on a reply), and the preview, which shows the draft as the commit it would become in place of the field (see
    AttachesToCommits). With photos, Commit spins while they upload, and a short body, or none, will do.

    Report commit, in someone else's published commit's More menu, opens it as the web's Report dialog: the private,
    not-hidden line, the commit's body clamped to three lines, the four reasons, optional details, and Send report,
    which waits for a reason. Once devnull has the report, the sheet says so, with Done. If devnull says no, why shows
    here and the form keeps what you wrote.

    It's full height for the forms, and short for the receipt. It's always on the screen, shown or not, so iOS can
    slide it away and say when it's gone.
--}}
<native:bottom-sheet ref="sheet" :visible="$sheetOpen" :detents="$sheetView === 'reported' ? '0.3' : 'large'" @dismiss="commitSheetClosed">
    @if ($sheetCommit || $sheetView === 'compose')
        @if (in_array($sheetView, ['quote', 'compose', 'reply'], true))
            @php($quoting = $sheetView === 'quote')
            @php($replying = $sheetView === 'reply')
            <native:scroll-view ref="sheet-composer" class="w-full flex-1">
                <native:column class="w-full px-5 pt-6 pb-10 gap-4">
                    <native:text font="semibold" class="text-lg text-theme-ink">{{ match (true) { $quoting => 'Quote '.$sheetCommit->author->handle(), $replying => 'Reply to '.$sheetCommit->author->handle(), default => 'New commit' } }}</native:text>

                    @if ($replying)
                        @include('native.partials.quoted-commit', ['quoted' => $sheetCommit, 'static' => true, 'label' => 'Replying to'])
                    @endif

                    @if ($previewing)
                        @include('native.partials.composer-preview')
                    @else
                        <native:bare-text-input
                            ref="sheet-body"
                            class="w-full text-theme-ink"
                            :placeholder="match (true) { $quoting => 'Add your take', $replying => 'Reply to '.$sheetCommit->author->handle(), default => 'What did you ship?' }"
                            native:model.debounce.150ms="sheetBody"
                            autofocus
                            multiline
                            :max-lines="8"
                            :max-length="\App\Devnull\Data\Commit::MaxLength"
                            size="lg"
                            a11y-label="Your commit"
                        />

                        @include('native.partials.composer-photos')

                        @if ($sheetPoll !== null && ! $replying)
                            @include('native.partials.poll-editor')
                        @endif

                        @if ($quoting)
                            @include('native.partials.quoted-commit', ['quoted' => $sheetCommit, 'static' => true])
                        @endif
                    @endif

                    @include('native.partials.composer-tools')

                    @if ($sheetError)
                        <native:text ref="sheet-error" class="w-full text-sm text-theme-destructive">{{ $sheetError }}</native:text>
                    @endif

                    <native:row class="w-full gap-3 items-center justify-end">
                        @if ($sheetRestored)
                            @include('native.partials.stash-label', ['ref' => 'sheet-stash', 'drop' => 'dropSheetStash'])
                            <native:spacer />
                        @endif
                        @include('native.partials.body-counter', ['body' => $sheetBody, 'ref' => 'sheet-budget'])
                        {{-- The label stays put while it spins, so the button keeps its size. --}}
                        <native:button ref="sheet-commit" :variant="$myColour" :loading="$sheetSending" :disabled="$sheetSending || ! \App\Devnull\Data\Draft::canCommit($sheetBody, $sheetImages, $replying ? null : $sheetPoll)" @press="commitFromSheet">{{ $replying ? 'Reply' : 'Commit' }}</native:button>
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

                    <native:button ref="send-report" :variant="$myColour" size="lg" :disabled="$reportReason === ''" @press="sendReport" class="w-full">Send report</native:button>
                </native:column>
            </native:scroll-view>
        @elseif ($sheetView === 'reported')
            <native:column ref="report-received" class="w-full px-5 pt-6 pb-6 gap-5">
                <native:row class="w-full gap-3 items-center">
                    <native:icon :ios="\App\Icons\Lucide::Check->ios()" :android="\App\Icons\Lucide::Check->android()" :size="20" class="text-theme-primary" />
                    <native:text class="flex-1 text-base text-theme-ink">Report received. Moderators will review it privately.</native:text>
                </native:row>
                <native:button ref="report-done" variant="secondary" size="lg" @press="hideCommitSheet" class="w-full">Done</native:button>
            </native:column>
        @endif
    @endif
</native:bottom-sheet>
