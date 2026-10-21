{{-- A person as a circle: their GitHub avatar on the wash, kept on the device for a day (see ImageCache), or their initial while it loads. --}}
@php($size ??= 40)
<native:stack :width="$size" :height="$size" class="rounded-full bg-theme-wash" center>
    <native:text font="semibold" class="text-theme-ink-muted" :font-size="$size * 0.4">{{ mb_strtoupper(mb_substr($user->name, 0, 1)) }}</native:text>
    @if ($user->avatarUrl)
        <native:image :src="\App\Devnull\ImageCache::src($user->avatarUrl)" :width="$size" :height="$size" :fit="2" class="rounded-full" />
    @endif
</native:stack>
