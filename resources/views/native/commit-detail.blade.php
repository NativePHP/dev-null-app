<native:column class="w-full h-full bg-theme-background">
    @include('native.partials.error')
    @include('native.partials.refresh-failed')
    @include('native.partials.commit-sheet')

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

            {{--
                A pushed screen runs under its bottom bar, so the page clears the reply bar itself (see
                Display::replyBarClearance): the last reply and its actions end above the bar, keyboard up or down.
            --}}
            @if ($commit)
                <native:column ref="reply-bar-clearance" class="w-full h-[{{ 24 + \App\Devnull\Display::replyBarClearance($reply, $replyRestored) }}]" />
            @endif
        </native:column>
    </native:scroll-view>

    @if ($commit)
        {{--
            The bar is painted from its first child's background: native:bottom-bar takes no classes of its own, so
            the surface and the hairline above it belong to this column, or the replies show through. On iOS the bar
            is bare and the field is a Liquid Glass plate instead, the way the HIG floats controls over content; the
            hairline stays as a clear 1pt so the bar keeps the height Display::ReplyBarHeight counts on.

            The expand button opens the reply in the composer sheet, which has the photos and preview the bar has no
            room for. On iOS it sits inside the plate at its trailing edge, where iOS puts a field's own controls, and
            stays at the bottom as the field grows. On Android it leads the bar, ahead of the field.
        --}}
        @php($expandInField = \Native\Mobile\Platform::isIos())
        <native:bottom-bar>
            <native:column ref="reply-bar" class="w-full android:bg-theme-surface">
                <native:column class="w-full h-[1] android:bg-theme-hairline" />
                <native:row ref="reply-row" class="w-full {{ $expandInField ? 'pl-4' : 'pl-2' }} pr-4 py-2 gap-2 items-center">
                    @unless ($expandInField)
                        @include('native.partials.composer-tool', ['ref' => 'reply-expand', 'icon' => \App\Icons\Lucide::Maximize2, 'label' => 'Reply with photos or a preview', 'press' => "replyInSheet('{$commit->id}')"])
                    @endunless
                    <native:row ref="reply-plate" class="flex-1 items-end ios:pr-1 ios:rounded-[18] ios:glass">
                        {{--
                            EDGE sends no focus event, so a press on the field is how the page learns you're replying.
                            The expand button is beside it rather than in it, so pressing it doesn't count.
                        --}}
                        <native:column ref="reply-field" class="flex-1 ios:pl-4" @pressUp="replyTapped">
                            <native:bare-text-input
                                ref="reply-input"
                                class="w-full py-2 text-theme-ink"
                                placeholder="Reply to {{ $commit->author->handle() }}"
                                native:model.debounce.150ms="reply"
                                :autofocus="$focusReply"
                                multiline
                                :max-lines="5"
                                :max-length="\App\Devnull\Data\Commit::MaxRawLength"
                            />
                        </native:column>
                        @if ($expandInField)
                            @include('native.partials.composer-tool', ['ref' => 'reply-expand', 'icon' => \App\Icons\Lucide::Maximize2, 'label' => 'Reply with photos or a preview', 'press' => "replyInSheet('{$commit->id}')"])
                        @endif
                    </native:row>
                    @include('native.partials.body-counter', ['body' => $reply, 'ref' => 'reply-budget'])
                    <native:button ref="send-reply" :variant="$myColour" size="sm" :disabled="! \App\Devnull\Data\Commit::fits($reply)" @press="sendReply">Reply</native:button>
                </native:row>
                @if (\App\Devnull\Data\CommitBody::hasOversizedUrl($reply))
                    <native:text ref="reply-link-error" class="w-full px-4 pb-2 text-sm text-theme-destructive">Each link must not be greater than {{ \App\Devnull\Data\BodyLink::MaxLength }} characters.</native:text>
                @endif
                {{-- A reply stashed when you left the commit comes back with the web's stash@{0} and drop under it. --}}
                @if ($replyRestored)
                    <native:row class="w-full px-4 -mt-2">
                        @include('native.partials.stash-label', ['ref' => 'reply-stash', 'drop' => 'dropReplyStash', 'size' => 'text-xs'])
                    </native:row>
                @endif
            </native:column>
        </native:bottom-bar>
    @endif
</native:column>
