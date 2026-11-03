{{--
    A Nata Nu//ers high score someone shared: the game's name with its second slash hollow, like the /dev/null
    wordmark's, the score large in mono, and "Beat 1,234" to have a go. A custard wash with a crust edge, the colours of
    a pastel de nata, so it stands out in a feed of prose.

    "Signed by their device" shows only when the run's signature holds for the commit's author (see ScoreCard). That
    is all it claims. A score without it shows the same, minus that line: a lifted or edited run isn't worth a warning.

    The whole card opens the game. A build without the Skia plugin, or the composer's preview ($scoreAction false),
    shows the card with nothing to open.
--}}
@php($card = $commit->scoreCard)
@php($score = number_format($card->score))
@php($playable = ($scoreAction ?? true) && \App\Devnull\NataNullers\Game::available())
@php($label = \App\Devnull\NataNullers\Game::SpokenName.' high score, '.$score.($card->signed ? ', signed by their device' : ''))
@if ($playable)
    <native:pressable ref="score-card-{{ $commit->id }}" @press="playNataNullers" class="w-full mt-2" a11y-label="{{ $label }}" a11y-hint="Opens the game">
@else
    <native:column ref="score-card-{{ $commit->id }}" class="w-full mt-2" a11y-label="{{ $label }}">
@endif
    <native:column class="w-full p-3 gap-1 rounded-lg border border-theme-nata/40 bg-theme-nata-soft">
        <native:row class="w-full gap-2 items-center">
            <native:text font="semibold" class="flex-1 text-[13px] font-semibold tracking-tight text-theme-nata" :max-lines="1">Nata Nu/&#x2060;<native:text text="/" class="text-outline text-theme-nata" />&#x2060;ers</native:text>
            @if ($card->signed)
                <native:row ref="score-signed-{{ $commit->id }}" class="gap-1 items-center">
                    <native:icon :ios="\App\Icons\Lucide::BadgeCheck->ios()" :android="\App\Icons\Lucide::BadgeCheck->android()" :size="13" class="text-theme-ink-muted" />
                    <native:text class="text-xs text-theme-ink-muted" :max-lines="1">Signed by their device</native:text>
                </native:row>
            @endif
        </native:row>
        <native:row class="w-full gap-3 items-end">
            <native:column class="flex-1">
                <native:text font="mono" class="text-[32px] font-bold leading-none text-theme-ink" :max-lines="1">{{ $score }}</native:text>
                <native:text class="text-[13px] text-theme-ink-muted">high score</native:text>
            </native:column>
            @if ($playable)
                <native:row class="px-3 py-[6] gap-1 items-center rounded-full bg-theme-nata">
                    <native:icon :ios="\App\Icons\Lucide::Gamepad->ios()" :android="\App\Icons\Lucide::Gamepad->android()" :size="16" class="text-theme-on-nata" />
                    <native:text font="semibold" class="text-[13px] font-semibold text-theme-on-nata" :max-lines="1">Beat {{ $score }}</native:text>
                </native:row>
            @endif
        </native:row>
    </native:column>
@if ($playable)
    </native:pressable>
@else
    </native:column>
@endif
