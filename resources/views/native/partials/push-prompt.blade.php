{{--
    The offer to turn on push notifications, at the top of a tab while this phone has none (see
    App\NativeComponents\Concerns\OffersPush). A tap turns them on, the cross hides it for good.
--}}
@if ($offersPush)
    <native:row ref="push-prompt" class="w-full bg-theme-surface items-center">
        <native:pressable ref="enroll-push" @press="enrollPush" class="flex-1" a11y-label="Turn on push notifications">
            <native:row class="w-full pl-4 py-3 gap-3 items-center">
                <native:icon :ios="\App\Icons\Lucide::Bell->ios()" :android="\App\Icons\Lucide::Bell->android()" :size="18" class="text-theme-primary" />
                <native:column class="flex-1 gap-1">
                    <native:text font="medium" class="text-[15px] text-theme-ink">Turn on notifications</native:text>
                    <native:text class="text-[13px] leading-snug text-theme-ink-muted">Hear about follows, replies, mentions and Remotes messages, even when the app is closed.</native:text>
                </native:column>
            </native:row>
        </native:pressable>
        <native:pressable ref="dismiss-push-prompt" @press="dismissPushPrompt" class="px-4 py-3" a11y-label="Don't show again">
            <native:icon :ios="\App\Icons\Lucide::X->ios()" :android="\App\Icons\Lucide::X->android()" :size="16" class="text-theme-ink-muted" />
        </native:pressable>
    </native:row>
    <native:column class="w-full h-[1] bg-theme-hairline" />
@endif
