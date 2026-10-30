{{--
    The web's Starred by dialog, as a sheet (see OpensStargazers): the title and how many people starred the commit,
    then each of them, newest first, with their avatar, name and handle. A tap opens them. While a page loads, skeleton
    rows stand in for up to three of them. When one doesn't load, the web's line says so, with Try again, and while
    there are more, Show more. Nothing is drawn until the sheet opens.
--}}
<native:bottom-sheet ref="stargazers-sheet" :visible="$stargazersOf !== null" detents="medium,large" @dismiss="stargazersClosed">
    @if ($stargazersOf !== null)
        <native:scroll-view ref="stargazers-scroll" class="w-full flex-1">
            <native:column class="w-full px-5 pt-6 pb-10 gap-4">
                <native:column class="w-full gap-1">
                    <native:text font="semibold" class="text-base text-theme-ink">Starred by</native:text>
                    <native:text ref="stargazers-count" class="text-sm text-theme-ink-muted">{{ \App\Devnull\Display::compactCount($stargazersCount) }} {{ $stargazersCount === 1 ? 'person' : 'people' }} starred this commit.</native:text>
                </native:column>

                <native:column ref="stargazers" class="w-full">
                    @foreach ($stargazers as $stargazer)
                        <native:pressable native:key="stargazer-{{ $stargazer->id }}" ref="stargazer-{{ $stargazer->username }}" @press="openStargazerProfile('{{ $stargazer->username }}')" class="w-full flex-row py-2 gap-3 items-center" a11y-label="{{ $stargazer->name }}, {{ $stargazer->handle() }}">
                            @include('native.partials.avatar', ['user' => $stargazer, 'size' => 36])
                            <native:column class="flex-1 gap-[2]">
                                <native:text font="medium" class="w-full text-sm text-theme-ink" :max-lines="1">{{ $stargazer->name }}</native:text>
                                <native:text font="mono" class="w-full text-[13px] text-theme-ink-muted" :max-lines="1">{{ $stargazer->handle() }}</native:text>
                            </native:column>
                        </native:pressable>
                    @endforeach

                    @if ($loadingStargazers)
                        @foreach (range(1, max(1, min($stargazersCount - count($stargazers), 3))) as $skeleton)
                            <native:row ref="stargazer-loading-{{ $skeleton }}" class="w-full py-2 gap-3 items-center opacity-[0.5]" animate-duration="900" :animate-loop="true" a11y-label="Loading stars">
                                <native:column :width="36" :height="36" class="rounded-full bg-theme-wash" />
                                <native:column class="w-2/5 h-[12] rounded bg-theme-wash" />
                            </native:row>
                        @endforeach
                    @endif
                </native:column>

                @if ($stargazersFailed)
                    <native:row ref="stargazers-failed" class="w-full gap-3 items-center">
                        <native:text font="mono" class="text-[13px] text-theme-ink-muted">fatal: could not load stars</native:text>
                        <native:button ref="stargazers-retry" variant="secondary" size="sm" @press="loadStargazers">Try again</native:button>
                    </native:row>
                @elseif (! $loadingStargazers && $stargazersCursor !== null)
                    <native:pressable ref="stargazers-more" @press="loadStargazers" class="min-h-[44] justify-center" a11y-label="Show more">
                        <native:text font="medium" class="text-[13px] text-theme-primary">Show more</native:text>
                    </native:pressable>
                @endif
            </native:column>
        </native:scroll-view>
    @endif
</native:bottom-sheet>
