{{--
    The web's Remotes inbox: Open, Fetched (while devnull takes requests, counting them) and Stashed along the top, then
    a row per conversation, the latest first. The tabs are pages you swipe between, as on the Log: the pager draws them
    in a full-width split, the underline in your lane follows your finger, and a tap on the tab you're on takes it back
    to its top. While you have Remotes off, the tab shows the web's page for /remotes then, its 404, word for word.
--}}
<native:column class="w-full h-full bg-theme-background">
    @if ($off)
        <native:column ref="remotes-off" class="w-full flex-1 px-6 py-16 gap-3 bg-theme-surface">
            <native:text font="mono" class="w-full text-[13px] text-theme-ink-muted">fatal: path '/remotes' does not exist in 'HEAD'</native:text>
            <native:text font="semibold" class="w-full text-2xl tracking-tight text-theme-ink">There's nothing here.</native:text>
            <native:text class="w-full text-[15px] leading-relaxed text-theme-ink-muted">The link may be mistyped, or whatever it pointed to has been deleted.</native:text>
            <native:text ref="back-to-log" font="medium" class="text-[15px] text-theme-primary" @press="backToLog">Back to your log</native:text>
        </native:column>
    @else
        @include('native.partials.error')
        @include('native.partials.refresh-failed')

        <native:pager
            native:key="remotes-pager"
            ref="remotes-pager"
            horizontal
            report-on-settle
            class="w-full flex-1 bg-theme-surface"
            :count="count($tabLabels)"
            :page="$pageIndex"
            :from="$pagesFrom"
            :to="$pagesTo"
            on-page-change="showPage"
            :tabs="$tabLabels"
            on-tab-reselect="backToTop"
            tab-active-color="theme-ink"
            :tab-indicator-color="'theme-'.$myColour"
            tab-color="theme-ink-muted"
            tab-divider-color="theme-hairline"
            tab-font="semibold"
            a11y-label="Remotes"
        >
            @foreach ($pages as $page)
                @include('native.partials.remotes-page', ['page' => $page])
            @endforeach
        </native:pager>

        @include('native.partials.mute-sheet')
    @endif
</native:column>
