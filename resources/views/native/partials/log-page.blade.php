{{--
    One log's page in the Log's pager (see App\NativeComponents\Log::page()): the log you're on, a log you've left, kept
    as you left it, or the first few commits kept for one you haven't been on yet, ready for a swipe.

    Each log has its own list, and keeps it when you swipe away, so the phone still has it scrolled where you were when
    you swipe back. A new $page['listKey'] puts it back at the top, as the pill does.

    Every list says which rows are on screen as you scroll, naming its log, so only the rows near them are drawn (see
    App\Devnull\RowWindow). The logs you're not on say so too: the phone only draws a placeholder at its row's height
    in a list that does, and at a guess in one that doesn't, which would move every row under a kept scroll offset.
    Only the log you're on asks for its next page, naming its log too, so a list that reaches its end as you swipe away
    from it doesn't load more of the one you swipe to. Every page keeps pull to refresh, which can only be pulled on the
    log you're on anyway: iOS builds a new list when a list gains or loses it, and the new one starts at the top.
--}}
@php
    $loadMore = "loadMore('{$page['log']}')";
@endphp
<native:column native:key="page-{{ $page['log'] }}" class="w-full h-full bg-theme-surface">
    <native:list
        native:key="log-{{ $page['log'] }}-{{ $page['listKey'] }}"
        :ref="$page['showing'] ? 'log' : 'log-'.$page['log']"
        class="w-full flex-1 bg-theme-surface"
        on-refresh="load"
        :on-end-reached="$page['showing'] ? $loadMore : null"
        :end-reached-buffer="5"
        on-visible-range="showRows('{{ $page['log'] }}')"
        :visible-range-step="20"
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
