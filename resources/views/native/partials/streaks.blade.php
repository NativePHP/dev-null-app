{{-- The streaks under the activity strip, in mono: muted labels, ink numbers, a lane dot before a streak still going. --}}
@if ($streaks->longest > 0)
    <native:row ref="streaks" class="w-full px-4 py-3 gap-2 items-center bg-theme-surface">
        <native:text font="mono" class="text-[13px] text-theme-ink-muted">longest streak <native:text font="mono-medium" class="text-theme-ink">{{ $streaks->longest }}</native:text> {{ $streaks->longest === 1 ? 'day' : 'days' }}</native:text>
        @if ($streaks->current > 0)
            <native:text font="mono" class="text-[13px] text-theme-ink-muted">·</native:text>
            <native:row class="gap-[6] items-center">
                <native:column :width="8" :height="8" class="rounded-full bg-theme-{{ \App\Devnull\Lane::token($lane) }}" />
                <native:text font="mono" class="text-[13px] text-theme-ink-muted">current <native:text font="mono-medium" class="text-theme-ink">{{ $streaks->current }}</native:text></native:text>
            </native:row>
        @endif
    </native:row>
    <native:column class="w-full h-[1] bg-theme-hairline" />
@endif
