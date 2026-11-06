{{--
    The pin beside a pinned message, filled as the web draws it, on the side of its bubble nearest the middle of the
    thread. Unpin is in the message's long-press menu.
--}}
@if (isset($pinnedIds[$message->id]))
    <native:stack ref="pinned-{{ $key }}" :width="30" :height="30" center a11y-label="Pinned message">
        <native:icon :ios="\App\Icons\Lucide::Pin->ios(true)" :android="\App\Icons\Lucide::Pin->android(true)" :size="14" class="text-theme-ink-muted" />
    </native:stack>
@endif
