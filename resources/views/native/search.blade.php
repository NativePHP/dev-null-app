{{--
    The web's search: one field for developers or commits. Before you type, the web's searches to try. Then the
    developers whose name or handle matches, then the commits nearest in meaning, on a rail of their own as the web's
    results page draws them. A term with nothing near it answers in git's voice.
--}}
<native:column class="w-full h-full bg-theme-background">
    @include('native.partials.error')
    @include('native.partials.refresh-failed')
    @include('native.partials.commit-sheet')
    @include('native.partials.live-stream-sheet')

    <native:column class="w-full px-4 py-3 bg-theme-surface">
        @include('native.partials.people-search', ['placeholder' => 'Search developers or commits', 'autofocus' => true, 'a11yLabel' => 'Search developers or commits'])
    </native:column>
    <native:column class="w-full h-[1] bg-theme-hairline" />

    <native:list native:key="search" ref="search-results" class="w-full flex-1 bg-theme-surface">
        @if ($term === '')
            <native:row ref="search-suggestions-heading" class="w-full px-4 pt-6 pb-3 gap-2 items-center bg-theme-surface">
                <native:icon :ios="\App\Icons\Lucide::Sparkles->ios()" :android="\App\Icons\Lucide::Sparkles->android()" :size="16" class="text-theme-primary" />
                <native:text font="semibold" class="text-base text-theme-ink">Try searching for…</native:text>
            </native:row>
            @foreach (\App\NativeComponents\Search::Suggestions as $suggestion)
                <native:column native:key="suggestion-{{ \Illuminate\Support\Str::slug($suggestion) }}" class="w-full px-4 pb-2 bg-theme-surface">
                    <native:pressable ref="suggestion-{{ \Illuminate\Support\Str::slug($suggestion) }}" @press="pick('{{ $suggestion }}')" class="w-full rounded-lg border border-theme-hairline bg-theme-background" a11y-label="Search for {{ $suggestion }}">
                        <native:row class="w-full px-4 py-[10] gap-3 items-center">
                            <native:text class="flex-1 text-sm text-theme-ink" :max-lines="1">{{ $suggestion }}</native:text>
                            <native:icon :ios="\App\Icons\Lucide::ChevronRight->ios()" :android="\App\Icons\Lucide::ChevronRight->android()" :size="16" class="text-theme-ink-muted" />
                        </native:row>
                    </native:pressable>
                </native:column>
            @endforeach
        @else
            @if ($people !== [])
                <native:row ref="developers-heading" class="w-full min-h-[44] px-4 py-3 border-b border-theme-hairline bg-theme-surface">
                    <native:text font="semibold" class="text-base text-theme-ink">Developers</native:text>
                </native:row>
                @foreach ($people as $person)
                    @include('native.partials.connection', ['person' => $person, 'isMe' => $person->user->username === $viewer?->username, 'showsFollowsYou' => true, 'myColour' => $myColour])
                @endforeach
            @endif

            @if ($searchesCommits)
                <native:row ref="commits-heading" class="w-full min-h-[44] px-4 py-3 gap-2 items-center border-b border-theme-hairline bg-theme-surface">
                    <native:text font="semibold" class="flex-1 text-base text-theme-ink">Most relevant commits</native:text>
                    @if ($commitsFoundFor !== null)
                        <native:text ref="commits-count" font="mono" class="text-xs text-theme-ink-muted">{{ count($commits) }} {{ count($commits) === 1 ? 'match' : 'matches' }}</native:text>
                    @endif
                </native:row>

                @if ($commitsFoundFor === null && $error === null)
                    @foreach (range(1, 3) as $skeleton)
                        @include('native.partials.rail-loading', ['ref' => 'loading-'.$skeleton, 'label' => 'Searching commits'])
                    @endforeach
                @elseif ($commitsFoundFor !== null && $commits === [])
                    @include('native.partials.empty', [
                        'command' => "fatal: nothing close to '{$commitsFoundFor}'",
                        'message' => 'Try describing the idea in other words.',
                    ])
                @else
                    @include('native.partials.feed', ['rows' => $rows, 'rowCache' => $rowCache])
                @endif
            @elseif ($peopleFoundFor === $term && $people === [])
                <native:column ref="people-none" class="w-full px-8 py-12 items-center bg-theme-surface">
                    <native:text font="mono" class="text-[13px] text-center text-theme-ink-muted" :max-lines="1">fatal: nothing matches '{{ $term }}'</native:text>
                </native:column>
            @endif
        @endif
    </native:list>
</native:column>
