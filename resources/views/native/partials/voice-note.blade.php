{{--
    A voice note, as the web's player draws it: play and pause, with a spinner while it downloads and retry when it
    couldn't play; the waveform, played bars in full ink and the rest faded; the clock; and the speed, faded at 1×.
    A tap on the waveform plays from there, in twelve stretches of four bars, so a screen reader has a dozen places to
    play from rather than every bar. The volume button the web shows a mouse is left off, as it is on a phone, which
    has its own buttons.

    While it plays, the thread renders four times a second for the clock and the waveform (VoicePlayback::Interval).

    $voice is the MessageVoice, $voiceKey what the thread knows it by, $ref names its parts, $ink the theme token it's
    drawn in (the bubble's ink), and $player the thread's VoicePlayback.
--}}
@php($voiceState = $player->stateOf($voiceKey))
@php($bars = $player->bars($voiceKey, $voice))
@php($speed = $player->speedOf($voiceKey))
@if ($voiceState === \App\Devnull\VoicePlayback::Playing)
<native:row ref="{{ $ref }}" class="gap-2 items-center" native:poll="250ms">
@else
<native:row ref="{{ $ref }}" class="gap-2 items-center">
@endif
    <native:pressable ref="{{ $ref }}-toggle" @press="toggleVoiceNote('{{ $voiceKey }}')" a11y-label="{{ match ($voiceState) { 'playing' => 'Pause voice note', 'failed' => 'Retry voice note', default => 'Play voice note' } }}">
        <native:stack :width="32" :height="32" center class="rounded-md bg-theme-{{ $ink }}/10">
            @if ($voiceState === \App\Devnull\VoicePlayback::Loading)
                <native:activity-indicator size="small" :color="theme($ink, 'gray-500')" />
            @else
                @php($voiceIcon = match ($voiceState) { 'playing' => \App\Icons\Lucide::Pause, 'failed' => \App\Icons\Lucide::RotateCw, default => \App\Icons\Lucide::Play })
                <native:icon :ios="$voiceIcon->ios()" :android="$voiceIcon->android()" :size="16" class="text-theme-{{ $ink }}" />
            @endif
        </native:stack>
    </native:pressable>
    <native:row ref="{{ $ref }}-waveform" class="w-[120] h-[28] items-center">
        @foreach (array_chunk($bars, 4, true) as $stretch)
            @php($from = array_key_first($stretch))
            <native:pressable ref="{{ $ref }}-from-{{ $from }}" @press="seekVoiceNote('{{ $voiceKey }}', {{ $from }})" a11y-label="Play from {{ \App\Devnull\VoiceNotes::clock(intdiv($from * $voice->durationMs, \App\Devnull\VoiceNotes::Bars) / $speed) }}">
                <native:row class="w-[10] h-[28] items-center">
                    @foreach ($stretch as $bar)
                        <native:row class="w-[2.5] h-[28] items-center">
                            <native:column class="w-[1.5] h-[{{ $bar['height'] }}] rounded-[1] bg-theme-{{ $ink }} {{ $bar['played'] ? '' : 'opacity-35' }}" />
                        </native:row>
                    @endforeach
                </native:row>
            </native:pressable>
        @endforeach
    </native:row>
    <native:text ref="{{ $ref }}-clock" font="mono" class="w-[30] text-[11px] text-right text-theme-{{ $ink }}">{{ $player->clock($voiceKey, $voice) }}</native:text>
    <native:pressable ref="{{ $ref }}-speed" @press="changeVoiceSpeed('{{ $voiceKey }}')" a11y-label="Playback speed, {{ $player->speedLabel($voiceKey) }}">
        <native:stack :width="30" :height="28" center class="rounded-md">
            <native:text font="mono" class="text-[11px] text-theme-{{ $ink }} {{ $speed === 1.0 ? 'opacity-60' : '' }}">{{ $player->speedLabel($voiceKey) }}</native:text>
        </native:stack>
    </native:pressable>
</native:row>
