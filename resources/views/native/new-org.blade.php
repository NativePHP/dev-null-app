{{--
    The web's New org dialog as a screen: what an org is and how full this one is, a name, a field to find people,
    who you've picked as chips you tap to take back out, then who matches, picked or not. Create org rides above the
    keyboard in the bottom bar, with devnull's reason beside it when it says no.
--}}
<native:column class="w-full h-full bg-theme-surface">
    @include('native.partials.error')

    <native:column class="w-full px-4 pt-3 pb-3 gap-3 bg-theme-surface">
        <native:row class="w-full gap-3 items-center">
            <native:text class="flex-1 text-sm text-theme-ink-muted">You maintain it. Everyone you add contributes.</native:text>
            <native:text ref="org-count" font="mono" class="text-xs {{ $isFull ? 'text-theme-ink' : 'text-theme-ink-muted' }}">{{ $total }}/{{ \App\NativeComponents\NewOrg::MaxMembers }}</native:text>
        </native:row>
        <native:row class="w-full h-[40] px-3 items-center rounded-lg border border-theme-hairline bg-theme-background">
            <native:bare-text-input
                ref="org-name"
                class="w-full text-theme-ink"
                placeholder="Name it (optional)"
                native:model.debounce.250ms="name"
                :max-length="\App\NativeComponents\NewOrg::NameMaxLength"
                a11y-label="Org name"
            />
        </native:row>
        @include('native.partials.people-search', ['placeholder' => 'Find people by name or @handle', 'autofocus' => true])
        <native:row ref="picked" class="w-full h-[28] items-center">
            @if ($picked === [])
                <native:text font="mono" class="text-xs text-theme-ink-muted">nobody picked yet</native:text>
            @else
                <native:scroll-view horizontal :shows-indicators="false" class="w-full">
                    <native:row class="gap-[6] items-center">
                        @foreach ($picked as $person)
                            <native:pressable native:key="picked-{{ $person->id }}" ref="picked-{{ $person->username }}" @press="toggle('{{ $person->username }}')" a11y-label="Remove {{ $person->handle() }}">
                                <native:row class="h-[28] pl-1 pr-2 gap-[6] items-center rounded-full bg-theme-wash">
                                    @include('native.partials.avatar', ['user' => $person, 'size' => 20])
                                    <native:text font="mono" class="text-xs text-theme-ink" :max-lines="1">{{ $person->handle() }}</native:text>
                                    <native:icon :ios="\App\Icons\Lucide::X->ios()" :android="\App\Icons\Lucide::X->android()" :size="12" class="text-theme-ink-muted" />
                                </native:row>
                            </native:pressable>
                        @endforeach
                    </native:row>
                </native:scroll-view>
            @endif
        </native:row>
    </native:column>
    <native:column class="w-full h-[1] bg-theme-hairline" />

    <native:list ref="candidates" class="w-full flex-1 bg-theme-surface">
        @if ($term === '')
            <native:column ref="people-hint" class="w-full px-8 py-12 items-center bg-theme-surface">
                <native:text class="text-sm text-center text-theme-ink-muted">Type a name or handle</native:text>
            </native:column>
        @elseif ($searched === $term && $rows === [])
            <native:column ref="people-none" class="w-full px-8 py-12 items-center bg-theme-surface">
                <native:text font="mono" class="text-[13px] text-center text-theme-ink-muted" :max-lines="1">fatal: nothing matches '{{ $term }}'</native:text>
            </native:column>
        @elseif ($searched === $term)
            @foreach ($rows as $row)
                @include('native.partials.candidate-row', $row + ['press' => 'toggle'])
            @endforeach
        @endif
        {{-- The bottom bar sits over the end of the list, so the list clears it and the home indicator. --}}
        <native:column class="w-full h-[{{ 64 + \App\Devnull\Display::HomeIndicatorHeight }}]" />
    </native:list>

    <native:bottom-bar>
        <native:column ref="create-bar" class="w-full bg-theme-surface">
            <native:column class="w-full h-[1] bg-theme-hairline" />
            <native:row class="w-full px-4 py-3 gap-3 items-center">
                <native:text ref="create-error" font="mono" class="flex-1 text-xs text-theme-destructive" :max-lines="2">{{ $createError ?? '' }}</native:text>
                <native:column class="w-[120]">
                    <native:button ref="create-org" :variant="$myColour" :disabled="$picked === [] || $creating" @press="create" class="w-full">Create org</native:button>
                </native:column>
            </native:row>
        </native:column>
    </native:bottom-bar>
</native:column>
