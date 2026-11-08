{{--
    Between the commits that came in since your last visit and the first one you'd already seen: a muted mono
    `HEAD@{last visit}` and a dashed hairline, drawn in short dashes since EDGE has no dashed borders.
--}}
<native:row native:key="last-visit" ref="last-visit" class="w-full bg-theme-surface" a11y-label="{{ $label ?? 'Commits above are new since your last visit' }}">
    <native:column :width="64" />
    <native:column class="flex-1">
        <native:row class="w-full py-2 pr-4 gap-3 items-center">
            <native:text font="mono" class="text-xs text-theme-ink-muted">HEAD@{last visit}</native:text>
            <native:row class="flex-1 h-[1] gap-[3]">
                @foreach (range(1, 24) as $dash)
                    <native:column class="flex-1 h-[1] border-b border-theme-hairline" />
                @endforeach
            </native:row>
        </native:row>
        <native:column class="w-full h-[1] border-b border-theme-hairline" />
    </native:column>

    @include('native.partials.rail', ['previous' => $lane])
</native:row>
