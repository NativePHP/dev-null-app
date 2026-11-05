{{--
    The push notifications prompt at the top of a tab (see App\NativeComponents\Concerns\OffersPush): an offer to turn
    them on while this phone has none, or, once they're turned off, a way to the phone's Settings. A tap does it, the
    cross hides it for good.
--}}
@if ($pushPrompt !== null)
    @php($turnedOff = $pushPrompt === \App\Devnull\PushPrompt::TurnedOff)
    <native:row ref="push-prompt" class="w-full bg-theme-surface items-center">
        @if ($turnedOff)
            <native:pressable ref="open-push-settings" @press="openPushSettings" class="flex-1" a11y-label="Notifications are off. Open Settings">
                @include('native.partials.push-prompt-text', [
                    'icon' => \App\Icons\Lucide::BellOff,
                    'title' => 'Notifications are off',
                    'body' => 'Turn them on in Settings to hear about follows, replies, mentions and Remotes messages.',
                ])
            </native:pressable>
        @else
            <native:pressable ref="enroll-push" @press="enrollPush" class="flex-1" a11y-label="Turn on push notifications">
                @include('native.partials.push-prompt-text', [
                    'icon' => \App\Icons\Lucide::Bell,
                    'title' => 'Turn on notifications',
                    'body' => 'Hear about follows, replies, mentions and Remotes messages, even when the app is closed.',
                ])
            </native:pressable>
        @endif
        <native:pressable ref="dismiss-push-prompt" @press="dismissPushPrompt" class="px-4 py-3" a11y-label="Don't show again">
            <native:icon :ios="\App\Icons\Lucide::X->ios()" :android="\App\Icons\Lucide::X->android()" :size="16" class="text-theme-ink-muted" />
        </native:pressable>
    </native:row>
    <native:column class="w-full h-[1] bg-theme-hairline" />
@endif
