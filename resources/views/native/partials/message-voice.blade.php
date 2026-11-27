{{--
    A message's voice note, in a bubble of its own as the web draws it, with the message's pin and Reply beside it as a
    text bubble has them (see partials/message-item). Each is on the wash, edged in its sender's colour, yours faded
    until devnull has it, or outlined in red once it turned the note down. The player is partials/voice-note.
    A long press opens the message's menu, as on a text bubble, without Copy text, and React's quick reactions point
    at the bubble.
--}}
@if ($message->voice !== null)
    <native:row ref="content-{{ $key }}" class="w-full gap-1 items-center {{ $mine ? 'justify-end pl-16' : 'justify-start pr-16' }}">
        <native:row ref="bubble-and-pin-{{ $key }}" class="gap-1 items-center">
            @if ($mine)
                @include('native.partials.message-pin')
            @endif
            @if ($message->isPending())
                <native:column ref="bubble-{{ $key }}" class="px-2 {{ $message->body === '' && $hasChips ? 'pt-2 pb-[18]' : 'py-2' }} rounded-2xl {{ $tighten(! $startsRun || $message->images !== [], ! $endsRun || $message->body !== '') }} {{ $message->status === 'rejected' ? 'border border-theme-destructive bg-theme-surface' : $bubble.'/60' }}">
                    @include('native.partials.voice-note', ['voice' => $message->voice, 'voiceKey' => $message->voiceKey(), 'ref' => 'voice-'.$key, 'ink' => 'ink'])
                </native:column>
            @else
                <native:pressable ref="bubble-{{ $key }}" @doubleTap="heart({{ $message->id }})" :context-menu="$menu" a11y-hint="{{ $swipeHint }}">
                    <native:column class="px-2 {{ $message->body === '' && $hasChips ? 'pt-2 pb-[18]' : 'py-2' }} rounded-2xl {{ $tighten(! $startsRun || $message->images !== [], ! $endsRun || $message->body !== '') }} {{ $bubble }}">
                        @include('native.partials.voice-note', ['voice' => $message->voice, 'voiceKey' => $message->voiceKey(), 'ref' => 'voice-'.$key, 'ink' => 'ink'])
                    </native:column>
                </native:pressable>
            @endif
            @if (! $mine)
                @include('native.partials.message-pin')
            @endif
            @include('native.partials.message-swipe-reply')
        </native:row>
        @if ($reactionsFor === $message->id && ! $reactsFromPinned)
            @include('native.partials.quick-reactions', ['anchor' => $reactionsAnchor])
        @endif
    </native:row>
@endif
