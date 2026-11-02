{{-- Other threads, in relevance order, on a fresh rail below this thread as on the web. --}}
<native:column native:key="related-commits" ref="related-commits" class="w-full border-t border-theme-hairline bg-theme-surface">
    <native:row ref="related-commits-heading" class="w-full min-h-[44] px-4 py-3 border-b border-theme-hairline">
        <native:text font="semibold" class="text-base text-theme-ink">Related commits</native:text>
    </native:row>
    @foreach ($relatedRows as $relatedRow)
        @include('native.partials.commit-row', [
            'commit' => $relatedRow->commit,
            'previousLane' => $relatedRow->previousLane,
            'continuesRun' => $relatedRow->continuesRun,
            'continuesNext' => null,
            'focused' => false,
            'muted' => false,
            'seriesExpanded' => false,
            'threadBelow' => false,
            'withTail' => ! $loop->last,
        ])
    @endforeach
</native:column>
