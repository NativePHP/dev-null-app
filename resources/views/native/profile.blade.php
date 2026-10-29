<native:column class="w-full h-full bg-theme-background">
    @include('native.partials.error')
    @include('native.partials.refresh-failed')
    @include('native.partials.commit-sheet')
    @include('native.partials.achievements-sheet')
    @include('native.partials.live-stream-sheet')

    {{-- The pager or the list stays alone in here, so the banners above coming and going never add a node beside it. --}}
    <native:column class="w-full flex-1">
    @if ($profile && ! $unavailable)
        @php($user = $profile->user)
        {{--
            Commits and Replies as pages you swipe between, under the tabs the pager draws, as on the Log, with the
            underline in your colour. The header goes to the pager too, which draws it at the top of the page you're on,
            so it scrolls up with it until the tabs pin under the nav bar. It stays where it is while you swipe, and the
            pages keep level with it.

            The pill hangs under the tabs wherever they are. Keyed so the banners above coming and going don't make it
            a new pager, with new lists.
        --}}
        <native:pager
            native:key="profile-pager"
            ref="profile-pager"
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
            a11y-label="Commits and replies"
        >
            <native:pager-header>
                @include('native.partials.profile-header')
            </native:pager-header>

            @foreach ($pages as $page)
                @include('native.partials.profile-page', ['page' => $page])
            @endforeach

            <native:pager-overlay>
                @include('native.partials.new-commits-pill', ['label' => $newCommitsLabel, 'press' => 'showNewCommits', 'shown' => $newCommits !== []])
            </native:pager-overlay>
        </native:pager>
    @else
        <native:list native:key="profile-{{ $listKey }}" class="w-full flex-1 bg-theme-surface" on-refresh="load">
            @if ($unavailable)
                @include('native.partials.profile-unavailable')
            @elseif ($error === null)
                <native:column ref="loading-profile" class="w-full items-center py-16 bg-theme-surface" a11y-label="Loading profile">
                    <native:activity-indicator />
                </native:column>
            @endif
        </native:list>
    @endif
    </native:column>
</native:column>
