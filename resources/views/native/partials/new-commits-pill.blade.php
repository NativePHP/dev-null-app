{{--
    DESIGN.md's new commits pill: Commit Blue, "↑ 3 new commits". The commits are already fetched, so a tap puts them on
    top with nothing to wait for.

    As on the web, it sits in a strip of its own between the top of the list (the Log's composer, a profile's tabs) and
    the commits, and pins to the top once you scroll past it. EDGE tells PHP nothing about where a list is scrolled, so
    the list pins it itself, as a sticky header.

    Its header is always in the list, hidden until there's something to show ($shown), so the list keeps its shape when
    new commits come in. A node added beside a scrolled list sometimes left the list blank on iOS: its rows still in the
    tree and taking taps, nothing drawn.
--}}
@php($shown ??= true)
<native:sticky-header ref="new-commits-slot" class="w-full bg-theme-surface {{ $shown ? '' : 'hidden' }}">
    @if ($shown)
        <native:row class="w-full py-3 justify-center">
            <native:pressable ref="new-commits" @press="{{ $press }}" class="rounded-full bg-theme-primary shadow-md" a11y-label="Show {{ $label }}">
                <native:row class="px-4 py-[6] gap-[6] items-center">
                    <native:icon :ios="\App\Icons\Lucide::ArrowUp->ios()" :android="\App\Icons\Lucide::ArrowUp->android()" :size="16" class="text-theme-on-primary" />
                    <native:text font="medium" class="text-sm text-theme-on-primary">{{ $label }}</native:text>
                </native:row>
            </native:pressable>
        </native:row>
        <native:column class="w-full h-[1] bg-theme-hairline" />
    @endif
</native:sticky-header>
