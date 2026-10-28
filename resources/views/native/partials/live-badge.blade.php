{{--
    The web's LIVE badge for someone streaming: a dot and LIVE in white mono on red, cut out of whatever it sits on by
    a 2pt edge in the surface colour. $compact is the rail's smaller one.
--}}
@php($compact ??= false)
<native:column class="rounded-sm bg-theme-surface p-[2]">
    <native:row ref="live-badge" class="rounded-sm bg-theme-live {{ $compact ? 'px-[4]' : 'px-[6] py-[1]' }} gap-[4] items-center">
        <native:column :width="6" :height="6" class="rounded-full bg-theme-on-live" />
        <native:text font="mono-medium" class="text-theme-on-live" :font-size="$compact ? 9 : 10">LIVE</native:text>
    </native:row>
</native:column>
