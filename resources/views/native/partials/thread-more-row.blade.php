{{--
    "Show more" at the foot of a thread opened in place (see App\Devnull\ThreadRows), as a row of its own: a muted rail
    dot where the next avatar would be, on the dashed line down from the reply above ($lane), and the words. Tapped,
    the thread's next page opens under what's showing, or whatever $press says. $indent puts it as far in as the
    replies above it, and $spines carry the lines from further up on past it.

    At the web's deepest step the dot hangs on an elbow from the line of the reply the thread answers ($hangs, in
    $parentLane), as those replies do. Past it there are no lines ($links), and it says whose replies these are ($to).

    It's 44pt tall, as tall as the list's shortest row, so the list never pads it out under its hairline. The dot sits
    in its middle, so the line from above stops at the top of the dot, 13pt down. In a feed the $last closes the
    commit's row with its hairline; on a commit page ($hairline false) nothing does.
--}}
@php($indent ??= 0)
@php($spines ??= [])
@php($railLane ??= null)
@php($hangs ??= false)
@php($links ??= true)
@php($to ??= null)
@php($hairline ??= true)
@php($press ??= "showMoreReplies('{$threadId}')")
@php($faded = in_array('more-'.$threadId, $fadedRows ?? [], true))
<native:row native:key="thread-more-{{ $threadId }}" class="w-full h-[44] bg-theme-surface {{ $last && $hairline ? 'border-b border-theme-hairline' : '' }}">
    <native:column :width="64" />
    <native:pressable ref="more-replies-{{ $threadId }}" @press="{{ $press }}" class="flex-1 flex-row h-[44] pr-4 gap-2 items-center {{ $indent > 0 ? 'pl-['.$indent.']' : '' }} {{ $faded ? 'opacity-0' : '' }}" :animate-duration="\App\Devnull\ThreadRows::Fade" :translate-y="$faded ? -8 : 0" a11y-label="{{ $to === null ? 'Show more replies' : 'Show more replies to '.$to }}">
        <native:stack :width="24" :height="24" center>
            <native:stack :width="18" :height="18" center class="rounded-full bg-theme-ink-muted">
                <native:stack :width="14" :height="14" center class="rounded-full bg-theme-surface">
                    <native:column :width="8" :height="8" class="rounded-full bg-theme-ink-muted" />
                </native:stack>
            </native:stack>
        </native:stack>
        @if ($to === null)
            <native:text class="text-[13px] text-theme-ink-muted">Show more</native:text>
        @else
            <native:text class="flex-1 text-[13px] text-theme-ink-muted" :max-lines="1">Show more to <native:text font="mono" class="text-xs">{{ $to }}</native:text></native:text>
        @endif
    </native:pressable>

    @if ($railLane !== null)
        @include('native.partials.rail', ['previous' => $railLane])
    @endif
    @foreach ($spines as $spineLine)
        @include('native.partials.dashed-line', ['position' => (isset($spineLine['top']) ? 'top-['.$spineLine['top'].']' : 'top-0').' bottom-0', 'left' => $spineLine['left'], 'lane' => $spineLine['lane']])
    @endforeach
    @if ($links && $hangs)
        @include('native.partials.thread-elbow', ['lane' => $parentLane, 'from' => 43.5 + $indent, 'to' => 64 + $indent, 'level' => 22])
    @elseif ($links)
        @include('native.partials.dashed-line', ['position' => 'top-0 h-[13]', 'left' => 75.5 + $indent, 'lane' => $lane])
    @endif
</native:row>
