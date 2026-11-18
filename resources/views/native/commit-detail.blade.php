<native:column class="w-full h-full bg-theme-background">
    @include('native.partials.error')
    @include('native.partials.refresh-failed')
    @include('native.partials.commit-sheet')
    @include('native.partials.live-stream-sheet')
    @include('native.partials.stargazers-sheet')

    <native:scroll-view native:key="thread-{{ $scrollKey }}" ref="thread-scroll" class="w-full flex-1 bg-theme-surface">
        <native:column ref="thread-page" class="w-full bg-theme-surface">
            @if (! $commit && $error === null)
                <native:column ref="loading-commit" class="w-full items-center py-16" a11y-label="Loading commit">
                    <native:activity-indicator />
                </native:column>
            @endif

            {{--
                The commit is the main thing, near the top: larger, in full ink, with its git date and its replies behind
                a toggle in its action row. The commit it replies to shows above it, and what that replies to waits
                behind "↑ N earlier commits", since EDGE can't open the page scrolled down to the commit. Opened, the
                ancestors, the commit and the rest of its series share one rail, so someone replying to themselves shows
                their avatar once. The replies hang under it on the thread's dashed lines, with the rail ending in a dot
                above them, five at a time with "Show more" under the last. A part of a series with more parts after it
                has its replies right under it, beside the rail running on, and the later parts follow. Everything
                around the commit is muted, and still opens its own page. A deleted reply's own replies open in place,
                one level in, five at a time too, since a deleted commit has no page.
            --}}
            @if ($earlierCount > 0)
                @include('native.partials.earlier-commits', ['count' => $earlierCount, 'lane' => $earlierLane])
            @endif

            @foreach ($rows as $row)
                @php($isFocused = $row->commit->id === $commit?->id)
                @php($hasThread = $isFocused && $repliesShown && $replies !== [])
                @include('native.partials.commit-row', [
                    'commit' => $row->commit,
                    'previousLane' => $row->previousLane,
                    'continuesRun' => $row->continuesRun,
                    'continuesNext' => $isFocused ? ! $loop->last : null,
                    'threadBelow' => $hasThread,
                    'focused' => $isFocused,
                    'muted' => ! $isFocused,
                    'seriesExpanded' => true,
                    'withTail' => ! $loop->last,
                ])
                @if ($isFocused && $repliesShown && $replies === [] && ! $repliesLoaded && $error === null)
                    <native:row ref="loading-replies" class="w-full pl-[64] pr-4 py-4 gap-2 items-center" a11y-label="Loading replies">
                        <native:activity-indicator size="small" />
                    </native:row>
                @endif
                @if ($hasThread)
                    @php($railLane = $loop->last ? null : $commit->lane())
                    @foreach ($replies as $index => $threadReply)
                        @php($nested = $tombstoneReplies[$threadReply->id] ?? [])
                        @php($nestedMore = ($tombstoneCursors[$threadReply->id] ?? null) !== null)
                        @php($hasNext = ! $loop->last || $repliesCursor !== null)
                        @php($replyRow = [
                            'reply' => $threadReply,
                            'lane' => $commit->lane(),
                            'previousLane' => $index === 0 ? null : $replies[$index - 1]->lane(),
                            'hasNext' => $hasNext,
                            'railLane' => $railLane,
                            'tombstones' => true,
                            'muted' => true,
                            'thread' => $commit,
                            'more' => $loop->last && $nested === [] && $repliesCursor !== null ? 'loadMoreReplies' : null,
                            'last' => $loop->last && $nested === [],
                        ])
                        @php($rowScope = array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))
                        @php($replyRows->include('reply-'.$threadReply->id, \App\Devnull\RowInputs::for($threadReply, $rowScope), 'native.partials.thread-reply', $replyRow, $rowScope))
                        @foreach ($nested as $nestedIndex => $nestedReply)
                            @php($nestedRow = [
                                'reply' => $nestedReply,
                                'lane' => \App\Devnull\Lane::Rail,
                                'previousLane' => $nestedIndex === 0 ? null : $nested[$nestedIndex - 1]->lane(),
                                'hasNext' => ! $loop->last || $nestedMore,
                                'railLane' => $railLane,
                                'indent' => 32,
                                'spine' => $hasNext ? $threadReply->lane() : null,
                                'muted' => true,
                                'thread' => $nestedMore ? $threadReply : $commit,
                                'more' => match (true) {
                                    ! $loop->last => null,
                                    $nestedMore => "loadMoreTombstoneReplies('{$threadReply->id}')",
                                    $loop->parent->last && $repliesCursor !== null => 'loadMoreReplies',
                                    default => null,
                                },
                                'last' => $loop->last && $loop->parent->last,
                            ])
                            @php($rowScope = array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))
                            @php($replyRows->include('reply-'.$nestedReply->id, \App\Devnull\RowInputs::for($nestedReply, $rowScope), 'native.partials.thread-reply', $nestedRow, $rowScope))
                        @endforeach
                    @endforeach
                @endif
            @endforeach

            @if ($commit && $replyCount === 0 && $repliesLoaded && $series === [])
                <native:column class="w-full pl-[64] pr-4 py-4">
                    <native:text class="text-sm text-theme-ink-muted">No replies yet.</native:text>
                </native:column>
            @endif

            @if ($commit && $related !== [])
                @include('native.partials.related-commits')
            @endif

            {{--
                A pushed screen runs under its bottom bar, so the page clears the reply bar itself (see
                Display::replyBarClearance): the last reply and its actions end above the bar, keyboard up or down.
            --}}
            @if ($commit)
                <native:column ref="reply-bar-clearance" class="w-full h-[{{ 24 + \App\Devnull\Display::replyBarClearance($reply, $replyExpanded, $replyRestored) }}]" />
            @endif
        </native:column>
    </native:scroll-view>

    @if ($commit)
        {{--
            The reply bar, drawn as the Remotes composer is: one rounded box with the field across the top and a row
            under it with the tools on the left and the round send button on the right (see partials/composer-box). The
            bar is Liquid Glass tinted with the surface on iOS, so the thread scrolling under it blurs, and the solid
            surface with a hairline on Android. native:bottom-bar takes no classes of its own, so the bar is painted
            from this column.

            The expand button grows the box where it is, the same field in it, so the keyboard stays up, and shrinks
            it back. A sheet can't take the reply over with the keyboard up: presenting one takes the keyboard down
            and back up on both platforms. Grown, the box also has the button for photos and a preview, which opens
            the reply in the composer sheet, and once the sheet has put photos on the draft, their count opens the
            sheet on them. Beside the send button, how long the reply is. A reply that came back when you returned to
            the commit has the web's stash@{0} and drop under the box, on a row of its own, since the box's row has no
            room left for it once it has grown.
        --}}
        <native:bottom-bar>
            <native:column ref="reply-bar" class="w-full android:bg-theme-surface ios:bg-theme-surface/70 ios:glass">
                <native:column class="w-full h-[1] android:bg-theme-hairline" />
                {{--
                    The people to mention keep a slot above the box, empty and 0.1pt tall when there's no one, so the
                    box's row never moves. Android rebuilds a row that moves among its siblings, field and all, which
                    drops the keyboard. On iOS an empty slot would otherwise take 10pt of the bar, and EDGE drops a
                    height of 0.
                --}}
                @php($mentioning = ($mention['field'] ?? null) === 'reply' && ($mentionPeople !== [] || $mentionOrganizations !== []))
                <native:row native:key="reply-mention-slot" ref="reply-mention-slot" class="w-full {{ $mentioning ? 'pt-1 ios:px-4' : 'h-[0.1]' }}">
                    @include('native.partials.mention-suggestions', ['field' => 'reply', 'label' => 'Mention a developer'])
                </native:row>
                <native:row native:key="reply-row" ref="reply-row" class="w-full px-4 py-2">
                    @php($photoCount = $replyImages === [] ? null : \App\Devnull\Display::count(count($replyImages), 'photo', 'photos'))
                    @include('native.partials.composer-box', [
                        'ref' => 'reply',
                        'field' => 'native.partials.reply-field',
                        'tools' => [
                            $replyExpanded
                                ? ['ref' => 'reply-expand', 'icon' => \App\Icons\Lucide::Minimize2, 'label' => 'Give the reply less room', 'press' => 'toggleReplyExpanded']
                                : ['ref' => 'reply-expand', 'icon' => \App\Icons\Lucide::Maximize2, 'label' => 'Give the reply more room', 'press' => 'toggleReplyExpanded'],
                            ...($replyExpanded ? [
                                ['ref' => 'reply-attach', 'icon' => \App\Icons\Lucide::ImagePlus, 'label' => 'Reply with photos or a preview', 'press' => "replyInSheet('{$commit->id}')"],
                            ] : []),
                            ...($photoCount === null ? [] : [
                                ['ref' => 'reply-photos', 'icon' => \App\Icons\Lucide::ImagePlus, 'text' => $photoCount, 'label' => $photoCount.' attached', 'hint' => 'Opens the reply with its photos', 'press' => "replyInSheet('{$commit->id}')"],
                            ]),
                        ],
                        'trail' => [['native.partials.body-counter', ['body' => $reply, 'ref' => 'reply-budget']]],
                        'send' => [
                            'ref' => 'send-reply',
                            'label' => 'Reply',
                            'press' => 'sendReply',
                            'disabled' => ! \App\Devnull\Data\Draft::canCommit($reply, $replyImages),
                        ],
                    ])
                </native:row>
                @if ($replyRestored)
                    <native:row ref="reply-stash-row" class="w-full px-5 -mt-2 justify-end">
                        @include('native.partials.stash-label', ['ref' => 'reply-stash', 'drop' => 'dropReplyStash', 'size' => 'text-xs'])
                    </native:row>
                @endif
                @if (\App\Devnull\Data\CommitBody::hasOversizedUrl($reply))
                    <native:text ref="reply-link-error" class="w-full px-4 pb-2 text-sm text-theme-destructive">Each link must not be greater than {{ \App\Devnull\Data\BodyLink::MaxLength }} characters.</native:text>
                @endif
            </native:column>
        </native:bottom-bar>
    @endif
</native:column>
