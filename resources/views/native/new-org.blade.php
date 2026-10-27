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
        @include('native.partials.picked-people')
    </native:column>
    <native:column class="w-full h-[1] bg-theme-hairline" />

    <native:list ref="candidates" class="w-full flex-1 bg-theme-surface">
        @include('native.partials.people-results')
        {{-- The bottom bar sits over the end of the list, so the list clears it and the home indicator. --}}
        <native:column class="w-full h-[{{ 64 + \App\Devnull\Display::HomeIndicatorHeight }}]" />
    </native:list>

    <native:bottom-bar>
        <native:column ref="create-bar" class="w-full bg-theme-surface">
            <native:column class="w-full h-[1] bg-theme-hairline" />
            <native:row class="w-full px-4 py-3 gap-3 items-center">
                <native:text ref="create-error" font="mono" class="flex-1 text-xs text-theme-destructive" :max-lines="2">{{ $pickError ?? '' }}</native:text>
                <native:column class="w-[120]">
                    <native:button ref="create-org" :variant="$myColour" :disabled="$picked === [] || $creating" @press="create" class="w-full">Create org</native:button>
                </native:column>
            </native:row>
        </native:column>
    </native:bottom-bar>
</native:column>
