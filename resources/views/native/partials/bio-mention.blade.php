{{--
    An account tagged in a bio, as the web's bio badge: its square GitHub icon on the hairline colour (a handle GitHub
    doesn't know keeps just the square), then the handle without its "@", in a rounded tag edged with a hairline on
    the wash. A tap calls $press with the handle. Scaled to $size, the size of the text around it.
--}}
@php($height = round($size * 1.5))
@php($icon = round($size * 1.15))
<native:pressable ref="{{ $ref }}" @press="{{ $press }}('{{ $handle }}')" class="flex-shrink-0 {{ $space }}" a11y-label="{{ '@'.$handle }}">
    <native:row :height="$height" class="items-center gap-[5] pl-[3] pr-[8] rounded-md border border-theme-hairline bg-theme-wash">
        <native:stack :width="$icon" :height="$icon" class="rounded-[4] bg-theme-hairline">
            <native:image :src="\App\Devnull\ImageCache::src('https://github.com/'.rawurlencode($handle).'.png?size=64')" :width="$icon" :height="$icon" :fit="2" class="rounded-[4]" />
        </native:stack>
        <native:text font="medium" :font-size="round($size * 0.93)" class="font-medium text-theme-ink" :max-lines="1">{{ $handle }}</native:text>
    </native:row>
</native:pressable>
