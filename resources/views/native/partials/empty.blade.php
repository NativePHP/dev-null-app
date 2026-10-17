{{-- Empty states speak in git's voice: a mono line, then a plain sentence or two, and maybe a link on its own line. --}}
<native:column ref="empty-state" class="w-full items-center px-8 py-16 gap-3 bg-theme-surface">
    <native:text font="mono" class="text-[13px] text-center text-theme-ink-muted">{{ $command }}</native:text>
    <native:text class="text-[15px] text-center leading-relaxed text-theme-ink max-w-[320]">{{ $message }}</native:text>
    @isset($link)
        <native:text ref="empty-link" font="medium" class="text-[15px] text-center text-theme-primary" @press="{{ $press }}">{{ $link }}</native:text>
    @endisset
</native:column>
