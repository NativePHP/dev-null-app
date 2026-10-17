{{--
    DESIGN.md's new commits pill: Commit Blue, hanging under the tabs over the log, "↑ 3 new commits". The commits are
    already fetched, so a tap puts them on top with nothing to wait for. Laid over the list rather than in it, so
    nothing below moves when it shows.

    Its row is always there, hidden until there's something to show ($shown). A node added beside a scrolled list
    sometimes left the list blank on iOS: its rows still in the tree and taking taps, nothing drawn.

    In a pager's overlay ($hanging) the pager hangs it under its tabs, which a header scrolls up and down.
--}}
@php($shown ??= true)
@php($hanging ??= false)
<native:row ref="new-commits-slot" class="{{ $hanging ? 'w-full pt-3' : 'absolute top-3 left-0 right-0' }} justify-center {{ $shown ? '' : 'hidden' }}">
    @if ($shown)
        <native:pressable ref="new-commits" @press="{{ $press }}" class="rounded-full bg-theme-primary shadow-md" a11y-label="Show {{ $label }}">
            <native:row class="px-4 py-[6] gap-[6] items-center">
                <native:icon :ios="\App\Icons\Lucide::ArrowUp->ios()" :android="\App\Icons\Lucide::ArrowUp->android()" :size="16" class="text-theme-on-primary" />
                <native:text font="medium" class="text-sm text-theme-on-primary">{{ $label }}</native:text>
            </native:row>
        </native:pressable>
    @endif
</native:row>
