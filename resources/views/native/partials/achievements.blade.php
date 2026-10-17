{{--
    The achievements shelf under the streaks, as the web draws it: the Unix rank and level with the XP, how many badges
    are unlocked, a bar of the way to the next rank in the owner's lane, and the badges. An unlocked badge is a hairline
    chip in ink; a locked one is muted, with its progress when it takes more than one step.

    The web wraps the badges onto as many lines as they need. EDGE parses flex-wrap but its iOS FlexContainer never
    wraps, so here they scroll sideways in one row. The web's locked chips are dashed, which EDGE can't draw round a
    pill, so they're a faint solid line. Tapping the rank, the count or a badge opens the web's dialog, as a sheet
    (see achievements-sheet); a badge opens it marked.

    The badges' row runs the full width of the screen and carries the shelf's 16pt inset inside it, so the first chip
    lines up with the text above, and chips scroll out to the edge of the screen rather than stopping short of it.
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
                @include('native.partials.progress-bar', ['ref' => 'level-progress', 'percent' => $achievements->levelProgress, 'fill' => "bg-theme-lane-{$lane}", 'label' => "{$achievements->levelProgress}% of the way to the next rank"])
            </native:column>
        </native:column>
    </native:pressable>

    <native:scroll-view ref="badges" horizontal :shows-indicators="false" class="w-full">
        <native:row class="px-4 gap-2">
            @foreach ($achievements->badges as $badge)
                @php($icon = \App\Icons\Lucide::forBadge($badge->icon))
                <native:pressable ref="badge-{{ $badge->id }}" @press="openAchievements('{{ $badge->id }}')" a11y-label="{{ $badge->name }}, {{ $badge->statusLabel() }}. {{ $badge->description }}">
                    <native:row class="px-[10] py-1 gap-[6] items-center rounded-full border {{ $badge->unlocked ? 'border-theme-hairline bg-theme-surface' : 'border-theme-ink-muted/30 bg-theme-wash/20' }}">
                        <native:icon :ios="$icon->ios()" :android="$icon->android()" :size="14" class="{{ $badge->unlocked ? 'text-theme-ink' : 'text-theme-ink-muted/50' }}" />
                        <native:text font="mono" class="text-xs {{ $badge->unlocked ? 'text-theme-ink' : 'text-theme-ink-muted/60' }}">{{ $badge->name }}</native:text>
                        @if ($badge->showsProgress())
                            <native:text font="mono" class="text-[10px] text-theme-ink-muted/60">({{ $badge->progress }}/{{ $badge->target }})</native:text>
                        @endif
                    </native:row>
                </native:pressable>
            @endforeach
        </native:row>
    </native:scroll-view>
</native:column>
<native:column class="w-full h-[1] bg-theme-hairline" />
