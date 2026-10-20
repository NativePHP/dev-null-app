{{--
    The achievements shelf under the streaks, as the web draws it: the Unix rank and level with the XP, how many badges
    are unlocked, a bar of the way to the next rank in the owner's lane, and the badges. An unlocked badge is a hairline
    chip in ink; a locked one is muted, with its progress when it takes more than one step.

    Like the web's compact shelf, only earned badges show here. Native rows don't wrap on iOS, so two fit beside
    the count of the rest; every badge, locked or earned, is in the sheet (see achievements-sheet). Tapping an earned
    badge opens it marked. The empty shelf offers View achievements.
--}}
<native:column ref="achievements" class="w-full py-3 gap-[10] bg-theme-surface" a11y-label="Developer achievements">
    <native:pressable ref="achievements-open" @press="openAchievements" class="w-full" a11y-label="{{ $achievements->label() }}, {{ $achievements->xp }} XP, {{ $achievements->unlockedCount() }} of {{ count($achievements->badges) }} badges unlocked" a11y-hint="Shows the achievements">
        <native:column class="w-full px-4 gap-[10]">
            <native:row class="w-full gap-2 items-center">
                <native:icon :ios="\App\Icons\Lucide::Award->ios()" :android="\App\Icons\Lucide::Award->android()" :size="16" class="text-theme-ink" />
                <native:text font="mono-medium" class="text-[13px] text-theme-ink flex-shrink" :max-lines="1">{{ $achievements->label() }}</native:text>
                <native:text font="mono" class="text-[13px] text-theme-ink-muted">·</native:text>
                <native:text font="mono" class="text-[13px] text-theme-ink-muted">{{ $achievements->xp }} XP</native:text>
                <native:spacer />
                <native:row class="gap-1 items-center">
                    <native:text ref="badges-unlocked" font="mono" class="text-xs text-theme-ink-muted">{{ $achievements->unlockedCount() }} / {{ count($achievements->badges) }} unlocked</native:text>
                    <native:icon :ios="\App\Icons\Lucide::ChevronRight->ios()" :android="\App\Icons\Lucide::ChevronRight->android()" :size="14" class="text-theme-ink-muted" />
                </native:row>
            </native:row>

            <native:column class="w-full gap-1">
                <native:row class="w-full items-center">
                    <native:text ref="xp" font="mono" class="text-[11px] text-theme-ink-muted">{{ $achievements->xpLabel() }}</native:text>
                    <native:spacer />
                    <native:text ref="next-rank" font="mono" class="text-[11px] text-theme-ink-muted">{{ $achievements->nextRankLabel() }}</native:text>
                </native:row>
                @include('native.partials.progress-bar', ['ref' => 'level-progress', 'percent' => $achievements->levelProgress, 'fill' => 'bg-theme-'.\App\Devnull\Lane::token($lane), 'label' => "{$achievements->levelProgress}% of the way to the next rank"])
            </native:column>
        </native:column>
    </native:pressable>

    @php($earned = $achievements->unlockedBadges())
    <native:row ref="badges" class="w-full px-4 gap-2 items-center">
        @foreach (array_slice($earned, 0, 2) as $badge)
            @php($icon = \App\Icons\Lucide::forBadge($badge->icon))
            <native:pressable ref="badge-{{ $badge->id }}" class="flex-shrink" @press="openAchievements('{{ $badge->id }}')" a11y-label="{{ $badge->name }}, {{ $badge->statusLabel() }}. {{ $badge->description }}">
                <native:row class="px-[10] py-1 gap-[6] items-center rounded-full border border-theme-hairline bg-theme-surface">
                    <native:icon :ios="$icon->ios()" :android="$icon->android()" :size="14" class="text-theme-ink" />
                    <native:text font="mono" class="text-xs text-theme-ink flex-shrink" :max-lines="1">{{ $badge->name }}</native:text>
                </native:row>
            </native:pressable>
        @endforeach
        @if (count($earned) > 2 || $earned === [])
            <native:pressable ref="badges-more" @press="openAchievements" a11y-label="Show all achievements">
                <native:text font="mono" class="text-xs text-theme-ink-muted">{{ $earned === [] ? 'View achievements' : '+'.(count($earned) - 2).' more' }}</native:text>
            </native:pressable>
        @endif
    </native:row>
</native:column>
<native:column class="w-full h-[1] bg-theme-hairline" />
