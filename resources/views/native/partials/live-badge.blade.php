{{--
    The web's LIVE badge for someone streaming: a dot and LIVE in white mono on red, cut out of whatever it sits on by
    a 2pt edge in the surface colour. $size is `sm` on the rail, as the web's smaller one, or `lg` under the profile's
    avatar, where the web's 10px reads too small on a phone. The dot pulses between full and half opacity, as the
    web's animate-pulse does.
--}}
@php($size ??= 'sm')
@php($large = $size === 'lg')
<native:column class="rounded-sm bg-theme-surface p-[2]">
    <native:row ref="live-badge" class="rounded-sm bg-theme-live {{ $large ? 'px-[8] py-[2] gap-[5]' : 'px-[4] gap-[4]' }} items-center">
        <native:column ref="live-dot" :width="$large ? 7 : 6" :height="$large ? 7 : 6" class="rounded-full bg-theme-on-live opacity-[0.5]" animate-duration="1000" :animate-loop="true" />
        <native:text font="mono-medium" class="text-theme-on-live" :font-size="$large ? 12 : 9">LIVE</native:text>
    </native:row>
</native:column>
