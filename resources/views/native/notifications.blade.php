<native:column class="w-full h-full bg-theme-background">
    @include('native.partials.error')
    @include('native.partials.refresh-failed')

    {{--
        The filters as pages you swipe between, as on the Log: the one you're on and one either side, which show the
        first few groups kept for them. The web's filters run along the top as the Log's page tabs, each as wide as its
        label, in a row that scrolls sideways. The pager draws them, as on the Log: the underline follows your finger,
        and the row keeps the filter you're on in view, as the web does. It says which filter it settled on once the
        swipe has come to rest, and a tap on the filter you're on takes it back to its top. Keyed so the banners above
        coming and going don't make it a new pager.
    --}}
    <native:pager
        native:key="notifications-pager"
        ref="notifications-pager"
        horizontal
        report-on-settle
        class="w-full flex-1 bg-theme-surface"
        :count="count($types)"
        :page="$pageIndex"
        :from="$pagesFrom"
        :to="$pagesTo"
        on-page-change="showPage"
        :tabs="array_column($types, 0)"
        tabs-scrollable
        on-tab-reselect="backToTop"
        tab-active-color="theme-ink"
        :tab-indicator-color="'theme-'.$myColour"
        tab-color="theme-ink-muted"
        tab-divider-color="theme-hairline"
        tab-font="semibold"
        a11y-label="Notifications"
    >
        @foreach ($pages as $page)
            @include('native.partials.notifications-page', ['page' => $page])
        @endforeach
    </native:pager>
</native:column>
