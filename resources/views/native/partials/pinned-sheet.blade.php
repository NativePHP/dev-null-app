{{--
    The org's pinned messages, latest pinned first, as the web's panel lists them under its banner: a header with how
    many there are and a close button, over a list of its own that scrolls.
--}}
<native:column ref="pinned-sheet" class="w-full h-full pt-3">
    <native:row class="w-full h-[44] pl-5 pr-3 gap-2 items-center">
        <native:text font="semibold" class="text-sm text-theme-ink">Pinned messages</native:text>
        <native:text font="mono" class="flex-1 text-[11px] text-theme-ink-muted">{{ count($pinned) }}</native:text>
        <native:pressable ref="close-pinned" @press="messageSheetClosed" a11y-label="Close pinned messages">
            <native:stack :width="36" :height="36" center>
                <native:icon :ios="\App\Icons\Lucide::X->ios()" :android="\App\Icons\Lucide::X->android()" :size="16" class="text-theme-ink-muted" />
            </native:stack>
        </native:pressable>
    </native:row>
    <native:column class="w-full h-[1] bg-theme-hairline" />
    <native:scroll-view ref="pinned-list" class="w-full flex-1">
        @foreach ($pinned as $message)
            @include('native.partials.pinned-message', ['message' => $message, 'key' => 'pin-'.$message->id, 'first' => $loop->first])
        @endforeach
    </native:scroll-view>
</native:column>
