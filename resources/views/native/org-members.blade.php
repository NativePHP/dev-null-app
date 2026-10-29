{{--
    The web's org members dialog as a screen, laid out like the Safety settings: for a maintainer, the org's name with
    Rename beside it, then everyone in a hairline box, with Add people at its top, then Leave org. The name and how full
    the org is sit in the top bar. More on a member opens what a maintainer can do to them, as the web's menu does.
--}}
<native:column class="w-full h-full bg-theme-surface">
    <native:scroll-view class="w-full flex-1 bg-theme-surface">
        <native:column class="w-full pt-4 pb-10">
            @include('native.partials.error')
            @include('native.partials.refresh-failed')

            @if ($isMaintainer)
                <native:row ref="rename" class="w-full px-4 pb-6 gap-2 items-center">
                    <native:row class="flex-1 h-[40] px-3 items-center rounded-lg border border-theme-hairline bg-theme-background">
                        <native:bare-text-input
                            ref="org-name"
                            class="w-full text-theme-ink"
                            placeholder="Name the org"
                            native:model.debounce.250ms="name"
                            @submit="rename"
                            :max-length="\App\NativeComponents\NewOrg::NameMaxLength"
                            a11y-label="Org name"
                        />
                    </native:row>
                    <native:column class="w-[96]">
                        <native:button ref="rename-org" variant="secondary" :disabled="! $canRename" @press="rename" class="w-full">Rename</native:button>
                    </native:column>
                </native:row>
            @endif

            @if ($loaded)
                <native:column ref="members" class="mx-4 rounded-lg border border-theme-hairline bg-theme-surface">
                    @if ($isMaintainer)
                        <native:pressable ref="add-people" @press="addPeople" a11y-label="Add people{{ $isFull ? ', the org is full ('.\App\NativeComponents\NewOrg::MaxMembers.')' : '' }}">
                            <native:row class="w-full px-4 py-3 gap-3 items-center {{ $isFull ? 'opacity-[0.5]' : '' }}">
                                <native:stack :width="40" :height="40" center class="rounded-full bg-theme-{{ $myColour }}/15">
                                    <native:icon :ios="\App\Icons\Lucide::UserPlus->ios()" :android="\App\Icons\Lucide::UserPlus->android()" :size="18" class="text-theme-{{ $myColour }}" />
                                </native:stack>
                                <native:column class="flex-1 gap-[2]">
                                    <native:text font="semibold" class="text-[15px] text-theme-ink">Add people</native:text>
                                    <native:text font="mono" class="text-xs text-theme-ink-muted">{{ $isFull ? 'the org is full ('.\App\NativeComponents\NewOrg::MaxMembers.')' : 'they join as contributors' }}</native:text>
                                </native:column>
                            </native:row>
                        </native:pressable>
                    @endif
                    @foreach ($rows as $row)
                        @if ($isMaintainer || ! $loop->first)
                            <native:column class="w-full h-[1] bg-theme-hairline" />
                        @endif
                        @include('native.partials.org-member-row', $row + ['showsSlot' => $isMaintainer])
                    @endforeach
                </native:column>

                <native:column class="w-full px-4 pt-8">
                    <native:button ref="leave-org" variant="destructive" size="lg" :loading="$leaving" :disabled="$leaving" @press="confirmLeave" class="w-full">Leave org</native:button>
                </native:column>
            @elseif ($error === null)
                <native:column ref="members-loading" class="mx-4 rounded-lg border border-theme-hairline bg-theme-surface">
                    @foreach (range(1, 3) as $skeleton)
                        @if (! $loop->first)
                            <native:column class="w-full h-[1] bg-theme-hairline" />
                        @endif
                        <native:row class="w-full px-4 py-3 gap-3 items-center opacity-[0.5]" animate-duration="900" :animate-loop="true" a11y-label="Loading">
                            <native:column :width="40" :height="40" class="rounded-full bg-theme-wash" />
                            <native:column class="flex-1 gap-[8]">
                                <native:column class="w-2/5 h-[12] rounded bg-theme-wash" />
                                <native:column class="w-1/4 h-[12] rounded bg-theme-wash" />
                            </native:column>
                        </native:row>
                    @endforeach
                </native:column>
            @endif
        </native:column>
    </native:scroll-view>
</native:column>
