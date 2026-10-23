{{--
    The refresh a re-tap of the Log tab or a pull started, still out: a quiet
    chip in the pager's overlay that says the log is being fetched again, to
    the eye and to a screen reader, until the answer lands or fails (the
    refreshes that owe this are counted in RefreshesInBackground; the quiet
    ones the poll and a resume make never show here).

    Its slot is always in the overlay, hidden until there's something to
    show, so the pager and its lists keep their shape when it comes and goes
    (as the new commits pill's slot does; a node added beside a scrolled list
    sometimes left the list blank on iOS). It sits after the pill, which
    pins under the tabs: the pill stays where it is while the chip comes and
    goes beneath it.
--}}
@php($shown ??= false)
<native:row ref="refreshing-slot" class="w-full pt-3 justify-center {{ $shown ? '' : 'hidden' }}">
    @if ($shown)
        <native:row ref="refreshing" class="px-4 py-[6] gap-[6] items-center rounded-full bg-theme-surface shadow-md" a11y-label="Refreshing the log">
            <native:activity-indicator size="small" />
            <native:text font="medium" class="text-sm text-theme-ink-muted">Refreshing…</native:text>
        </native:row>
    @endif
</native:row>
