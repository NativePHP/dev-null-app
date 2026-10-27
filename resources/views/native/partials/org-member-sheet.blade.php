{{--
    What a maintainer can do to someone else in the org, as the web's member menu offers it: make them a maintainer or
    a contributor, then remove them. The sheet names who it's for, and can open their profile. Make contributor is off
    for the last maintainer, since an org needs one. Remove from org asks first, in the sheet itself, since iOS drops
    an alert raised over a sheet.
--}}
<native:bottom-sheet ref="member-sheet" :visible="$managed !== null" detents="0.32" @dismiss="sheetClosed">
    @if ($managed)
        @php($user = $managed['user'])
        @php($isMaintainer = $managed['role'] === \App\NativeComponents\OrgMembers::Maintainer)
        <native:column class="w-full pt-6 pb-8">
            <native:pressable ref="sheet-profile" @press="viewProfile('{{ $user->username }}')" a11y-label="View {{ $user->handle() }}">
                <native:row class="w-full px-5 pb-4 gap-3 items-center">
                    @include('native.partials.avatar', ['user' => $user, 'size' => 44])
                    <native:column class="flex-1 gap-[2]">
                        <native:text font="semibold" class="text-[17px] text-theme-ink" :max-lines="1">{{ $user->name }}</native:text>
                        <native:text font="mono" class="text-[13px] text-theme-ink-muted" :max-lines="1">{{ $user->handle() }} · {{ $managed['role'] ?? \App\NativeComponents\OrgMembers::Contributor }}</native:text>
                    </native:column>
                    <native:icon :ios="\App\Icons\Lucide::ChevronRight->ios()" :android="\App\Icons\Lucide::ChevronRight->android()" :size="16" class="text-theme-ink-muted" />
                </native:row>
            </native:pressable>
            <native:column class="w-full h-[1] bg-theme-hairline" />

            @if ($confirmingRemoval)
                <native:column ref="remove-confirmation" class="w-full px-5 pt-4 gap-4">
                    <native:column class="w-full gap-1">
                        <native:text font="semibold" class="text-base text-theme-ink">Remove {{ $user->handle() }}?</native:text>
                        <native:text class="text-sm leading-relaxed text-theme-ink-muted">They stop seeing the org and its history. A maintainer would have to add them back.</native:text>
                    </native:column>
                    <native:row class="w-full gap-3">
                        <native:column class="flex-1">
                            <native:button ref="cancel-remove" variant="secondary" @press="cancelRemove" class="w-full">Cancel</native:button>
                        </native:column>
                        <native:column class="flex-1">
                            <native:button ref="confirm-remove" variant="destructive" @press="remove('{{ $user->username }}')" class="w-full">Remove</native:button>
                        </native:column>
                    </native:row>
                </native:column>
            @else
                @if ($isMaintainer)
                    @if ($isLastMaintainer)
                        <native:column ref="sheet-make-contributor" class="w-full px-5 py-4 gap-1 opacity-[0.5]" a11y-label="Make contributor, an org needs at least one maintainer">
                            <native:text class="text-base text-theme-ink">Make contributor</native:text>
                            <native:text class="text-xs text-theme-ink-muted">An org needs at least one maintainer.</native:text>
                        </native:column>
                    @else
                        <native:pressable ref="sheet-make-contributor" @press="makeContributor('{{ $user->username }}')" a11y-label="Make contributor">
                            <native:row class="w-full px-5 py-4 items-center">
                                <native:text class="flex-1 text-base text-theme-ink">Make contributor</native:text>
                            </native:row>
                        </native:pressable>
                    @endif
                @else
                    <native:pressable ref="sheet-make-maintainer" @press="makeMaintainer('{{ $user->username }}')" a11y-label="Make maintainer">
                        <native:row class="w-full px-5 py-4 items-center">
                            <native:text class="flex-1 text-base text-theme-ink">Make maintainer</native:text>
                        </native:row>
                    </native:pressable>
                @endif
                <native:column class="w-full h-[1] bg-theme-hairline" />

                <native:pressable ref="sheet-remove" @press="confirmRemove('{{ $user->username }}')" a11y-label="Remove from org">
                    <native:row class="w-full px-5 py-4 items-center">
                        <native:text class="flex-1 text-base text-theme-destructive">Remove from org</native:text>
                    </native:row>
                </native:pressable>
            @endif
        </native:column>
    @endif
</native:bottom-sheet>
