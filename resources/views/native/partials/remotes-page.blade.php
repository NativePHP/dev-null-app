{{--
    One tab's page in the Remotes pager (see App\NativeComponents\Remotes::page()): the tab you're on, or the first few
    rows kept for one beside it, ready for a swipe. Each tab has its own list, and one you leave starts again at the top
    of a fresh one, as on Notifications. Only the tab you're on asks for its next page.
--}}
<native:column native:key="page-{{ $page['tab'] }}" class="w-full h-full bg-theme-surface">
    <native:list
        native:key="remotes-{{ $page['tab'] }}-{{ $page['listKey'] }}"
        :ref="$page['showing'] ? 'remotes' : 'remotes-'.$page['tab']"
        class="w-full flex-1 bg-theme-surface"
        on-refresh="load"
        :on-end-reached="$page['showing'] ? 'loadMore' : null"
        :end-reached-buffer="5"
    >
        @if (! $page['loaded'] && $page['error'] === null)
            @foreach (range(1, 4) as $skeleton)
                @include('native.partials.remote-row-loading', ['ref' => ($page['showing'] ? '' : $page['tab'].'-').'loading-'.$skeleton])
            @endforeach
        @endif

        @if ($page['loaded'] && $page['rows'] === [] && $page['error'] === null)
            @include('native.partials.empty', match ($page['tab']) {
                'stashed' => ['command' => 'nothing stashed', 'message' => 'Stash a conversation from its menu. It pops back into Open when someone writes.'],
                'fetched' => ['command' => 'no requests fetched', 'message' => 'First messages from people you don’t follow wait here until you merge or drop them.'],
                default => ['command' => 'no conversations yet', 'message' => 'Start one with New remote, or from a profile with Remote.'],
            })
        @endif

        @foreach ($page['rows'] as $row)
            @include('native.partials.remote-row', $row)
        @endforeach

        @if ($page['loaded'] && $page['hasMore'])
            @include('native.partials.remote-row-loading', ['ref' => 'loading-more'])
        @endif
    </native:list>
</native:column>
