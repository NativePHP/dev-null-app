@use('App\Icons\Lucide')
@use('App\NativeComponents\Call')

<native:column fill class="safe-area bg-theme-background">
    {{-- Who it's with and how it's going, in the middle of the screen. --}}
    <native:column ref="who" class="w-full flex-1 items-center justify-center gap-3 px-6">
        @if ($avatar)
            <native:image ref="avatar" :src="$avatar" :width="96" :height="96" :fit="2" alt="" class="rounded-full" />
        @endif

        <native:text ref="name" font="semibold" class="text-center text-2xl text-theme-ink">{{ $name }}</native:text>

        {{-- The clock ticks every second while it rings for them and once connected. --}}
        @if (in_array($status, [Call::Calling, Call::Connected], true))
            <native:text ref="line" font="mono" class="text-center text-sm text-theme-ink-muted" native:poll="1s">{{ $line }}</native:text>
        @else
            <native:text ref="line" font="mono" class="text-center text-sm text-theme-ink-muted">{{ $line }}</native:text>
        @endif

        @if ($status === Call::Connected && $code !== '')
            <native:column ref="verification" class="items-center gap-1 pt-4">
                <native:text ref="code" font="mono-medium" class="text-center text-base text-theme-ink">fp {{ $code }}</native:text>
                <native:text font="mono" class="text-center text-xs text-theme-ink-muted">If you both see the same code, nobody is listening in between.</native:text>
            </native:column>
        @endif
    </native:column>

    <native:row ref="controls" class="w-full px-6 pb-6 gap-3 justify-center">
        @if ($status === Call::Ringing)
            <native:button ref="decline" variant="secondary" size="lg" @press="decline" class="flex-1">Decline</native:button>
            <native:button ref="accept" variant="primary" size="lg" :ios-icon="Lucide::Phone->ios()" :android-icon="Lucide::Phone->android()" @press="accept" class="flex-1">Accept</native:button>
        @elseif ($status === Call::Connected)
            <native:button ref="mute" :variant="$isMuted ? 'primary' : 'secondary'" size="lg"
                :ios-icon="($isMuted ? Lucide::MicOff : Lucide::Mic)->ios()" :android-icon="($isMuted ? Lucide::MicOff : Lucide::Mic)->android()"
                :a11y-label="$isMuted ? 'Unmute microphone' : 'Mute microphone'" @press="toggleMute" />
            <native:button ref="speaker" :variant="$isSpeakerOn ? 'primary' : 'secondary'" size="lg"
                :ios-icon="Lucide::Volume2->ios()" :android-icon="Lucide::Volume2->android()"
                :a11y-label="$isSpeakerOn ? 'Turn speaker off' : 'Turn speaker on'" @press="toggleSpeaker" />
            <native:button ref="hang-up" variant="destructive" size="lg" :ios-icon="Lucide::PhoneOff->ios()" :android-icon="Lucide::PhoneOff->android()" @press="hangUp" class="flex-1">Hang up</native:button>
        @elseif ($status === Call::Ended)
            <native:button ref="close" variant="secondary" size="lg" @press="hangUp" class="flex-1">Close</native:button>
        @else
            <native:button ref="cancel" variant="secondary" size="lg" :ios-icon="Lucide::PhoneOff->ios()" :android-icon="Lucide::PhoneOff->android()" @press="hangUp" class="flex-1">Cancel</native:button>
        @endif
    </native:row>
</native:column>
