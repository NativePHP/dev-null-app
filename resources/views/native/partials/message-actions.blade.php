{{--
    React, Reply and Pin for the message you drag aside, right beside its bubble as the web shows them on hover: Pin
    nearest it, then Reply, then React, with the web's icons. Every message keeps them, shrunk to nothing, and as the drag
    slides the message aside each grows in over its share of the way, nearest the bubble first, following the finger
    there and back. The slot never changes width: in an org it keeps room for Pin whether it shows or not, as the web's
    does, so a pin arriving never rewraps a bubble. They only take taps once the message has settled open. React opens
    the web's quick reactions in a bubble pointing at it (see quick-reactions).

    A pinned message's pin shows all the time, nearest the bubble, so the others come in beyond it without moving it.
    For an org's maintainers it turns into Unpin, in the primary colour, while the others show; for everyone else it
    only marks the message, as the web's does.
--}}
@php($isPinned = isset($pinnedIds[$message->id]))
@php($pinsHere = ! $isPinned && $canPin)
@php($fading = $mine ? ['react', 'reply', ...($pinsHere ? ['pin'] : [])] : [...($pinsHere ? ['pin'] : []), 'reply', 'react'])
<native:row ref="actions-{{ $key }}" class="w-[{{ $actionsWidth }}] flex-shrink-0 gap-1 items-center {{ $mine ? 'justify-end' : 'justify-start' }}">
    @if ($isPinned && ! $mine)
        @include('native.partials.message-pin')
    @endif
    @php($share = $slide / max(1, count($fading)))
    <native:row native:key="actions-{{ $key }}-buttons" ref="actions-{{ $key }}-buttons" class="gap-1 items-center">
        @foreach ($fading as $action)
            @php($nearness = $mine ? count($fading) - 1 - $loop->index : $loop->index)
            @php($grown = $pan === null ? 0 : ($mine ? $pan->interpolate([$nearness * $share, ($nearness + 1) * $share], [0, 1]) : $pan->interpolate([-($nearness + 1) * $share, -$nearness * $share], [1, 0])))
            @php($press = match ($action) { 'reply' => 'reply', 'react' => 'openReactions', 'pin' => 'togglePin' })
            @php($icon = match ($action) { 'reply' => \App\Icons\Lucide::Reply, 'react' => \App\Icons\Lucide::SmilePlus, 'pin' => \App\Icons\Lucide::Pin })
            {{--
                Only an open message's actions take taps. A closed one's are drawn, so they grow in under the finger, but
                shrunk or faded a button keeps its whole box for taps, and a tap beside a closed message would land on it.
            --}}
            @if ($showsActions)
                <native:pressable ref="{{ $action }}-{{ $key }}" @press="{{ $press }}({{ $message->id }})" :scale="$grown" :opacity="$grown" a11y-label="{{ match ($action) { 'reply' => 'Reply', 'react' => 'React', 'pin' => 'Pin message' } }}">
                    <native:stack :width="30" :height="30" center class="rounded-full bg-theme-wash">
                        <native:icon :ios="$icon->ios()" :android="$icon->android()" :size="16" class="text-theme-ink-muted" />
                    </native:stack>
                </native:pressable>
            @else
                <native:stack ref="{{ $action }}-{{ $key }}" :width="30" :height="30" center class="rounded-full bg-theme-wash" :scale="$grown" :opacity="$grown">
                    <native:icon :ios="$icon->ios()" :android="$icon->android()" :size="16" class="text-theme-ink-muted" />
                </native:stack>
            @endif
        @endforeach
        {{-- React's bubble points at React: the last button of theirs, the first of yours. --}}
        @if ($reactionsFor === $message->id && ! $reactsFromPinned)
            @include('native.partials.quick-reactions', ['anchor' => 'absolute top-0 bottom-0 w-[30] '.($mine ? 'left-0' : 'right-0')])
        @endif
    </native:row>
    @if ($isPinned && $mine)
        @include('native.partials.message-pin')
    @endif
</native:row>
