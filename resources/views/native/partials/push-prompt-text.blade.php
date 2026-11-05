{{-- The push prompt's icon, title and line (see push-prompt). --}}
<native:row class="w-full pl-4 py-3 gap-3 items-center">
    <native:icon :ios="$icon->ios()" :android="$icon->android()" :size="18" class="text-theme-primary" />
    <native:column class="flex-1 gap-1">
        <native:text font="medium" class="text-[15px] text-theme-ink">{{ $title }}</native:text>
        <native:text class="text-[13px] leading-snug text-theme-ink-muted">{{ $body }}</native:text>
    </native:column>
</native:row>
