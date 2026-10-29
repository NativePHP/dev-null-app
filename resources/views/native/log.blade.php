<native:column class="w-full h-full bg-theme-background">
    @include('native.partials.error')
    @include('native.partials.refresh-failed')
    @include('native.partials.commit-sheet')
    @include('native.partials.live-stream-sheet')

    {{--
        The logs as pages you swipe between, under DESIGN.md's page tabs, which the pager draws itself: a full-width
        split, the active label in ink over a 3px underline in your colour. On the phone the underline follows your
        finger, the label you're nearest lights up past halfway, and a tab tap scrolls the pager there, so none of it
        waits for PHP.

        The pager says which log it settled on once the swipe has come to rest, not as the log changes under your
        finger: switching logs sends a frame big enough to make the swipe stutter if it lands mid-swipe. A tap on the
        tab you're on comes here instead, and takes that log back to its top.

        The pager keeps where it is across renders, and only moves when $pageIndex is one it didn't report itself.
        Keyed so the banners above coming and going don't make it a new pager, with new lists.
    --}}
    <native:pager
        native:key="log-pager"
        ref="log-pager"
        horizontal
        report-on-settle
        class="w-full flex-1 bg-theme-surface"
        :count="count($pages)"
        :page="$pageIndex"
        on-page-change="showPage"
        :tabs="$tabs"
        on-tab-reselect="backToTop"
        tab-active-color="theme-ink"
        :tab-indicator-color="'theme-'.$myColour"
        tab-color="theme-ink-muted"
        tab-divider-color="theme-hairline"
        tab-font="semibold"
        a11y-label="Logs"
    >
        @foreach ($pages as $page)
            @include('native.partials.log-page', ['page' => $page])
        @endforeach

        <native:pager-overlay>
            @include('native.partials.new-commits-pill', ['label' => $newCommitsLabel, 'press' => 'showNewCommits', 'shown' => $newCommits !== []])
        </native:pager-overlay>
    </native:pager>
</native:column>

@if ($viewer !== null && ! $sheetOpen)
    <native:fab
        ref="compose-fab"
        icon="edit"
        bottom-offset="72"
        edge-offset="20"
        a11y-label="New commit"
        @tap="composeInSheet"
    />
@endif
