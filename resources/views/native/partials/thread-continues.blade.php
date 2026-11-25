{{--
    "Show more of this thread" under the last part of an author's thread a commit page shows, as the web's: a tap shows
    the next ten parts (see SelfThread). It mirrors "↑ N earlier commits" above the commit: the last part's rail comes
    down solid to a hollow node in its lane, and a dashed Rail Gray line runs on below it to what's still to come.
--}}
@php($colour = 'bg-theme-'.\App\Devnull\Lane::token($lane))
<native:pressable ref="more-thread" @press="showMoreThread" class="w-full bg-theme-surface" a11y-label="Show more of this thread">
    <native:row class="w-full">
        <native:column :width="64" />
        <native:column class="flex-1">
            <native:row class="w-full h-[44] pr-4 gap-[6] items-center">
                <native:icon :ios="\App\Icons\Lucide::ListTree->ios()" :android="\App\Icons\Lucide::ListTree->android()" :size="14" class="text-theme-primary" />
                <native:text font="medium" class="text-[13px] text-theme-primary">Show more of this thread</native:text>
            </native:row>
            <native:column class="w-full h-[1] bg-theme-hairline" />
        </native:column>

        <native:column class="absolute top-0 h-[13] left-[31] w-[2] {{ $colour }}" />
        <native:column ref="more-thread-node" class="absolute top-[13] left-[23]">
            @include('native.partials.rail-node', ['lane' => $lane, 'filled' => false])
        </native:column>
        @include('native.partials.dashed-line', ['position' => 'top-[31] bottom-0', 'left' => 31.5, 'lane' => \App\Devnull\Lane::Rail])
    </native:row>
</native:pressable>
