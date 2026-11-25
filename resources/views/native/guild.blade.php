{{--
    A guild's page (see App\NativeComponents\Guild): its header, then its bulletin board, the pinned commit on top under
    its label, then the rest newest first, more coming in as you scroll. One list, as the directory is, so the header
    scrolls away with the board.
--}}
<native:column class="w-full h-full bg-theme-background">
    @include('native.partials.error')
    @include('native.partials.refresh-failed')
    @include('native.partials.commit-sheet')
    @include('native.partials.live-stream-sheet')
    @include('native.partials.stargazers-sheet')

    {{--
        The new commits pill lies over the top of the board, as the pinned bar lies over a Remotes thread. The stack and
        the pill's slot are always there, so the pill coming or going never adds a node beside the list.
    --}}
    <native:stack ref="guild-stack" class="w-full flex-1">
        <native:list
            native:key="guild-{{ $listKey }}"
            ref="guild"
            class="w-full h-full bg-theme-surface"
            on-refresh="load"
            :on-end-reached="$guild !== null && $nextCursor !== null ? 'loadMore' : null"
            :end-reached-buffer="5"
        >
            @if ($unavailable)
                @include('native.partials.empty', [
                    'command' => 'fatal: guilds are not available',
                    'message' => "Guilds aren't open to you yet.",
                ])
            @elseif ($missing)
                @include('native.partials.empty', [
                    'command' => 'fatal: no such guild #'.$handle,
                    'message' => 'This guild is no longer available.',
                ])
            @elseif ($guild !== null)
                @include('native.partials.guild-header')

                <native:row ref="board-label" class="w-full px-4 py-3 border-b border-theme-hairline bg-theme-surface">
                    <native:text font="mono" class="text-xs text-theme-ink-muted">Bulletin board · Latest commits</native:text>
                </native:row>

                @if ($pinnedRows !== [])
                    <native:row ref="pinned-label" class="w-full px-4 pt-3 gap-[6] items-center bg-theme-surface">
                        <native:icon :ios="\App\Icons\Lucide::Pin->ios(true)" :android="\App\Icons\Lucide::Pin->android(true)" :size="14" class="text-theme-primary" />
                        <native:text class="text-xs text-theme-ink-muted">Pinned to board</native:text>
                    </native:row>
                    @include('native.partials.feed', ['rows' => $pinnedRows, 'threads' => $threads, 'rowCache' => $rowCache])
                @endif

                @if ($isEmpty)
                    @include('native.partials.empty', [
                        'command' => '$ git log --grep=#'.$handle,
                        'message' => 'No commits yet. Post to the board or include #'.$handle.' in a commit.',
                    ])
                @else
                    @include('native.partials.feed', ['rows' => $rows, 'threads' => $threads, 'rowCache' => $rowCache])

                    @if ($nextCursor !== null)
                        @include('native.partials.rail-loading')
                    @endif
                @endif
            @elseif ($error === null)
                <native:column ref="loading-guild" class="w-full items-center py-16 bg-theme-surface" a11y-label="Loading guild">
                    <native:activity-indicator />
                </native:column>
            @endif
        </native:list>

        <native:column class="absolute top-0 left-0 w-full">
            @include('native.partials.new-commits-pill', ['label' => $newCommitsLabel, 'press' => 'showNewCommits', 'shown' => $hasNewCommits])
        </native:column>
    </native:stack>
</native:column>
