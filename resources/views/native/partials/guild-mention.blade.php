{{--
    A guild mentioned in a commit, as the web's guild mention: the guild's mark (see guild-mark), then its name in
    medium mono, in a rounded tag edged with a hairline on the wash, like the "posted in" pill and a bio's tags.
    A tap opens the guild. Scaled to $size, the size of the text around it. The tag is a little shorter than a line,
    with the rest above and below it, so tags on wrapped lines don't touch. Its height comes from its name plus
    padding, not a fixed number, so it grows with the system text size instead of cutting the name off.
--}}
@php($padding = round($size * 0.1))
@php($mark = round($size * 1.05))
<native:pressable ref="{{ $ref }}" @press="openGuild('{{ $guild->handle }}')" class="flex-shrink-0 py-[2] {{ $space }}" a11y-label="{{ $guild->name }}, {{ $guild->hashtag() }}">
    <native:row class="items-center gap-[5] pl-[4] pr-[7] py-[{{ $padding }}] rounded-md border border-theme-hairline bg-theme-wash">
        @include('native.partials.guild-mark', ['guild' => $guild, 'size' => $mark, 'ref' => $ref.'-mark'])
        <native:text font="mono-medium" :font-size="round($size * 0.93)" class="text-theme-ink" :max-lines="1">{{ $guild->name }}</native:text>
    </native:row>
</native:pressable>
