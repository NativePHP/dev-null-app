{{--
    "Show more" at the foot of a thread opened in a feed (see App\Devnull\ThreadRows), as a list row of its own: a muted
    rail dot where the next avatar would be, on the dashed line down from the reply above ($lane), and the words.
    Tapped, the thread's next page opens under what's showing. $indent puts it one step in, under a reply's own replies.

    It's 44pt tall, as tall as the list's shortest row, so the list never pads it out under its hairline. The dot sits
    in its middle, so the line from above stops at the top of the dot, 13pt down.
--}}
@php($indent ??= 0)
@php($spine ??= null)
@php($railLane ??= null)
@php($faded = in_array('more-'.$threadId, $fadedRows ?? [], true))
<native:row native:key="thread-more-{{ $threadId }}" class="w-full h-[44] bg-theme-surface {{ $last ? 'border-b border-theme-hairline' : '' }}">
    <native:column :width="64" />
    <native:pressable ref="more-replies-{{ $threadId }}" @press="showMoreReplies('{{ $threadId }}')" class="flex-1 flex-row h-[44] pr-4 gap-2 items-center {{ $indent > 0 ? 'pl-['.$indent.']' : '' }} {{ $faded ? 'opacity-0' : '' }}" :animate-duration="\App\Devnull\ThreadRows::Fade" :translate-y="$faded ? -8 : 0" a11y-label="Show more replies">
        <native:stack :width="24" :height="24" center>
            <native:stack :width="18" :height="18" center class="rounded-full bg-theme-ink-muted">
                <native:stack :width="14" :height="14" center class="rounded-full bg-theme-surface">
                    <native:column :width="8" :height="8" class="rounded-full bg-theme-ink-muted" />
                </native:stack>
            </native:stack>
        </native:stack>
        <native:text class="text-[13px] text-theme-ink-muted">Show more</native:text>
    </native:pressable>

    @if ($railLane !== null)
        @include('native.partials.rail', ['previous' => $railLane])
    @endif
    @if ($spine !== null)
        @include('native.partials.dashed-line', ['position' => 'top-0 bottom-0', 'left' => 75.5, 'lane' => $spine])
    @endif
    @include('native.partials.dashed-line', ['position' => 'top-0 h-[13]', 'left' => 75.5 + $indent, 'lane' => $lane])
</native:row>
