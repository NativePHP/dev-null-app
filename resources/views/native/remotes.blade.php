{{--
    The web's Remotes inbox: Open, Fetched (while devnull takes requests, counting them) and Stashed along the top, in
    your lane's underline, then a row per conversation, the latest first. While you have Remotes off, the tab shows
    the web's page for /remotes then, its 404, word for word.
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

        <native:row ref="remotes-tabs" class="w-full px-4 gap-6 bg-theme-surface">
            @foreach ($tabs as $key => $label)
                <native:pressable ref="tab-{{ $key }}" @press="showTab('{{ $key }}')" a11y-label="{{ $label }}{{ $key === 'fetched' && $requestsCount > 0 ? ', '.$requestsCount.' waiting' : '' }}">
                    <native:column class="pt-3 gap-[10]">
                        <native:row class="gap-[6] items-center">
                            <native:text font="medium" class="text-sm {{ $key === $tab ? 'text-theme-ink' : 'text-theme-ink-muted' }}">{{ $label }}</native:text>
                            @if ($key === 'fetched' && $requestsCount > 0)
                                <native:column ref="requests-count" class="px-[6] rounded-full bg-theme-{{ $myColour }}">
                                    <native:text font="mono-medium" class="text-[11px] leading-5 text-theme-on-{{ $myColour }}">{{ $requestsCount }}</native:text>
                                </native:column>
                            @endif
                        </native:row>
                        <native:column class="w-full h-[2] rounded-full {{ $key === $tab ? 'bg-theme-'.$myColour : 'bg-theme-surface' }}" />
                    </native:column>
                </native:pressable>
            @endforeach
        </native:row>
        <native:column class="w-full h-[1] bg-theme-hairline" />

        <native:list native:key="remotes-{{ $tab }}" ref="remotes" class="w-full flex-1 bg-theme-surface" on-refresh="load" on-end-reached="loadMore" :end-reached-buffer="5">
            @if (! $loaded && $error === null)
                @foreach (range(1, 4) as $skeleton)
                    @include('native.partials.remote-row-loading', ['ref' => 'loading-'.$skeleton])
                @endforeach
            @endif

            @if ($loaded && $rows === [] && $error === null)
                @include('native.partials.empty', match ($tab) {
                    'stashed' => ['command' => 'nothing stashed', 'message' => 'Stash a conversation from its menu. It pops back into Open when someone writes.'],
                    'fetched' => ['command' => 'no requests fetched', 'message' => 'First messages from people you don’t follow wait here until you merge or drop them.'],
                    default => ['command' => 'no conversations yet', 'message' => 'Start one from a profile with Remote.'],
                })
            @endif

            @foreach ($rows as $row)
                @include('native.partials.remote-row', $row)
            @endforeach

            @if ($loaded && $hasMore)
                @include('native.partials.remote-row-loading', ['ref' => 'loading-more'])
            @endif
        </native:list>
    @endif
</native:column>
