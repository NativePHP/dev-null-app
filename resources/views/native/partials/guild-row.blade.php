{{--
    A guild in the directory, as the web's card shows it: its uploaded icon, or its emoji (see guild-mark).

    Tapped, it opens the guild's screen (see OpensGuilds).
--}}
@php($members = \App\Devnull\Display::count($guild->memberCount, 'member', 'members'))
<native:pressable native:key="guild-{{ $guild->handle }}" ref="guild-{{ $guild->handle }}" @press="openGuild('{{ $guild->handle }}')" class="w-full flex-row px-4 py-3 gap-3 items-start border-b border-theme-hairline bg-theme-surface" a11y-label="{{ $guild->name }}, {{ $guild->hashtag() }}, {{ $members }}">
    <native:column class="w-[40] h-[40] rounded-md bg-theme-wash items-center justify-center">
        @include('native.partials.guild-mark', ['guild' => $guild, 'size' => 40, 'ref' => 'guild-mark-'.$guild->handle])
    </native:column>
    <native:column class="flex-1 gap-1">
        <native:text font="semibold" class="w-full text-[15px] text-theme-ink" :max-lines="1">{{ $guild->name }}</native:text>
        <native:text font="mono" class="w-full text-[13px] text-theme-ink-muted" :max-lines="1">{{ $guild->hashtag() }}</native:text>
        @if ($guild->description !== '')
            <native:text class="w-full text-sm text-theme-ink" :max-lines="2">{{ $guild->description }}</native:text>
        @endif
        <native:text class="text-[13px] text-theme-ink-muted">{{ $members }}</native:text>
    </native:column>
</native:pressable>
