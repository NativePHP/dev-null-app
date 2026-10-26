{{--
    One log's page in the Log's pager (see App\NativeComponents\Log::page()): the log you're on, or the first few
    commits kept for another, ready for a swipe.

    Each log has its own list. A new $page['listKey'] puts it back at the top, as the pill does. A list kept while every
    row changed under it kept its scroll offset and row heights, and iOS drew it blank with the first rows pushed to the
    bottom, so a page only gets new rows under it when it can't have been scrolled.

    Only the log you're on asks for its next page: a page laid out off screen still shows its rows, and its last ones
    would ask for more of the log you're on. It also says which rows are on screen as you scroll, so only the rows
    near them are drawn (see App\Devnull\RowWindow), and when it comes to rest, when what waited while it moved goes in.
    Every page keeps pull to refresh, which can only be pulled on the log you're on anyway: iOS builds a new list when a
    list gains or loses it, and the new one starts at the top.
--}}
<native:column native:key="page-{{ $page['log'] }}" class="w-full h-full bg-theme-surface">
    <native:list
        native:key="log-{{ $page['log'] }}-{{ $page['listKey'] }}"
        :ref="$page['showing'] ? 'log' : 'log-'.$page['log']"
        class="w-full flex-1 bg-theme-surface"
        on-refresh="load"
        :on-end-reached="$page['showing'] ? 'loadMore' : null"
        :end-reached-buffer="5"
        :on-visible-range="$page['showing'] ? 'showRows' : null"
        :visible-range-step="20"
        :on-scroll-end="$page['showing'] ? 'scrollEnded' : null"
    >
        @if (! $page['loaded'] && $page['error'] === null)
            @foreach (range(1, 3) as $skeleton)
                @include('native.partials.rail-loading', ['ref' => 'loading-'.$skeleton, 'label' => 'Loading commits', 'pulse' => $page['showing']])
            @endforeach
        @endif

        @if ($page['loaded'] && $page['isEmpty'] && $page['error'] === null)
            @if ($page['log'] === 'following')
                @include('native.partials.following-empty', ['suggestions' => $page['suggestions']])
            @else
                @include('native.partials.empty', [
                    'command' => 'fatal: no commits yet',
                    'message' => "Nobody has committed anything. Be the first. It's just plain text; you'll be fine.",
                ])
            @endif
        @endif

        @include('native.partials.feed', ['rows' => $page['rows'], 'threads' => $page['threads'], 'rowCache' => $page['rowCache'], 'rowWindow' => $page['rowWindow']])

        @if ($page['lastCommit'])
            @if ($page['nextCursor'] !== null)
                @include('native.partials.rail-loading', ['pulse' => $page['showing']])
            @else
                @include('native.partials.rail-end', ['lane' => $page['lastCommit']->lane(), 'message' => "That's the root commit. There's nothing before it."])
            @endif
        @endif
    </native:list>
</native:column>
