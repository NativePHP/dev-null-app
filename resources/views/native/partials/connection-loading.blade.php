{{--
    While people load, a skeleton row stands in for one of them: the avatar's circle and the name, handle and bio as
    bars, the same height as a row with a line of bio. A list with nothing kept to show yet shows a few of them.
--}}
<native:column ref="{{ $ref }}" class="w-full bg-theme-surface" a11y-label="{{ $label }}">
    <native:row class="w-full px-4 py-3 gap-3 items-start opacity-[0.5]" animate-duration="900" :animate-loop="true">
        <native:column :width="48" :height="48" class="rounded-full bg-theme-wash flex-shrink-0" />
        <native:column class="flex-1 gap-[10] pt-1">
            <native:column class="w-2/5 h-[12] rounded bg-theme-wash" />
            <native:column class="w-1/4 h-[12] rounded bg-theme-wash" />
            <native:column class="w-4/5 h-[12] rounded bg-theme-wash" />
        </native:column>
    </native:row>
    <native:column class="w-full h-[1] bg-theme-hairline" />
</native:column>
