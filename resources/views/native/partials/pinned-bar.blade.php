{{--
    An org's pinned messages folded into one bar over the top of the thread, as the web's banner: the latest one, by
    whom, and how many there are. It lies over the thread instead of pushing it down, so a pin arriving never moves what
    you're reading, and a tap opens them all in the sheet.
--}}
@php($latest = $pinned[0])
@php($latestAuthor = $latest->user ?? ($authors[$latest->userId] ?? null))
@php($count = count($pinned))
<native:pressable ref="pinned-bar" @press="openPinned" class="absolute top-0 left-0 w-full" a11y-label="{{ $count === 1 ? 'One pinned message' : $count.' pinned messages' }}. Latest from {{ $latestAuthor->name ?? 'someone' }}: {{ $latest->snippet() }}">
    <native:column class="w-full bg-theme-surface/95">
        <native:row class="w-full h-[36] px-4 gap-2 items-center">
            <native:icon :ios="\App\Icons\Lucide::Pin->ios(true)" :android="\App\Icons\Lucide::Pin->android(true)" :size="14" class="text-theme-primary" />
            <native:text font="mono" class="text-[11px] text-theme-ink-muted">pinned · {{ $count }}</native:text>
            <native:text class="flex-1 text-[13px] text-theme-ink" :max-lines="1"><native:text :text="$latestAuthor->name ?? 'Someone'" font="semibold" class="font-semibold" /><native:text :text="' '.$latest->snippet()" class="text-theme-ink-muted" /></native:text>
            <native:icon :ios="\App\Icons\Lucide::ChevronDown->ios()" :android="\App\Icons\Lucide::ChevronDown->android()" :size="16" class="text-theme-ink-muted" />
        </native:row>
        <native:column class="w-full h-[1] bg-theme-hairline" />
    </native:column>
</native:pressable>
