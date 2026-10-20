{{--
    DESIGN.md's new commits pill: Commit Blue, "↑ 3 new commits". The commits are already fetched, so a tap puts them on
    top with nothing to wait for.

    It floats over the commits, hung just under the pager's tabs, in the pager's overlay. There it stays put while the
    list scrolls under it, and under a profile's header it follows the tabs wherever the header has scrolled them. The
    overlay lets taps on either side of it through to the commits beneath.

    Its slot is always in the overlay, hidden until there's something to show ($shown), so the pager keeps its shape
    when new commits come in. A node added beside a scrolled list sometimes left the list blank on iOS: its rows still in
    the tree and taking taps, nothing drawn.
--}}
@php($shown ??= true)
<native:row ref="new-commits-slot" class="w-full pt-3 justify-center {{ $shown ? '' : 'hidden' }}">
    @if ($shown)
        <native:pressable ref="new-commits" @press="{{ $press }}" class="rounded-full bg-theme-primary shadow-md" a11y-label="Show {{ $label }}">
            <native:row class="px-4 py-[6] gap-[6] items-center">
                <native:icon :ios="\App\Icons\Lucide::ArrowUp->ios()" :android="\App\Icons\Lucide::ArrowUp->android()" :size="16" class="text-theme-on-primary" />
                <native:text font="medium" class="text-sm text-theme-on-primary">{{ $label }}</native:text>
            </native:row>
        </native:pressable>
    @endif
</native:row>
