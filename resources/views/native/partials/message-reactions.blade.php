{{--
    A message's reactions, one chip per emoji in the order it was first left, with how many left it: yours outlined in
    your lane, and a tap to take yours back or add it.
--}}
@php($chips = $message->reactionChips($viewer?->id))
@if ($chips !== [])
    <native:row ref="reactions-{{ $key }}" class="gap-1">
        @foreach ($chips as $chip)
            <native:pressable ref="chip-{{ $key }}-{{ $loop->index }}" @press="toggleReaction({{ $message->id }}, '{{ $chip['emoji'] }}')" a11y-label="{{ $chip['emoji'] }} {{ $chip['count'] }}{{ $chip['mine'] ? ', yours' : '' }}">
                <native:row class="px-2 py-[2] gap-1 items-center rounded-full border {{ $chip['mine'] ? 'border-theme-'.$myColour.' bg-theme-'.$myColour.'/10' : 'border-theme-hairline bg-theme-surface' }}">
                    <native:text class="text-[13px]">{{ $chip['emoji'] }}</native:text>
                    <native:text font="mono" class="text-[11px] text-theme-ink-muted">{{ $chip['count'] }}</native:text>
                </native:row>
            </native:pressable>
        @endforeach
    </native:row>
@endif
