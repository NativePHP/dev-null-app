{{--
    "↑ N earlier commits" above the commit a page is about, standing in for the commits it replies to. A tap opens them
    in place above it. EDGE can't open a scroll view scrolled to a row, so the page can't start on its commit with
    them above it, as the web does: this keeps the commit at the top until you ask for what came before.

    On the rail, a dashed Rail Gray line comes down from above to a hollow node in the lane of the commit just before,
    which runs on solid into the commit below.
--}}
@php($label = \App\Devnull\Display::count($count, 'earlier commit', 'earlier commits'))
@php($colour = 'bg-theme-'.\App\Devnull\Lane::token($lane))
<native:pressable ref="earlier-commits" @press="showEarlier" class="w-full bg-theme-surface" a11y-label="Show {{ $label }}">
    <native:row class="w-full">
        <native:column :width="64" />
        <native:column class="flex-1">
            <native:row class="w-full h-[44] pr-4 gap-[6] items-center">
                <native:icon :ios="\App\Icons\Lucide::ArrowUp->ios()" :android="\App\Icons\Lucide::ArrowUp->android()" :size="14" class="text-theme-primary" />
                <native:text font="medium" class="text-[13px] text-theme-primary">{{ $label }}</native:text>
            </native:row>
            <native:column class="w-full h-[1] bg-theme-hairline" />
        </native:column>

        @include('native.partials.dashed-line', ['position' => 'top-0 h-[13]', 'left' => 31.5, 'colour' => 'bg-theme-rail', 'dashes' => 2])
        <native:column class="absolute top-[31] bottom-0 left-[31] w-[2] {{ $colour }}" />
        <native:column ref="earlier-node" class="absolute top-[13] left-[23]">
            @include('native.partials.rail-node', ['lane' => $lane, 'filled' => false])
        </native:column>
    </native:row>
</native:pressable>
