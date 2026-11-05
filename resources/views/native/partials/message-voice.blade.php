{{--
    A message's voice note, in a bubble of its own as the web draws it, with the message's pin beside it as a text
    bubble has it (see partials/message-item). Yours is in your lane, staged in it faded until devnull has it, or
    outlined in red once it turned the note down; everyone else's is on the wash. The player is partials/voice-note.
    A long press opens the message's menu, as on a text bubble, without Copy text, and React's quick reactions point
    at the bubble.
--}}
@if ($message->voice !== null)
    @php($voiceInk = $message->status === 'rejected' ? 'ink' : ($mine ? 'on-'.$myColour : 'ink'))
    <native:row ref="content-{{ $key }}" class="w-full gap-1 items-center {{ $mine ? 'justify-end pl-16' : 'justify-start pr-16' }}">
        @if ($mine)
            @include('native.partials.message-pin')
        @endif
        @if ($message->isPending())
            <native:column ref="bubble-{{ $key }}" class="p-2 rounded-2xl {{ $message->status === 'rejected' ? 'border border-theme-destructive bg-theme-surface' : 'bg-theme-'.$myColour.'/60' }}">
                @include('native.partials.voice-note', ['voice' => $message->voice, 'voiceKey' => $message->voiceKey(), 'ref' => 'voice-'.$key, 'ink' => $voiceInk])
            </native:column>
        @else
            <native:pressable ref="bubble-{{ $key }}" @doubleTap="heart({{ $message->id }})" :context-menu="$menu" a11y-hint="{{ $swipeHint }}">
                <native:column class="p-2 rounded-2xl {{ $mine ? 'bg-theme-'.$myColour : 'bg-theme-wash' }}">
                    @include('native.partials.voice-note', ['voice' => $message->voice, 'voiceKey' => $message->voiceKey(), 'ref' => 'voice-'.$key, 'ink' => $voiceInk])
                </native:column>
            </native:pressable>
        @endif
        @if (! $mine)
            @include('native.partials.message-pin')
        @endif
        @if ($reactionsFor === $message->id && ! $reactsFromPinned)
            @include('native.partials.quick-reactions', ['anchor' => $reactionsAnchor])
        @endif
    </native:row>
@endif
