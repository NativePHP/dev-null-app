{{--
    A deleted commit that still has replies keeps its place on the rail, the way the web draws it: a dashed Rail Gray
    ring where the avatar was, a Rail Gray segment running on from it, and a muted line in git's voice. It isn't a link.
    A deleted part of a series keeps its place in it, so the series still reads in order.
--}}
@php($previousLane ??= null)
@php($withTail ??= true)
<native:row native:key="{{ $rowKey ?? 'tombstone-'.$commit->id }}" ref="tombstone-{{ $commit->id }}" class="w-full bg-theme-surface">
    <native:column :width="64" />

    <native:column class="flex-1">
        <native:row class="w-full min-h-[64] py-3 pr-4 items-center">
            <native:text class="flex-1 text-[15px] text-theme-ink-muted"><native:text font="mono" class="text-[13px]">[deleted]</native:text> This commit was deleted.</native:text>
            @if ($commit->isSeriesPart())
                @include('native.partials.series-position')
            @endif
        </native:row>
        <native:column class="w-full h-[1] border-b border-theme-hairline" />
    </native:column>

    @include('native.partials.rail', ['previous' => $previousLane, 'lane' => \App\Devnull\Lane::Rail, 'tail' => $withTail ? \App\Devnull\Lane::Rail : null, 'node' => 'tombstone'])
</native:row>
