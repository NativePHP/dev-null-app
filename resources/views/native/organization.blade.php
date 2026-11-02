<native:column class="w-full h-full bg-theme-background">
    @include('native.partials.refresh-failed')
    @include('native.partials.commit-sheet')
    @include('native.partials.live-stream-sheet')
    @include('native.partials.stargazers-sheet')

    {{-- The pager or the list stays alone in here, so the banners above coming and going never add a node beside it. --}}
    <native:column class="w-full flex-1">
    @if ($organization)
        @php($user = $organization->organization)
        {{--
            Commits and Mentions as pages you swipe between, under the tabs the pager draws, as on a profile, with the
            header drawn at the top of the page you're on, so it scrolls up with it until the tabs pin under the nav bar.
        --}}
        <native:pager
            native:key="organization-pager"
            ref="organization-pager"
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
            a11y-label="Commits and mentions"
        >
            <native:pager-header>
                @include('native.partials.organization-header')
            </native:pager-header>

            @foreach ($pages as $page)
                @include('native.partials.organization-page', ['page' => $page])
            @endforeach

            <native:pager-overlay>
                @include('native.partials.new-commits-pill', ['label' => $newCommitsLabel, 'press' => 'showNewCommits', 'shown' => $newCommits !== []])
            </native:pager-overlay>
        </native:pager>
    @else
        {{-- devnull's error isn't printed: the page says itself when it didn't load, and offers the page on the web. --}}
        <native:list native:key="organization-{{ $listKey }}" class="w-full flex-1 bg-theme-surface" on-refresh="load">
            @if ($error)
                @include('native.partials.empty', [
                    'command' => '$ git log --grep=@'.$handle,
                    'message' => "This organization's page didn't load here.",
                    'link' => 'Open it on dev/null',
                    'press' => 'openOnWeb',
                ])
            @else
                <native:column ref="loading-organization" class="w-full items-center py-16 bg-theme-surface" a11y-label="Loading organization">
                    <native:activity-indicator />
                </native:column>
            @endif
        </native:list>
    @endif
    </native:column>
</native:column>
