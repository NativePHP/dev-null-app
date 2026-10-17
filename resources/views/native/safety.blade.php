{{--
    Laid out like the web's Safety settings: Muted, then Blocked, each with its heading, then its people in a hairline
    box, with Unmute or Unblock beside each. An empty list is the web's dashed box. No press-* feedback on the rows:
    it would keep the screen from scrolling.
--}}
<native:scroll-view class="w-full h-full bg-theme-surface">
    <native:column class="w-full pt-6 pb-10">
        @include('native.partials.error')
        @include('native.partials.refresh-failed')

        @foreach ($groups as $group)
            @php($key = $group['key'])
            <native:column ref="{{ $key }}" class="w-full {{ $loop->first ? '' : 'pt-10' }}">
                @include('native.partials.settings-heading', ['title' => $group['title'], 'description' => $group['description']])

                @if (! $loaded[$key])
                    @if ($error === null)
                        <native:column class="mx-4 mt-4 rounded-lg border border-theme-hairline bg-theme-surface">
                            @foreach (range(1, 2) as $skeleton)
                                @if (! $loop->first)
                                    <native:column class="w-full h-[1] bg-theme-hairline" />
                                @endif
                                <native:row ref="loading-{{ $key }}-{{ $skeleton }}" class="w-full px-4 py-3 gap-3 items-center opacity-[0.5]" animate-duration="900" :animate-loop="true" a11y-label="Loading">
                                    <native:column :width="44" :height="44" class="rounded-full bg-theme-wash" />
                                    <native:column class="flex-1 gap-[8]">
                                        <native:column class="w-2/5 h-[12] rounded bg-theme-wash" />
                                        <native:column class="w-1/4 h-[12] rounded bg-theme-wash" />
                                    </native:column>
                                </native:row>
                            @endforeach
                        </native:column>
                    @endif
                @elseif ($people[$key] === [])
                    <native:column ref="{{ $key }}-empty" class="mx-4 mt-4">
                        <native:text class="w-full px-4 py-6 text-sm text-center text-theme-ink-muted">{{ $group['empty'] }}</native:text>
                        @include('native.partials.dashed-box')
                    </native:column>
                @else
                    <native:column ref="{{ $key }}-people" class="mx-4 mt-4 rounded-lg border border-theme-hairline bg-theme-surface">
                        @foreach ($people[$key] as $person)
                            @if (! $loop->first)
                                <native:column class="w-full h-[1] bg-theme-hairline" />
                            @endif
                            <native:row ref="{{ $key }}-{{ $person->username }}" class="w-full px-4 py-3 gap-3 items-center">
                                <native:pressable @press="openProfile('{{ $person->username }}')" class="flex-1" a11y-label="{{ $person->name }}'s profile">
                                    <native:row class="w-full gap-3 items-center">
                                        @include('native.partials.rail-avatar', ['user' => $person, 'lane' => $person->lane(), 'size' => 44])
                                        <native:column class="flex-1 gap-[2]">
                                            <native:text font="medium" class="text-base text-theme-ink" :max-lines="1">{{ $person->name }}</native:text>
                                            <native:text font="mono" class="text-[13px] text-theme-ink-muted" :max-lines="1">{{ $person->handle() }}</native:text>
                                        </native:column>
                                    </native:row>
                                </native:pressable>
                                <native:button ref="{{ $group['press'] }}-{{ $person->username }}" variant="secondary" size="sm" @press="{{ $group['press'] }}('{{ $person->username }}')" a11y-label="{{ $group['action'] }} {{ $person->handle() }}">{{ $group['action'] }}</native:button>
                            </native:row>
                        @endforeach
                    </native:column>

                    @if ($cursors[$key] !== null)
                        <native:column class="w-full px-4 pt-2">
                            <native:button ref="{{ $key }}-more" variant="ghost" size="sm" @press="loadMore('{{ $key }}')" class="w-full">Show more</native:button>
                        </native:column>
                    @endif
                @endif
            </native:column>
        @endforeach
    </native:column>
</native:scroll-view>
