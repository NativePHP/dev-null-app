{{--
    The web's Add people dialog as a screen, laid out as New org: how full the org would be, a field to find people,
    who you've picked as chips you tap to take back out, then who matches. Share history and Add ride above the
    keyboard in the bottom bar, with devnull's reason beside Add when it says no.
--}}
<native:column class="w-full h-full bg-theme-surface">
    @include('native.partials.error')

    <native:column class="w-full px-4 pt-3 pb-3 gap-3 bg-theme-surface">
        <native:row class="w-full gap-3 items-center">
            <native:text class="flex-1 text-sm text-theme-ink-muted">They join as contributors.</native:text>
            <native:text ref="org-count" font="mono" class="text-xs {{ $isFull ? 'text-theme-ink' : 'text-theme-ink-muted' }}">{{ $total }}/{{ \App\NativeComponents\NewOrg::MaxMembers }}</native:text>
        </native:row>
        @include('native.partials.people-search', ['placeholder' => 'Find people by name or @handle', 'autofocus' => true])
        @include('native.partials.picked-people')
    </native:column>
    <native:column class="w-full h-[1] bg-theme-hairline" />

    <native:list ref="candidates" class="w-full flex-1 bg-theme-surface">
        @include('native.partials.people-results')
        {{-- The bottom bar sits over the end of the list, so the list clears it and the home indicator. --}}
        <native:column class="w-full h-[{{ 128 + \App\Devnull\Display::HomeIndicatorHeight }}]" />
    </native:list>

    <native:bottom-bar>
        <native:column ref="add-bar" class="w-full bg-theme-surface">
            <native:column class="w-full h-[1] bg-theme-hairline" />
            <native:row ref="share-history" class="w-full px-4 pt-3 gap-6 items-center">
                <native:column class="flex-1 gap-[2]">
                    <native:text font="medium" class="text-[15px] text-theme-ink">Share history</native:text>
                    <native:text class="text-xs text-theme-ink-muted">They can read everything pushed before they joined.</native:text>
                </native:column>
                <native:toggle ref="share-history-switch" :value="$sharesHistory" @change="setSharesHistory" a11y-label="Share history" />
            </native:row>
            <native:row class="w-full px-4 py-3 gap-3 items-center">
                <native:text ref="add-error" font="mono" class="flex-1 text-xs text-theme-destructive" :max-lines="2">{{ $pickError ?? '' }}</native:text>
                <native:column class="w-[120]">
                    <native:button ref="add-people" :variant="$myColour" :disabled="$picked === [] || $adding" :loading="$adding" @press="add" class="w-full">{{ trim('Add '.($picked === [] ? '' : count($picked))) }}</native:button>
                </native:column>
            </native:row>
        </native:column>
    </native:bottom-bar>
</native:column>
