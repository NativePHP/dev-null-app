{{--
    The sheet under a commit (see OpensCommitSheet). Quote opens it as a composer: a bar of Cancel, the title and
    Commit, which waits for a body long enough to make, over the field, focused, with the commit being quoted under it
    as a static card, then the counter. Cancel and Commit are a cross and a check in circles, as Apple draws a sheet's
    bar, the same on both platforms (see sheet-bar-button), 16pt in from the sheet's top and sides. On Android the bar
    sits under Material's handle strip, since mobile-ui has no way yet to draw the handle over the content. The title is
    centred.
    Cancel closes it as a swipe does, keeping the draft. Commit closes it at once, and the commit goes from the outbox
    (see SyncsOutbox). If devnull says no, a toast says why and the draft comes back in the sheet. The
    Log's compose button opens the same composer for a new commit, without the card. A draft stashed last time comes
    back in it, with the web's stash@{0} and drop beside the counter, in the toolbar at the bottom.

    Report commit, in someone else's published commit's More menu, opens it as the web's Report dialog: the private,
    not-hidden line, the commit's body clamped to three lines, the four reasons, optional details, and Send report,
    which waits for a reason. Once devnull has the report, the sheet says so, with Done. If devnull says no, why shows
    here and the form keeps what you wrote.

    A new commit can become a `/feature` or `/bug` issue, as on the web (see DraftsIssues): the command's badge over the
    field, its placeholder in it, and Open issue (the same check) in Commit's place in the bar. Opened, the sheet says
    so, with View issue and Done.

    A commit's page opens its reply here too, as "Reply to @handle", with the commit being answered as a static card
    over the field, for the photos and the preview its reply bar has no room for. Commit says Reply there.

    Under what's being written come its photos and the poll editor. A toolbar is pinned to the bottom of the sheet, so
    it stays just above the keyboard: the tools on the left, photos, the camera, a poll (not on a reply) and the
    preview, which shows the draft as the commit it would become in place of the field (see AttachesToCommits), and
    stash@{0} and the counter on the right. An issue draft has no tools, as on the web. With photos, Commit spins while
    they upload, and no body at all will do.

    A new commit can be scheduled instead, with the web's clock button among the tools: "Submit at" opens under the
    field with the system's date and time picker, in the phone's timezone, within the 30 days devnull allows, and the
    web's lines on what scheduling means and Scheduled commits →. The bar's check becomes Schedule commit, and waits for
    a time too. As on the web, an issue can't be scheduled, so neither shows the other. devnull's app API schedules a
    body alone, so the clock greys out while there are photos or a poll, and they grey out while it's on.

    It's full height for the forms, and short for the receipts. It's always on the screen, shown or not, so iOS can
    slide it away and say when it's gone.

    The sheet and its field are keyed, so nothing drawn above the field (Commit turning on, the issue badge, a banner
    behind the sheet) gives it a new id. iOS and Android would build a new field for a new id, and the keyboard would
    go down with the old one.
--}}
<native:bottom-sheet native:key="commit-sheet" ref="sheet" :visible="$sheetOpen" :detents="in_array($sheetView, ['reported', 'issued'], true) ? '0.3' : 'large'" @dismiss="commitSheetClosed">
    @if ($sheetCommit || in_array($sheetView, ['compose', 'issued'], true))
        @if (in_array($sheetView, ['quote', 'compose', 'reply'], true))
            @php($quoting = $sheetView === 'quote')
            @php($replying = $sheetView === 'reply')
            @php($issueCommand = $sheetIssue === null ? null : \App\Devnull\IssueCommand::from($sheetIssue))
            <native:column class="w-full flex-1">
                @php($canCommit = \App\Devnull\Data\Draft::canCommit($sheetBody, $sheetImages, $replying ? null : $sheetPoll))
                <native:row class="w-full p-4 items-center gap-3">
                    @include('native.partials.sheet-bar-button', ['ref' => 'sheet-cancel', 'icon' => \App\Icons\Lucide::X, 'label' => 'Cancel', 'hint' => 'Keeps your draft for next time', 'press' => 'hideCommitSheet'])
                    <native:text font="semibold" class="flex-1 text-center text-base android:text-lg text-theme-ink" :max-lines="1">{{ match (true) { $quoting => 'Quote '.$sheetCommit->author->handle(), $replying => 'Reply to '.$sheetCommit->author->handle(), default => 'New commit' } }}</native:text>
                    @if ($issueCommand)
                        @include('native.partials.sheet-bar-button', ['ref' => 'sheet-open-issue', 'icon' => \App\Icons\Lucide::Check, 'label' => 'Open issue', 'main' => true, 'loading' => $openingIssue, 'press' => $openingIssue || ! \App\Devnull\IssueCommand::fits($sheetBody) ? null : 'openIssueFromSheet'])
                    @else
                        @php($schedulingThis = $scheduling && $sheetView === 'compose')
                        @include('native.partials.sheet-bar-button', ['ref' => 'sheet-commit', 'icon' => \App\Icons\Lucide::Check, 'label' => match (true) { $schedulingThis => 'Schedule commit', $replying => 'Reply', default => 'Commit' }, 'main' => true, 'loading' => $sendingSchedule, 'press' => $sendingSchedule || ! $canCommit || ($schedulingThis && ! $hasScheduledTime) ? null : 'commitFromSheet'])
                    @endif
                </native:row>
                <native:column class="w-full h-[1] bg-theme-hairline" />
                <native:scroll-view ref="sheet-composer" class="w-full flex-1">
                    <native:column class="w-full px-5 pt-4 pb-10 gap-4">
                        @if ($sheetError)
                            <native:text ref="sheet-error" class="w-full text-sm text-theme-destructive">{{ $sheetError }}</native:text>
                        @endif

                        @if ($issueCommand)
                            @include('native.partials.issue-badge', ['command' => $issueCommand])
                        @endif

                        @if ($replying)
                            @include('native.partials.quoted-commit', ['quoted' => $sheetCommit, 'static' => true, 'label' => 'Replying to'])
                        @endif

                        @if ($previewing)
                            @include('native.partials.composer-preview')
                        @else
                            <native:bare-text-input
                                native:key="sheet-body"
                                ref="sheet-body"
                                class="w-full text-theme-ink"
                                :placeholder="$issueCommand?->placeholder() ?? match (true) { $quoting => 'Add your take', $replying => 'Reply to '.$sheetCommit->author->handle(), default => 'What did you ship?' }"
                                native:model.debounce.150ms="sheetBody"
                                autofocus
                                multiline
                                :max-lines="8"
                                :max-length="$issueCommand ? \App\Devnull\Data\Commit::MaxLength : \App\Devnull\Data\Commit::MaxRawLength"
                                size="lg"
                                a11y-label="Your commit"
                            />

                            @if (! $issueCommand && \App\Devnull\Data\CommitBody::hasOversizedUrl($sheetBody))
                                <native:text ref="sheet-link-error" class="w-full text-sm text-theme-destructive">Each link must not be greater than {{ \App\Devnull\Data\BodyLink::MaxLength }} characters.</native:text>
                            @endif

                            @include('native.partials.composer-photos')

                            @if ($sheetPoll !== null && ! $replying)
                                @include('native.partials.poll-editor')
                            @endif

                            @if ($quoting)
                                @include('native.partials.quoted-commit', ['quoted' => $sheetCommit, 'static' => true])
                            @elseif ($scheduling)
                                @include('native.partials.schedule-picker')
                            @elseif (! $replying && ! $issueCommand)
                                @include('native.partials.issue-suggestions', [
                                    'suggestions' => $sheetTyped ? \App\Devnull\IssueCommand::suggested($sheetBody) : [],
                                    'hint' => $issueHintsDismissed ? null : \App\Devnull\IssueCommand::hinted($sheetBody),
                                ])
                            @endif
                        @endif

                    </native:column>
                </native:scroll-view>
                {{-- Pinned under the scroll view, so it rides just above the keyboard, and above the home indicator without it. --}}
                <native:column class="w-full h-[1] bg-theme-hairline" />
                <native:row ref="sheet-toolbar" class="w-full pl-3 pr-5 py-1 gap-3 items-center">
                    @unless ($issueCommand)
                        @include('native.partials.composer-tools')
                    @endunless
                    <native:spacer />
                    @if ($sheetRestored)
                        @include('native.partials.stash-label', ['ref' => 'sheet-stash', 'drop' => 'dropSheetStash'])
                    @endif
                    @include('native.partials.body-counter', ['body' => $sheetBody, 'ref' => 'sheet-budget', 'literal' => $issueCommand !== null])
                </native:row>
            </native:column>
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
        @elseif ($sheetView === 'issued')
            <native:column ref="issue-opened" class="w-full px-5 pt-6 pb-6 gap-5">
                <native:row class="w-full gap-3 items-center">
                    <native:icon :ios="\App\Icons\Lucide::Check->ios()" :android="\App\Icons\Lucide::Check->android()" :size="20" class="text-theme-primary" />
                    <native:text class="flex-1 text-base text-theme-ink">Opened issue #{{ $openedIssueNumber }}.</native:text>
                </native:row>
                <native:column class="w-full gap-3">
                    @if ($openedIssueUrl)
                        <native:button ref="view-issue" :variant="$myColour" size="lg" :ios-icon-trailing="\App\Icons\Lucide::ArrowUpRight->ios()" :android-icon-trailing="\App\Icons\Lucide::ArrowUpRight->android()" @press="viewOpenedIssue" class="w-full">View issue</native:button>
                    @endif
                    <native:button ref="issue-done" variant="secondary" size="lg" @press="hideCommitSheet" class="w-full">Done</native:button>
                </native:column>
            </native:column>
        @endif
    @endif
</native:bottom-sheet>

@include('native.partials.image-viewer')
