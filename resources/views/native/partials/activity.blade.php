{{--
    Commit activity under a profile, as the web draws it: a strip of the owner's lane, stronger where they were busier.
    Every scale is a strip here; the year sums each week into one cell rather than scrolling a 53-week grid sideways.
--}}
@php($cells = $activity->cells())
@php($gap = $activity->scale === 'year' ? 2 : 3)
@php($summary = $activity->isEmpty() ? 'No commits yet' : \App\Devnull\Display::count($activity->total(), 'commit', 'commits').' '.$activity->window())
<native:column ref="activity" class="w-full px-4 py-5 gap-3 bg-theme-surface">
    <native:row class="w-full gap-4 items-center justify-between">
        <native:text font="semibold" class="text-sm text-theme-ink">Commit activity</native:text>
        @if ($activity->isEmpty())
            <native:text class="text-sm text-theme-ink-muted">No commits yet</native:text>
        @else
            <native:text class="text-sm text-theme-ink-muted"><native:text font="mono" class="text-theme-ink">{{ $activity->total() }}</native:text> {{ $activity->total() === 1 ? 'commit' : 'commits' }} {{ $activity->window() }}</native:text>
        @endif
    </native:row>

    <native:column class="w-full gap-[6]" a11y-label="{{ $summary }}">
        <native:row ref="activity-strip" class="w-full h-[40] gap-[{{ $gap }}]">
            @foreach ($cells as $cell)
                <native:column class="flex-1 h-full rounded-[2] {{ $cell->level === 0 ? 'bg-theme-wash' : 'bg-theme-surface' }}">
                    @if ($cell->level > 0)
                        <native:column class="w-full h-full rounded-[2] bg-theme-{{ \App\Devnull\Lane::token($lane) }} opacity-[{{ $cell->opacity() }}]" />
                    @endif
                </native:column>
            @endforeach
        </native:row>

        <native:row class="w-full gap-[{{ $gap }}]">
            @foreach ($activity->labels() as $group)
                <native:column :flexGrow="$group['span']" :flexBasis="0">
                    @if ($group['label'])
                        <native:text :font="$activity->scale === 'day' ? 'mono' : null" class="text-[11px] text-theme-ink-muted" :max-lines="1">{{ $group['label'] }}</native:text>
                    @endif
                </native:column>
            @endforeach
        </native:row>
    </native:column>

    <native:row class="w-full gap-1 items-center justify-end">
        <native:text class="mr-1 text-xs text-theme-ink-muted">Less</native:text>
        @foreach ([0, 1, 2, 3, 4] as $level)
            @php($swatch = new \App\Devnull\Data\ActivityCell(0, $level))
            <native:column :width="10" :height="10" class="rounded-[2] {{ $level === 0 ? 'bg-theme-wash' : 'bg-theme-surface' }}">
                @if ($level > 0)
                    <native:column class="w-full h-full rounded-[2] bg-theme-{{ \App\Devnull\Lane::token($lane) }} opacity-[{{ $swatch->opacity() }}]" />
                @endif
            </native:column>
        @endforeach
        <native:text class="ml-1 text-xs text-theme-ink-muted">More</native:text>
    </native:row>
</native:column>
<native:column class="w-full h-[1] bg-theme-hairline" />
