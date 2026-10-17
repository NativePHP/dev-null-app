{{-- DESIGN.md's page tabs: a full-width split, the active label in ink over a 3px underline in your colour. --}}
<native:column class="w-full bg-theme-surface">
    <native:row class="w-full">
        @foreach ($tabs as $tab)
            <native:pressable ref="tab-{{ $tab['key'] }}" @press="{{ $tab['press'] }}" class="flex-1 items-center pt-[14]" a11y-label="{{ $tab['label'] }}{{ $tab['active'] ? ', selected' : '' }}">
                <native:column class="items-center gap-[11]">
                    <native:text font="semibold" class="text-[15px] {{ $tab['active'] ? 'text-theme-ink' : 'text-theme-ink-muted' }}">{{ $tab['label'] }}</native:text>
                    <native:column class="w-full h-[3] rounded-full {{ $tab['active'] ? 'bg-theme-'.$myColour : 'bg-transparent' }}" />
                </native:column>
            </native:pressable>
        @endforeach
    </native:row>
    <native:column class="w-full h-[1] bg-theme-hairline" />
</native:column>
