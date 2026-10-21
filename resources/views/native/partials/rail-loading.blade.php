{{--
    While the next page loads, a skeleton row stands in on a Rail Gray rail ending in its dot, as the web's CommitSkeleton.
    A list with nothing kept to show yet shows a few of them, the first time only.
    It pulses only on the page you're on ($pulse). The pager lays out the pages beside it too, and a looping animation
    there never stops: SwiftUI keeps redrawing it off screen, and the main thread keeps waiting on it while you scroll.
    A loop only starts when its view appears, so the pulsing column gets a key of its own and comes in afresh, already
    pulsing, when you swipe to its page.
--}}
@php($ref ??= 'loading-more')
@php($pulse ??= true)
@php($label ??= 'Loading more commits')
<native:row ref="{{ $ref }}" class="w-full bg-theme-surface" a11y-label="{{ $label }}">
    <native:column :width="64" />
    <native:column class="flex-1">
        <native:column native:key="{{ $pulse ? 'pulsing' : 'still' }}" class="w-full min-h-[64] py-3 pr-4 gap-[10] opacity-[0.5]" :animate-duration="$pulse ? 900 : 0" :animate-loop="$pulse">
            <native:column class="w-2/5 h-[12] mt-1 rounded bg-theme-wash" />
            <native:column class="w-4/5 h-[12] rounded bg-theme-wash" />
            <native:column class="w-3/5 h-[12] rounded bg-theme-wash" />
        </native:column>
        <native:column class="w-full h-[1] bg-theme-hairline" />
    </native:column>

    @include('native.partials.rail', ['tail' => \App\Devnull\Lane::Rail, 'endsWithDot' => true, 'lane' => \App\Devnull\Lane::Rail, 'node' => 'skeleton'])
</native:row>
