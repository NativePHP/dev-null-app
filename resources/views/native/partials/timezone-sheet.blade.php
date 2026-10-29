{{--
    The web's timezone picker, as a sheet: a field to find one by name or city, then the timezones by how far they are
    from UTC now, with that offset in mono and the city under each. Before anything is typed it lists the ones at the
    phone's offset, and after, at most 50 matches, saying when there are more: all of them at once would be a frame
    bigger than EDGE takes. The one picked has a check. Tapping one picks it and the sheet goes; the Account screen
    saves it. No press-* feedback on the rows: it would keep the list from scrolling.
--}}
<native:bottom-sheet ref="timezone-sheet" :visible="$pickingTimezone" detents="large" @dismiss="timezoneSheetClosed">
    <native:column class="w-full flex-1">
        <native:column class="w-full px-5 pt-6 pb-3 gap-3">
            <native:text font="semibold" class="text-lg text-theme-ink">Timezone</native:text>
            <native:outlined-text-input ref="timezone-search" class="w-full" label="Find a timezone" native:model.debounce.250ms="timezoneQuery" a11y-label="Find a timezone" />
        </native:column>
        <native:scroll-view ref="timezone-list" class="w-full flex-1">
            <native:column class="w-full pb-10">
                @forelse ($timezones['zones'] as $zone)
                    @if (! $loop->first)
                        <native:column class="w-full h-[1] bg-theme-hairline" />
                    @endif
                    <native:pressable ref="timezone-{{ $zone['name'] }}" @press="chooseTimezone('{{ $zone['name'] }}')" a11y-label="{{ $zone['offset'] }} {{ $zone['name'] }}{{ $zone['name'] === $chosenTimezone ? ', selected' : '' }}">
                        <native:row class="w-full px-5 py-3 gap-3 items-center">
                            <native:column :width="16">
                                @if ($zone['name'] === $chosenTimezone)
                                    <native:icon :ios="\App\Icons\Lucide::Check->ios()" :android="\App\Icons\Lucide::Check->android()" :size="16" class="text-theme-primary" />
                                @endif
                            </native:column>
                            <native:column class="flex-1 gap-[2]">
                                <native:row class="w-full gap-2 items-center">
                                    <native:text font="mono" class="text-[13px] text-theme-ink-muted">{{ $zone['offset'] }}</native:text>
                                    <native:text class="flex-1 text-[15px] text-theme-ink" :max-lines="1">{{ $zone['name'] }}</native:text>
                                </native:row>
                                <native:text class="text-xs text-theme-ink-muted" :max-lines="1">{{ $zone['city'] }}</native:text>
                            </native:column>
                        </native:row>
                    </native:pressable>
                @empty
                    <native:text ref="timezone-none" class="w-full px-5 py-6 text-sm text-theme-ink-muted">No timezone matches "{{ trim($timezoneQuery) }}".</native:text>
                @endforelse
                @if ($timezones['more'] > 0)
                    <native:text ref="timezone-more" class="w-full px-5 py-4 text-sm text-theme-ink-muted">{{ $timezones['more'] }} more. Keep typing to find yours.</native:text>
                @elseif (trim($timezoneQuery) === '' && $timezones['zones'] !== [])
                    <native:text ref="timezone-hint" class="w-full px-5 py-4 text-sm text-theme-ink-muted">Type a city or region to find another.</native:text>
                @endif
            </native:column>
        </native:scroll-view>
    </native:column>
</native:bottom-sheet>
