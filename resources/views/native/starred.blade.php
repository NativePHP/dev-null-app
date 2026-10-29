<native:column class="w-full h-full bg-theme-background">
    @include('native.partials.error')
    @include('native.partials.refresh-failed')
    @include('native.partials.commit-sheet')
    @include('native.partials.live-stream-sheet')

    <native:list native:key="starred" ref="starred" class="w-full flex-1 bg-theme-surface" on-refresh="load" on-end-reached="loadMore" :end-reached-buffer="5">
        {{--
            What the web's Starred page says above the list: they're yours alone, newest first. The hairline is in
            the same row: as a list row of its own, iOS laid it out far taller than 1pt, with a gap either side.
        --}}
        <native:column ref="starred-intro" class="w-full bg-theme-surface">
            <native:column class="w-full px-4 py-3 gap-1">
                <native:row class="gap-[6] items-center">
                    <native:icon :ios="\App\Icons\Lucide::LockKeyhole->ios()" :android="\App\Icons\Lucide::LockKeyhole->android()" :size="14" class="text-theme-ink-muted" />
                    <native:text class="text-sm text-theme-ink-muted">Only you</native:text>
                </native:row>
                <native:text class="w-full text-sm text-theme-ink-muted">Commits you starred, newest first.</native:text>
            </native:column>
            <native:column class="w-full h-[1] bg-theme-hairline" />
        </native:column>

        @if (! $loaded && $error === null)
            @foreach (range(1, 3) as $skeleton)
                @include('native.partials.rail-loading', ['ref' => 'loading-'.$skeleton, 'label' => 'Loading starred commits'])
            @endforeach
        @endif

        @if ($loaded && $commits === [] && $error === null)
            @include('native.partials.empty', [
                'command' => 'fatal: no starred commits yet',
                'message' => 'Star a commit to find it here.',
                'link' => 'Explore Trending',
                'press' => 'exploreTrending',
            ])
        @endif

        @include('native.partials.feed', ['rows' => $rows, 'rowCache' => $rowCache])

        @if ($lastCommit)
            @if ($nextCursor !== null)
                @include('native.partials.rail-loading')
            @else
                @include('native.partials.rail-end', ['lane' => $lastCommit->lane(), 'message' => "You've reached the end of your starred commits."])
            @endif
        @endif
    </native:list>
</native:column>
