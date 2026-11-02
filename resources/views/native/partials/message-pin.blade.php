{{--
    The pin beside a pinned message, filled as the web draws it. While the message's React and Reply show, an org's
    maintainer gets Unpin in its place, in the primary colour.
--}}
@if ($canPin && $showsActions)
    <native:pressable ref="unpin-{{ $key }}" @press="togglePin({{ $message->id }})" a11y-label="Unpin message">
        <native:stack :width="30" :height="30" center class="rounded-full bg-theme-primary/15">
            <native:icon :ios="\App\Icons\Lucide::Pin->ios(true)" :android="\App\Icons\Lucide::Pin->android(true)" :size="16" class="text-theme-primary" />
        </native:stack>
    </native:pressable>
@else
    <native:stack ref="pinned-{{ $key }}" :width="30" :height="30" center a11y-label="Pinned message">
        <native:icon :ios="\App\Icons\Lucide::Pin->ios(true)" :android="\App\Icons\Lucide::Pin->android(true)" :size="14" class="text-theme-ink-muted" />
    </native:stack>
@endif
