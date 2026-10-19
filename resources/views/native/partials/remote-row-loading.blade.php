{{-- A skeleton inbox row, the height of a real one, while devnull hasn't answered yet. --}}
<native:column ref="{{ $ref }}" class="w-full bg-theme-surface" a11y-label="Loading conversations">
    <native:row class="w-full px-4 py-3 gap-3 items-center">
        <native:column :width="44" :height="44" class="rounded-full bg-theme-wash" />
        <native:column class="flex-1 gap-2">
            <native:column class="w-[140] h-[12] rounded bg-theme-wash" />
            <native:column class="w-[220] h-[10] rounded bg-theme-wash" />
        </native:column>
    </native:row>
    <native:column class="w-full pl-[72]">
        <native:column class="w-full h-[1] bg-theme-hairline" />
    </native:column>
</native:column>
