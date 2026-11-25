{{-- Where the day changes in a feed: the rail runs through without a node, beside a mono "Today", "Yesterday" or "Tue, Oct 13". --}}
<native:row native:key="day-{{ $label }}" ref="day-{{ $label }}" class="w-full bg-theme-surface">
    <native:column :width="64" />
    <native:column class="flex-1">
        <native:row class="w-full py-2 pr-4 items-center">
            <native:text font="mono" class="text-xs text-theme-ink-muted">{{ $label }}</native:text>
        </native:row>
        <native:column class="w-full h-[1] border-b border-theme-hairline" />
    </native:column>

    @include('native.partials.rail', ['previous' => $lane])
</native:row>
