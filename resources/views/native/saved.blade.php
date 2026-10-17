<native:column class="w-full h-full bg-theme-background">
    @include('native.partials.error')
    @include('native.partials.refresh-failed')
    @include('native.partials.commit-sheet')

    <native:list native:key="saved" ref="saved" class="w-full flex-1 bg-theme-surface" on-refresh="load" on-end-reached="loadMore" :end-reached-buffer="5">
        {{-- What the web's Saved page says above the list: they're yours alone, last saved first. --}}
        <native:column ref="saved-intro" class="w-full px-4 py-3 gap-1 bg-theme-surface">
            <native:row class="gap-[6] items-center">
                <native:icon :ios="\App\Icons\Lucide::LockKeyhole->ios()" :android="\App\Icons\Lucide::LockKeyhole->android()" :size="14" class="text-theme-ink-muted" />
                <native:text class="text-sm text-theme-ink-muted">Only you</native:text>
            </native:row>
            <native:text class="w-full text-sm text-theme-ink-muted">Commits you want to come back to. Most recently saved first.</native:text>
        </native:column>
        <native:column class="w-full h-[1] bg-theme-hairline" />

        @if (! $loaded && $error === null)
            @foreach (range(1, 3) as $skeleton)
                @include('native.partials.rail-loading', ['ref' => 'loading-'.$skeleton, 'label' => 'Loading saved commits'])
            @endforeach
        @endif

        @if ($loaded && $commits === [] && $error === null)
            @include('native.partials.empty', [
                'command' => 'fatal: no saved commits yet',
                'message' => 'Save a commit to find it here. Your saved commits are private.',
                'link' => 'Explore Trending',
                'press' => 'exploreTrending',
            ])
        @endif

        @include('native.partials.feed', ['rows' => $rows])

        @if ($lastCommit)
            @if ($nextCursor !== null)
                @include('native.partials.rail-loading')
            @else
                @include('native.partials.rail-end', ['lane' => $lastCommit->lane(), 'message' => "You've reached the end of your saved commits."])
            @endif
        @endif
    </native:list>
</native:column>
