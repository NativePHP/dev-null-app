{{--
    A refresh that didn't land, offline or otherwise. What's showing stays put, and this says quietly that it may be
    out of date, in git's voice, with a way to try again. Only a screen with nothing to show prints the error instead.
--}}
@if ($refreshFailed)
    <native:pressable ref="refresh-failed" @press="retry" class="w-full bg-theme-surface" a11y-label="Couldn't refresh. Retry">
        <native:row class="w-full px-4 py-2 gap-3 items-center">
            <native:text font="mono" class="flex-1 text-[13px] text-theme-ink-muted">warning: couldn't refresh</native:text>
            <native:text font="medium" class="text-[13px] text-theme-primary">Retry</native:text>
        </native:row>
        <native:column class="w-full h-[1] border-b border-theme-hairline" />
    </native:pressable>
@endif
