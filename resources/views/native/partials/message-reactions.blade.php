{{--
    A message's reactions in one pill with the fill and edge of the bubble they sit on: each emoji in the order it was
    first left, with how many left it, the count in your lane where one is yours, and a tap to take yours back or add it.
    The pinned sheet has no bubble, so there the pill is on the surface with a hairline edge. The reactions mostly
    overlap the bottom of what they react to, 8pt hanging below it, set in from its side on a solid fill; a text or voice
    bubble with reactions has room for them under its content (see partials/message-item). They can run wider than a
    short message. Android draws a negative margin without giving its space back (see ComposeFlexLayout), so within a run
    ($chipsHang) they're pinned to the bottom of the message's column, whose padding holds only the 8pt that hangs
    below, and the next message comes up close. Otherwise they're a row drawn 19pt higher, with the time or status under
    them.
--}}
@php($chipsHang ??= false)
@php($mine ??= false)
@php($pill = $bubble ?? 'bg-theme-surface border border-theme-hairline')
@php($chips = $message->reactionChips($viewer?->id))
@if ($chips !== [])
    <native:row class="{{ $chipsHang ? 'absolute -bottom-[8] '.($mine ? 'right-2' : 'left-2') : 'px-2' }} {{ $mine ? 'justify-end' : 'justify-start' }}" :translate-y="$chipsHang ? 0 : -19">
    <native:row ref="reactions-{{ $key }}" class="px-2 py-[1] gap-2 items-center rounded-full {{ $pill }}">
        @foreach ($chips as $chip)
            <native:pressable ref="chip-{{ $key }}-{{ $loop->index }}" @press="toggleReaction({{ $message->id }}, '{{ $chip['emoji'] }}')" a11y-label="{{ $chip['emoji'] }} {{ $chip['count'] }}{{ $chip['mine'] ? ', yours' : '' }}">
                <native:row class="gap-1 items-center">
                    <native:text class="text-[20px]">{{ $chip['emoji'] }}</native:text>
                    <native:text font="mono" class="text-[13px] {{ $chip['mine'] ? 'text-theme-'.$myColour : 'text-theme-ink-muted' }}">{{ $chip['count'] }}</native:text>
                </native:row>
            </native:pressable>
        @endforeach
    </native:row>
    </native:row>
@endif
