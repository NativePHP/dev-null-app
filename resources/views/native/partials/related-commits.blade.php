{{--
    Other threads, in relevance order, on a fresh rail below this thread as on the web. They start closed under a
    header with their count, which opens them in place and closes them again, its chevron turning as they do (see
    CommitDetail::toggleRelated()). Each commit fades and slides into place a little after the one above it, and fades
    out together as they close. The header is the whole row, so it's an easy target at the foot of the page.
--}}
@php($relatedOpen = $relatedShown && ! $relatedClosing)
@php($relatedLabel = \App\Devnull\Display::count(count($relatedRows), 'related commit', 'related commits'))
<native:column native:key="related-commits" ref="related-commits" class="w-full border-t border-theme-hairline bg-theme-surface">
    <native:pressable ref="related-commits-toggle" @press="toggleRelated" class="w-full" a11y-label="{{ $relatedLabel }}" a11y-hint="{{ $relatedOpen ? 'Hides them' : 'Shows them' }}">
        <native:row ref="related-commits-heading" class="w-full min-h-[44] px-4 py-3 gap-2 items-center border-b border-theme-hairline">
            <native:text font="semibold" class="text-base text-theme-ink">Related commits</native:text>
            <native:text ref="related-commits-count" font="mono" class="text-sm text-theme-ink-muted">{{ count($relatedRows) }}</native:text>
            <native:spacer />
            <native:icon ref="related-commits-chevron" :ios="\App\Icons\Lucide::ChevronRight->ios()" :android="\App\Icons\Lucide::ChevronRight->android()" :size="18" class="text-theme-ink-muted" :rotate="$relatedOpen ? 90 : 0" :animate-duration="\App\NativeComponents\CommitDetail::RelatedFade" />
        </native:row>
    </native:pressable>
    @if ($relatedShown)
        @foreach ($relatedRows as $relatedRow)
            <native:column native:key="related-{{ $relatedRow->commit->id }}" ref="related-{{ $relatedRow->commit->id }}" class="w-full {{ $relatedFaded ? 'opacity-0' : '' }}" :translate-y="$relatedFaded ? -8 : 0" :animate-duration="\App\NativeComponents\CommitDetail::RelatedFade" :animate-delay="$relatedClosing ? 0 : $loop->index * \App\NativeComponents\CommitDetail::RelatedStagger">
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
            </native:column>
        @endforeach
    @endif
</native:column>
