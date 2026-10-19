{{--
    The web's achievements dialog, as a sheet over the profile (see OpensAchievements): the title with the rank in the
    owner's lane, the handle, XP and how many badges are unlocked, the bar to the next rank, then two tabs. Badges
    lists every badge with its icon, name and description, a tick once it's unlocked or its progress until then, and a
    bar under a locked one that takes more than one step. Unix Ranks lists the ranks with the XP each needs, the
    current one in the lane. The XP formula closes it, as on the web.

    A badge the sheet was opened on wears the lane, as the web marks the current rank. EDGE can't scroll to a child,
    so a badge in the second half of the list opens the sheet at its end instead, where that badge is in view.

    The web's locked cards are dashed. A dashed card costs about ninety nodes here (see dashed-box), so a locked card
    is a faint wash inside a solid hairline, as the shelf's chips are. Only the tab showing is drawn, and nothing at
    all until the sheet opens, to keep the profile's frames small.
--}}
@php($achievements = $profile?->achievements)
<native:bottom-sheet ref="achievements-sheet" :visible="$achievementsOpen && $achievements !== null" detents="large" @dismiss="achievementsClosed">
    @if ($achievementsOpen && $achievements)
        @php($lane = $profile->user->lane())
        @php($markedIndex = $achievementsBadge === null ? null : $achievements->badgeIndex($achievementsBadge))
        @php($opensAtEnd = $achievementsTab === 'badges' && $markedIndex !== null && $markedIndex >= intdiv(count($achievements->badges), 2))
        <native:scroll-view ref="achievements-scroll" native:key="achievements-{{ $achievementsTab }}" scroll-anchor="{{ $opensAtEnd ? 'bottom' : 'top' }}" class="w-full flex-1">
            <native:column class="w-full px-5 pt-6 pb-10 gap-4">
                <native:column class="w-full gap-[6]">
                    <native:row class="w-full gap-2 items-center">
                        <native:text font="semibold" class="flex-1 text-base text-theme-ink" :max-lines="1">Developer Achievements</native:text>
                        <native:column ref="sheet-rank" class="px-2 py-[2] rounded-full border border-theme-{{ \App\Devnull\Lane::token($lane) }}/25 bg-theme-{{ \App\Devnull\Lane::token($lane) }}/10">
                            <native:text font="mono-medium" class="text-xs text-theme-{{ \App\Devnull\Lane::token($lane) }}">{{ $achievements->label() }}</native:text>
                        </native:column>
                    </native:row>
                    <native:text ref="sheet-summary" font="mono" class="w-full text-xs text-theme-ink-muted">{{ $profile->user->handle() }} · {{ $achievements->xp }} XP · {{ $achievements->unlockedCount() }}/{{ count($achievements->badges) }} badges unlocked</native:text>
                </native:column>

                <native:column class="w-full p-3 gap-2 rounded-lg border border-theme-hairline bg-theme-wash/30">
                    <native:row class="w-full items-center">
                        <native:text ref="sheet-xp" font="mono" class="text-xs text-theme-ink-muted">{{ $achievements->xpLabel() }}</native:text>
                        <native:spacer />
                        <native:text ref="sheet-next-rank" font="mono" class="text-xs text-theme-ink-muted">{{ $achievements->nextRankLabel() }}</native:text>
                    </native:row>
                    @include('native.partials.progress-bar', ['ref' => 'sheet-level-progress', 'percent' => $achievements->levelProgress, 'fill' => 'bg-theme-'.\App\Devnull\Lane::token($lane), 'label' => "{$achievements->levelProgress}% of the way to the next rank"])
                </native:column>

                <native:column class="w-full">
                    <native:row class="w-full">
                        @foreach ([['badges', 'Badges', count($achievements->badges)], ['ranks', 'Unix Ranks', count($achievements->ranks)]] as [$key, $title, $count])
                            <native:pressable ref="achievements-tab-{{ $key }}" @press="showAchievementsTab('{{ $key }}')" a11y-label="{{ $title }}, {{ $count }}{{ $achievementsTab === $key ? ', selected' : '' }}">
                                <native:column>
                                    <native:column class="px-3 py-[6]">
                                        <native:text font="mono-medium" class="text-xs {{ $achievementsTab === $key ? 'text-theme-ink' : 'text-theme-ink-muted' }}">{{ $title }} ({{ $count }})</native:text>
                                    </native:column>
                                    <native:column class="w-full h-[2] {{ $achievementsTab === $key ? 'bg-theme-'.\App\Devnull\Lane::token($lane) : 'bg-transparent' }}" />
                                </native:column>
                            </native:pressable>
                        @endforeach
                    </native:row>
                    <native:column class="w-full h-[1] bg-theme-hairline" />
                </native:column>

                @if ($achievementsTab === 'badges')
                    <native:column ref="sheet-badges" class="w-full gap-2">
                        @foreach ($achievements->badges as $index => $badge)
                            @php($icon = \App\Icons\Lucide::forBadge($badge->icon))
                            <native:column ref="sheet-badge-{{ $badge->id }}" class="w-full p-3 gap-[6] rounded-lg {{ $index === $markedIndex ? 'border-2 border-theme-'.\App\Devnull\Lane::token($lane) : 'border border-theme-hairline' }} {{ $badge->unlocked ? 'bg-theme-surface' : 'bg-theme-wash/20' }}" a11y-label="{{ $badge->name }}, {{ $badge->statusLabel() }}. {{ $badge->description }}">
                                <native:row class="w-full gap-2 items-center">
                                    <native:column :width="24" :height="24" class="items-center justify-center rounded border bg-theme-wash {{ $badge->unlocked ? 'border-theme-hairline' : 'border-theme-hairline/60' }}">
                                        <native:icon :ios="$icon->ios()" :android="$icon->android()" :size="14" class="{{ $badge->unlocked ? 'text-theme-ink' : 'text-theme-ink-muted/60' }}" />
                                    </native:column>
                                    <native:text font="mono-medium" class="flex-1 text-xs text-theme-ink" :max-lines="1">{{ $badge->name }}</native:text>
                                    @if ($badge->unlocked)
                                        <native:column class="px-[6] py-[2] rounded bg-emerald-500/10">
                                            <native:text font="mono-medium" class="text-[10px] text-emerald-600 dark:text-emerald-400">✓</native:text>
                                        </native:column>
                                    @else
                                        <native:text font="mono-medium" class="text-[10px] text-theme-ink-muted">{{ $badge->progress }}/{{ $badge->target }}</native:text>
                                    @endif
                                </native:row>
                                <native:text class="w-full text-xs leading-normal text-theme-ink-muted">{{ $badge->description }}</native:text>
                                @if ($badge->showsProgress())
                                    <native:column class="w-full pt-1">
                                        @include('native.partials.progress-bar', ['ref' => "sheet-badge-progress-{$badge->id}", 'percent' => $badge->progressPercent(), 'height' => 4, 'label' => "{$badge->progressPercent()}% of the way"])
                                    </native:column>
                                @endif
                            </native:column>
                        @endforeach
                    </native:column>
                @else
                    <native:column ref="sheet-ranks" class="w-full gap-2">
                        @foreach ($achievements->ranks as $rank)
                            @php($current = $rank->level === $achievements->level)
                            <native:row ref="sheet-rank-{{ $rank->level }}" class="w-full p-3 gap-3 items-center rounded-lg {{ $current ? 'border-2 border-theme-'.\App\Devnull\Lane::token($lane).' bg-theme-surface' : ($rank->achieved ? 'border border-theme-hairline bg-theme-surface' : 'border border-theme-hairline/60 bg-theme-wash/20 opacity-70') }}" a11y-label="Level {{ $rank->level }}, {{ $rank->rank }}{{ $current ? ', current' : '' }}. {{ $rank->description }}. {{ $rank->achieved ? 'Achieved' : 'Requires '.$rank->minXp.' XP' }}">
                                <native:column :width="24" :height="24" class="items-center justify-center rounded {{ $current ? 'bg-theme-'.\App\Devnull\Lane::token($lane) : 'bg-theme-wash' }}">
                                    <native:text font="mono-medium" class="text-xs {{ $current ? 'text-theme-surface' : ($rank->achieved ? 'text-theme-ink' : 'text-theme-ink-muted') }}">{{ $rank->level }}</native:text>
                                </native:column>
                                <native:column class="flex-1 gap-[2]">
                                    <native:row class="gap-2 items-center">
                                        <native:text font="mono-medium" class="text-xs text-theme-ink">{{ $rank->rank }}</native:text>
                                        @if ($current)
                                            <native:column class="px-[6] rounded bg-theme-{{ \App\Devnull\Lane::token($lane) }}/15">
                                                <native:text font="mono-medium" class="text-[10px] text-theme-{{ \App\Devnull\Lane::token($lane) }}">CURRENT</native:text>
                                            </native:column>
                                        @endif
                                    </native:row>
                                    <native:text class="w-full text-[11px] text-theme-ink-muted">{{ $rank->description }}</native:text>
                                </native:column>
                                <native:column class="items-end gap-[2]">
                                    <native:text font="mono-medium" class="text-xs text-theme-ink">{{ $rank->minXp }} XP</native:text>
                                    @if ($rank->achieved)
                                        <native:text font="mono" class="text-[10px] text-emerald-600 dark:text-emerald-400">✓ Achieved</native:text>
                                    @else
                                        <native:text font="mono" class="text-[10px] text-theme-ink-muted">Requires {{ $rank->minXp }} XP</native:text>
                                    @endif
                                </native:column>
                            </native:row>
                        @endforeach
                    </native:column>
                @endif

                <native:column class="w-full pt-2 gap-2">
                    <native:column class="w-full h-[1] bg-theme-hairline" />
                    <native:text font="mono" class="w-full text-center text-[11px] text-theme-ink-muted/80">XP: 10/commit · 15/day streak · 5/star received · 5/reply · 2/star given</native:text>
                </native:column>
            </native:column>
        </native:scroll-view>
    @endif
</native:bottom-sheet>
