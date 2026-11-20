{{--
    One tab's page in the profile's pager (see App\NativeComponents\Profile::page()): the tab you're on, or the first
    few commits kept for the other, ready for a swipe.

    The pager draws the header and the tabs at the top of this list, so they scroll with it. As on the Log, each tab has
    its own list, a new $page['listKey'] puts it back at the top, and only the tab you're on asks for its next page.
    The pinned commit, the skeleton rows and the row at the end are kept in the tab's row cache with its commits, so
    a frame only builds what changed.
--}}
<native:column native:key="page-{{ $page['tab'] }}" class="w-full h-full bg-theme-surface">
    <native:list
        native:key="profile-{{ $page['tab'] }}-{{ $page['listKey'] }}"
        :ref="$page['showing'] ? 'profile' : 'profile-'.$page['tab']"
        class="w-full flex-1 bg-theme-surface"
        on-refresh="load"
        :on-end-reached="$page['showing'] ? 'loadMore' : null"
        :end-reached-buffer="5"
    >
        @if (! $page['loaded'] && $page['error'] === null)
            @foreach (range(1, 3) as $skeleton)
                @php
                    $page['rowCache']->include('loading-'.$skeleton, [], 'native.partials.rail-loading', ['ref' => 'loading-'.$skeleton, 'label' => 'Loading commits', 'pulse' => $page['showing']], []);
                @endphp
            @endforeach
        @endif

        @if ($page['loaded'] && ! $sketched && $page['isEmpty'] && $page['pinnedCommit'] === null && $page['error'] === null)
            @if ($page['tab'] === 'replies')
                @include('native.partials.empty', ['command' => 'fatal: no replies yet', 'message' => $isMe ? 'Your replies show up here. Join a conversation from your log.' : "{$user->handle()} hasn't replied to anything yet."])
            @elseif ($isMe)
                @include('native.partials.empty', ['command' => 'fatal: you do not have any commits yet', 'message' => 'Your commits show up here. Make your first one from your log.'])
            @else
                @include('native.partials.empty', ['command' => 'fatal: no commits yet', 'message' => "{$user->handle()} hasn't committed anything yet."])
            @endif
        @endif

        @if ($page['pinnedCommit'])
            {{-- The pinned commit sits above the rest, marked in mono as on the web, with no rail above it. --}}
            <native:row ref="pinned" class="w-full bg-theme-surface">
                <native:column :width="64" />
                <native:row class="flex-1 pt-3 gap-[6] items-center">
                    <native:icon :ios="\App\Icons\Lucide::Pin->ios()" :android="\App\Icons\Lucide::Pin->android()" :size="12" class="text-theme-ink-muted" />
                    <native:text font="mono" class="text-xs text-theme-ink-muted">Pinned</native:text>
                </native:row>
            </native:row>
            @php
                $rowScope = array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]);
                $page['rowCache']->include('pinned-'.$page['pinnedCommit']->id, \App\Devnull\RowInputs::for($page['pinnedCommit'], $rowScope), 'native.partials.commit-row', ['commit' => $page['pinnedCommit'], 'previousLane' => null, 'continuesRun' => false, 'rowKey' => 'pinned-'.$page['pinnedCommit']->id], $rowScope);
            @endphp
        @endif

        @include('native.partials.feed', ['rows' => $page['rows'], 'threads' => $page['threads'], 'rowCache' => $page['rowCache']])

        @if (! $page['isEmpty'] || $page['pinnedCommit'])
            @if ($page['nextCursor'] !== null)
                @php
                    $page['rowCache']->include('loading-more', [], 'native.partials.rail-loading', ['pulse' => $page['showing']], []);
                @endphp
            @elseif ($page['tab'] === 'replies')
                @php
                    $page['rowCache']->include('root-commit', [], 'native.partials.rail-end', ['lane' => $user->lane(), 'message' => $isMe ? 'That was your first reply.' : "That's {$user->handle()}'s first reply."], []);
                @endphp
            @else
                @php
                    $page['rowCache']->include('root-commit', [], 'native.partials.rail-end', ['lane' => $user->lane(), 'message' => $isMe ? 'That was your first commit. Everyone starts somewhere.' : "That's {$user->handle()}'s root commit."], []);
                @endphp
            @endif
        @endif
    </native:list>
</native:column>
