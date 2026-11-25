{{--
    The kinds the Notifications tab shows, laid out like the app's other settings: a heading with a line on what it's
    for, then a row per kind with its name, what it covers, and a real switch. Each switch saves when it flips.
--}}
<native:column class="w-full h-full bg-theme-surface">
    <native:scroll-view class="w-full flex-1 bg-theme-surface">
        <native:column class="w-full pt-6 pb-10">
            <native:column ref="notification-kinds" class="w-full">
                @include('native.partials.settings-heading', [
                    'title' => 'Show in Notifications',
                    'description' => 'Choose which kinds of notification the list shows. Kept on this phone, as the web keeps its filters in your browser.',
                ])

                <native:column class="w-full pt-2">
                    @foreach ($options as $kind => [$label, $description])
                        <native:row ref="kind-{{ $kind }}" class="w-full px-4 pt-4 gap-6 items-center">
                            <native:column class="flex-1 gap-[2]">
                                <native:text font="medium" class="text-[15px] text-theme-ink">{{ $label }}</native:text>
                                <native:text class="text-sm text-theme-ink-muted">{{ $description }}</native:text>
                            </native:column>
                            <native:toggle ref="kind-{{ $kind }}-switch" :value="in_array($kind, $kinds, true)" @change="setKind('{{ $kind }}')" :a11y-label="$label" />
                        </native:row>
                    @endforeach
                </native:column>
            </native:column>
        </native:column>
    </native:scroll-view>
</native:column>
