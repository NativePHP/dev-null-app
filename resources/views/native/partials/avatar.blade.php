{{--
    A person as a circle: their GitHub avatar on the wash, kept on the device for a day (see ImageCache), or their
    initial while it loads. An organization is a rounded square edged with a hairline instead, as the web draws it, so
    it never passes for a person. The image sits inside the hairline, as the web's does.
--}}
@php($size ??= 40)
@php($organization = $user->isOrganization)
@php($imageSize = $organization ? $size - 2 : $size)
<native:stack :width="$size" :height="$size" class="{{ $organization ? 'rounded-md border border-theme-hairline' : 'rounded-full' }} bg-theme-wash" center>
    <native:text font="semibold" class="text-theme-ink-muted" :font-size="$size * 0.4">{{ mb_strtoupper(mb_substr($user->name, 0, 1)) }}</native:text>
    @if ($user->avatarUrl)
        <native:image :src="\App\Devnull\ImageCache::src($user->avatarUrl)" :width="$imageSize" :height="$imageSize" :fit="2" class="{{ $organization ? 'rounded-[5]' : 'rounded-full' }}" />
    @endif
</native:stack>
