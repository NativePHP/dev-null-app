{{--
    The sheet Mute opens on a Remotes conversation or org, standing in for the web's Mute dialog, in its words: how long
    for, one button each, in its order. $mutingTitle is the handle or the org's name, and the sheet shows while
    $muting is set.
--}}
<native:bottom-sheet ref="mute-sheet" :visible="$muting !== null" detents="medium" @dismiss="muteSheetClosed">
    <native:column class="w-full px-5 pt-6 pb-8 gap-3">
        <native:text font="semibold" class="w-full text-lg text-theme-ink" :max-lines="1">Mute {{ $mutingTitle }}</native:text>
        <native:text class="w-full text-[15px] leading-relaxed text-theme-ink-muted">Silence browser notifications and sound on all your devices. Messages and unread counts stay visible.</native:text>

        <native:column class="w-full pt-2 gap-2">
            @foreach (\App\Devnull\MuteDuration::choices() as $duration => $label)
                <native:button ref="mute-{{ $duration }}" variant="secondary" size="lg" @press="muteFor('{{ $duration }}')" class="w-full">{{ $label }}</native:button>
            @endforeach
        </native:column>
    </native:column>
</native:bottom-sheet>
