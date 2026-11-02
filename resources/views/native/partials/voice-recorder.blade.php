{{--
    The voice note in the composer, in the field's place (see partials/composer-plate), as the web's recorder draws
    it: the trash, then "opening microphone…" until it records, then a pulsing dot, the microphone's level coming in
    on the right, and the clock counting to the minute, amber in the last ten seconds, with Stop; then the recording
    itself, to listen to before Push sends it. The same height as the field with one line, so the bar doesn't move.

    While it records, the thread renders five times a second for the meter (RecordsVoiceNotes::MeterInterval).
--}}
@if ($recorder === 'recording')
<native:row native:key="voice-recorder" ref="voice-recorder" class="flex-1 h-[36] gap-2 items-center ios:pl-1" native:poll="200ms">
@else
<native:row native:key="voice-recorder" ref="voice-recorder" class="flex-1 h-[36] gap-2 items-center ios:pl-1">
@endif
    <native:pressable ref="discard-voice-note" @press="discardVoiceNote" a11y-label="Discard voice note">
        <native:column class="min-w-[36] h-[36] items-center justify-center rounded-md">
            <native:icon :ios="\App\Icons\Lucide::Trash2->ios()" :android="\App\Icons\Lucide::Trash2->android()" :size="20" class="text-theme-ink-muted" />
        </native:column>
    </native:pressable>
    @if ($recorder === 'recorded' && $recorded !== null)
        @include('native.partials.voice-note', ['voice' => $recorded, 'voiceKey' => \App\Devnull\Data\Message::DraftVoice, 'ref' => 'voice-draft', 'ink' => 'ink'])
    @elseif ($recorder === 'recording' && $recordingStarted)
        <native:column class="w-[8] h-[8] rounded-full bg-theme-ink opacity-30" animate-duration="700" animate-easing="ease-in-out" animate-loop a11y-label="Recording a voice note" />
        <native:row ref="voice-meter" class="flex-1 h-[24] gap-[1] items-center">
            @foreach ($recordingLevels as $level)
                <native:column class="flex-1 h-[{{ max(2, (int) round(24 * max(10, $level) / 100)) }}] rounded-[1] bg-theme-ink" />
            @endforeach
        </native:row>
        <native:text ref="voice-elapsed" font="mono" class="text-xs {{ $recordingElapsed >= \App\Devnull\VoiceNotes::MaxMilliseconds - 10_000 ? 'text-theme-pending' : 'text-theme-ink-muted' }}">{{ \App\Devnull\VoiceNotes::clock($recordingElapsed) }}/{{ \App\Devnull\VoiceNotes::clock(\App\Devnull\VoiceNotes::MaxMilliseconds) }}</native:text>
        <native:pressable ref="stop-voice-note" @press="stopVoiceNote" a11y-label="Stop recording">
            <native:column class="min-w-[36] h-[36] items-center justify-center rounded-md">
                <native:icon :ios="\App\Icons\Lucide::Square->ios()" :android="\App\Icons\Lucide::Square->android()" :size="14" class="text-theme-ink" />
            </native:column>
        </native:pressable>
    @else
        <native:row ref="voice-encoding" class="flex-1 gap-2 items-center">
            <native:activity-indicator size="small" :color="theme('ink-muted', 'gray-500')" />
            <native:text font="mono" class="text-xs text-theme-ink-muted">{{ $recorder === 'recording' ? 'opening microphone…' : 'encoding…' }}</native:text>
        </native:row>
    @endif
</native:row>
