<native:column class="w-full h-full bg-theme-background">
    @include('native.partials.error')
    @include('native.partials.refresh-failed')

    <native:list native:key="guilds" ref="guilds" class="w-full flex-1 bg-theme-surface" on-refresh="load" on-end-reached="loadMore" :end-reached-buffer="5">
        @if ($unavailable)
            @include('native.partials.empty', [
                'command' => 'fatal: guilds are not available',
                'message' => "Guilds aren't open to you yet.",
            ])
        @else
            {{--
                What the web's Guilds page says above the list, then its search.
                The hairline is in the same row, as on Starred.

                The web also says "Join to see their commits in Following.", but we'll hold
                off on adding that in the app until users of the app are able to join a guild.
                We can't add guild joining until the upstream APIs are merged and available.
            --}}
            <native:column ref="guilds-intro" class="w-full bg-theme-surface">
                <native:column class="w-full px-4 pt-3 pb-3 gap-2">
                    <native:text class="w-full text-sm text-theme-ink-muted">Public groups, biggest first.</native:text>
                    <native:row ref="guild-search" class="w-full h-[40] px-3 gap-2 items-center rounded-lg border border-theme-hairline bg-theme-background">
                        <native:icon :ios="\App\Icons\Lucide::Search->ios()" :android="\App\Icons\Lucide::Search->android()" :size="16" class="text-theme-ink-muted" />
                        <native:column class="flex-1">
                            <native:bare-text-input
                                ref="guild-search-field"
                                class="w-full text-theme-ink"
                                placeholder="Search guilds"
                                native:model.debounce.300ms="term"
                                @submit="search"
                                :max-length="100"
                                a11y-label="Search guilds"
                            />
                        </native:column>
                    </native:row>
                    <native:text class="w-full text-xs text-theme-ink-muted">Search names, #handles, and descriptions.</native:text>
                    <native:row ref="guilds-create" class="w-full pt-1 gap-3 items-center">
                        <native:text class="flex-1 text-sm text-theme-ink-muted">Start a public group.</native:text>
                        <native:button ref="create-guild" variant="secondary" size="sm" :ios-icon="\App\Icons\Lucide::Plus->ios()" :android-icon="\App\Icons\Lucide::Plus->android()" @press="createGuild">Create a guild</native:button>
                    </native:row>
                </native:column>
                <native:column class="w-full h-[1] bg-theme-hairline" />
            </native:column>

            @if (! $loaded && $error === null)
                @foreach (range(1, 3) as $skeleton)
                    @include('native.partials.rail-loading', ['ref' => 'loading-'.$skeleton, 'label' => 'Loading guilds'])
                @endforeach
            @endif

            @if ($loaded && $guilds === [] && $error === null)
                @if (trim($term) === '')
                    @include('native.partials.empty', [
                        'command' => 'fatal: no guilds yet',
                        'message' => 'No guilds yet.',
                    ])
                @else
                    @include('native.partials.empty', [
                        'command' => 'fatal: no guilds match',
                        'message' => 'No guilds found. Try another keyword.',
                        'link' => 'Browse all guilds',
                        'press' => 'browseAll',
                    ])
                @endif
            @endif

            @foreach ($guilds as $guild)
                @include('native.partials.guild-row')
            @endforeach

            @if ($nextPage !== null)
                @include('native.partials.rail-loading', ['ref' => 'loading-more', 'label' => 'Loading more guilds'])
            @endif
        @endif
    </native:list>
</native:column>
