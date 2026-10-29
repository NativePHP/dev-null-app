{{--
    A message's voice note, in a bubble of its own as the web draws it, with the message's actions beside it as a text
    bubble has them (see partials/message-item). Yours is in your lane, staged in it faded until devnull has it, or
    outlined in red once it turned the note down; everyone else's is on the wash. The player is partials/voice-note.
--}}
@if ($message->voice !== null)
    @php($voiceInk = $message->status === 'rejected' ? 'ink' : ($mine ? 'on-'.$myColour : 'ink'))
    <native:row ref="content-{{ $key }}" class="w-full gap-1 items-center {{ $mine ? 'justify-end' : 'justify-start' }}" :translate-x="$pan ?? 0">
        @if ($mine)
            @include('native.partials.message-actions')
        @endif
        @if ($message->isPending())
            <native:column ref="bubble-{{ $key }}" class="p-2 rounded-2xl {{ $message->status === 'rejected' ? 'border border-theme-destructive bg-theme-surface' : 'bg-theme-'.$myColour.'/60' }}">
                @include('native.partials.voice-note', ['voice' => $message->voice, 'voiceKey' => $message->voiceKey(), 'ref' => 'voice-'.$key, 'ink' => $voiceInk])
            </native:column>
        @else
            <native:column ref="bubble-{{ $key }}" class="p-2 rounded-2xl {{ $mine ? 'bg-theme-'.$myColour : 'bg-theme-wash' }}" @press="hideActions" @doubleTap="heart({{ $message->id }})" a11y-hint="{{ $replyable ? 'Swipe sideways for React and Reply' : '' }}">
                @include('native.partials.voice-note', ['voice' => $message->voice, 'voiceKey' => $message->voiceKey(), 'ref' => 'voice-'.$key, 'ink' => $voiceInk])
            </native:column>
        @endif
        @if (! $mine)
            @include('native.partials.message-actions')
        @endif
    </native:row>
@endif
